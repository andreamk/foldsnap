<?php

/**
 * Bottom-up chunked recalculate of folder counters
 *
 * Safety net + first-boot initialisation for the incremental counter
 * system. Builds a persistent LIFO stack of folder IDs in BFS top-down
 * order: popping it yields a leaves-first post-order walk, so by the time
 * we update a folder its children's totals are already fresh.
 *
 * State for in-progress recalculations is stored in a single option
 * (`foldsnap_opt_recalc_stack`). When the stack drains, the global Root
 * counters are written and the option is cleared.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Services;

use FoldSnap\Models\FolderModel;
use FoldSnap\Utils\Sanitize;

class CountersRecalculator
{
    /** @var string Option key holding the pending LIFO stack of folder IDs */
    public const OPT_STACK = 'foldsnap_opt_recalc_stack';

    /** @var string Option key set once the first-boot recalculate has completed */
    public const OPT_INITIALIZED = 'foldsnap_opt_counters_initialized';

    /** @var string Cron action name used by the scheduler wrapper */
    public const CRON_ACTION = 'foldsnap_recalc_chunk';

    /** @var int Default chunk size (folders processed per call) */
    public const DEFAULT_LIMIT = 100;

    private FolderCounterService $counters;

    /**
     * @param FolderCounterService $counters Root counter writer (handles cache invalidation).
     */
    public function __construct(FolderCounterService $counters)
    {
        $this->counters = $counters;
    }

    /**
     * Process the next chunk of the recalculate stack
     *
     * If the stack option is missing, builds it via BFS first. Then pops
     * up to $limit folder IDs (leaves first), recomputes total_size and
     * total_count for each as `direct + Σ total(children)`, persists the
     * shorter stack. When empty, recomputes the Root global counters and
     * marks the first-boot initialisation complete.
     *
     * @param int $limit Max folders to process this call (clamped 1..1000).
     *
     * @return array{processed:int,remaining:int,done:bool}
     */
    public function processChunk(int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min(1000, $limit));

        $stack = $this->loadStack();

        if (empty($stack)) {
            $this->finalizeRoot();
            delete_option(self::OPT_STACK);
            update_option(self::OPT_INITIALIZED, '1');

            return [
                'processed' => 0,
                'remaining' => 0,
                'done'      => true,
            ];
        }

        $batch = [];
        for ($i = 0; $i < $limit && ! empty($stack); $i++) {
            $batch[] = (int) array_pop($stack);
        }

        if (empty($batch)) {
            update_option(self::OPT_STACK, $stack, false);
            return [
                'processed' => 0,
                'remaining' => count($stack),
                'done'      => false,
            ];
        }

        // Direct counts/sizes can be read once for the whole batch — they
        // don't depend on previous iterations.
        $directSizes  = Database::getDirectSizesForFolders($batch, TaxonomyService::TAXONOMY_NAME);
        $directCounts = Database::getDirectCountsForFolders($batch, TaxonomyService::TAXONOMY_NAME);

        // Children totals MUST be read per-folder right before its update:
        // a chunk can contain a parent and one of its descendants, and the
        // descendant must be written first (LIFO order takes care of that)
        // so the parent reads fresh values.
        foreach ($batch as $folderId) {
            $childrenTotals = Database::getChildrenTotalsForFolders([$folderId], TaxonomyService::TAXONOMY_NAME);

            $totalSize  = ($directSizes[$folderId] ?? 0) + ($childrenTotals[$folderId]['size'] ?? 0);
            $totalCount = ($directCounts[$folderId] ?? 0) + ($childrenTotals[$folderId]['count'] ?? 0);

            update_term_meta($folderId, FolderModel::META_SIZE, (string) $totalSize);
            update_term_meta($folderId, FolderModel::META_COUNT, (string) $totalCount);
        }

        $remaining = count($stack);
        if ($remaining > 0) {
            update_option(self::OPT_STACK, $stack, false);
        } else {
            // Drain pass: the next call will compute Root and finalize.
            update_option(self::OPT_STACK, [], false);
        }

        return [
            'processed' => count($batch),
            'remaining' => $remaining,
            'done'      => false,
        ];
    }

    /**
     * Reset and rebuild the stack (forces a fresh recalculate)
     *
     * Used by the manual REST endpoint to recover from a corrupted state.
     *
     * @return void
     */
    public function reset(): void
    {
        delete_option(self::OPT_STACK);
        delete_option(self::OPT_INITIALIZED);
    }

    /**
     * Whether the first-boot initialisation has already completed at least once
     *
     * @return bool
     */
    public function isInitialized(): bool
    {
        $value = get_option(self::OPT_INITIALIZED, '');
        return '1' === $value;
    }

    /**
     * Read the pending stack, building it on first run
     *
     * @return int[] Stack of folder IDs (top of stack = end of array).
     */
    private function loadStack(): array
    {
        $raw = get_option(self::OPT_STACK, null);

        if (is_array($raw)) {
            $ints = [];
            foreach ($raw as $val) {
                $id = Sanitize::toInt($val);
                if ($id > 0) {
                    $ints[] = $id;
                }
            }
            return $ints;
        }

        $stack = $this->buildStack();

        // Initialize meta keys for every folder we just discovered so the
        // first bulk UPDATE that the live system fires has rows to update.
        Database::ensureFolderCountersInitialized($stack, TaxonomyService::TAXONOMY_NAME);

        update_option(self::OPT_STACK, $stack, false);

        return $stack;
    }

    /**
     * Build the stack via BFS top-down from parent=0
     *
     * Produces an array ordered by depth (top-level first, leaves last).
     * Used as a LIFO stack: array_pop yields leaves first, which is the
     * post-order traversal required for bottom-up aggregation.
     *
     * @return int[]
     */
    private function buildStack(): array
    {
        $stack        = [];
        $currentLevel = [0];

        for ($depth = 0; $depth < 64; $depth++) {
            $children = Database::getChildTermIdsForParents($currentLevel, TaxonomyService::TAXONOMY_NAME);

            if (empty($children)) {
                break;
            }

            foreach ($children as $tid) {
                $stack[] = $tid;
            }

            $currentLevel = $children;
        }

        return $stack;
    }

    /**
     * Compute and persist the global Root counters
     *
     * Root = direct_unassigned + Σ total(top-level folders). The site-wide
     * sums already include every attachment (assigned or not), so they ARE
     * the Root totals — no per-top-level read needed.
     *
     * @return void
     */
    private function finalizeRoot(): void
    {
        $totalSize  = Database::getGlobalTotalSize(TaxonomyService::POST_TYPE);
        $totalCount = Database::getGlobalMediaCount(TaxonomyService::POST_TYPE);

        $this->counters->setRoot($totalSize, $totalCount);
    }
}

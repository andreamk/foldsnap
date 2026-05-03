<?php

/**
 * Bottom-up chunked recalculate of folder counters
 *
 * Safety net + initial migration for the incremental counter system. Builds
 * a persistent LIFO stack of folder IDs in BFS top-down order: popping it
 * yields a leaves-first post-order walk, so by the time we update a folder
 * its children's totals are already fresh.
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

class CountersRecalculator
{
    /** @var string Option key holding the pending LIFO stack of folder IDs */
    public const OPT_STACK = 'foldsnap_opt_recalc_stack';

    /** @var string Option key set once the migration has completed */
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
     * marks the migration complete.
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
            update_option(self::OPT_STACK, $stack);
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
            update_option(self::OPT_STACK, $stack);
        } else {
            // Drain pass: the next call will compute Root and finalize.
            update_option(self::OPT_STACK, []);
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
     * Whether the migration has already completed at least once
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
                if (is_numeric($val)) {
                    $id = (int) $val;
                    if ($id > 0) {
                        $ints[] = $id;
                    }
                }
            }
            return $ints;
        }

        $stack = $this->buildStack();

        // Initialize meta keys for every folder we just discovered so the
        // first bulk UPDATE that the live system fires has rows to update.
        Database::ensureFolderCountersInitialized($stack, TaxonomyService::TAXONOMY_NAME);

        update_option(self::OPT_STACK, $stack);

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
        /** @var \wpdb $wpdb */
        global $wpdb;

        $stack         = [];
        $currentLevel  = [0];
        $maxIterations = 64;

        while (! empty($currentLevel) && $maxIterations-- > 0) {
            $placeholders = implode(',', array_fill(0, count($currentLevel), '%d'));

            // phpcs:disable WordPress.DB
            $rows = $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT term_id
                    FROM %i
                    WHERE taxonomy = %s
                    AND parent IN (' . $placeholders . ')
                    ORDER BY term_id ASC',
                    array_merge([$wpdb->term_taxonomy, TaxonomyService::TAXONOMY_NAME], $currentLevel)
                )
            );
            // phpcs:enable WordPress.DB

            if (! is_array($rows) || empty($rows)) {
                break;
            }

            $nextLevel = [];
            foreach ($rows as $val) {
                if (! is_numeric($val)) {
                    continue;
                }
                $tid = (int) $val;
                if ($tid <= 0) {
                    continue;
                }
                $stack[]     = $tid;
                $nextLevel[] = $tid;
            }

            $currentLevel = $nextLevel;
        }

        return $stack;
    }

    /**
     * Compute and persist the global Root counters
     *
     * Root = direct_unassigned + Σ total(top-level folders). Read from term
     * meta of top-level folders (already stable after the bottom-up pass).
     *
     * @return void
     */
    private function finalizeRoot(): void
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        // phpcs:disable WordPress.DB
        $topIds = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT term_id FROM %i WHERE taxonomy = %s AND parent = 0',
                $wpdb->term_taxonomy,
                TaxonomyService::TAXONOMY_NAME
            )
        );
        // phpcs:enable WordPress.DB

        $totalSize  = Database::getGlobalTotalSize(TaxonomyService::POST_TYPE);
        $totalCount = Database::getGlobalMediaCount(TaxonomyService::POST_TYPE);

        // Sanity: above queries already include every attachment (assigned or
        // not), so totalSize/Count ARE the global figures. Top-level term meta
        // not needed for Root math — left as a hook for future audit logs.
        unset($topIds);

        $this->counters->setRoot($totalSize, $totalCount);
    }
}

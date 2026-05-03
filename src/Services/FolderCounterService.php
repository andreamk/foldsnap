<?php

/**
 * Folder counter math and Root global counters.
 *
 * Owns:
 * - The two `wp_options` rows backing the virtual Root totals.
 * - The wp_cache entries that memoize Root *unassigned* counters.
 * - Bulk `(size, count)` delta application across an ancestor chain.
 *
 * Extracted from FolderRepository so any code that adjusts counters
 * (repository mutations, attachment lifecycle, recalculate finalisation)
 * goes through the same write path and benefits from cache invalidation.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Services;

use FoldSnap\Models\FolderModel;

class FolderCounterService
{
    /** @var string Cache key for root (unassigned) total size */
    private const CACHE_ROOT_TOTAL_SIZE = 'root_total_size';

    /** @var string Cache key for root (unassigned) media count */
    private const CACHE_ROOT_MEDIA_COUNT = 'root_media_count';

    /** @var string Cache group for all FoldSnap cache entries */
    private const CACHE_GROUP = 'foldsnap';

    /** @var string Option holding the global Root total size (bytes) */
    public const OPT_ROOT_SIZE = 'foldsnap_opt_root_size';

    /** @var string Option holding the global Root media count */
    public const OPT_ROOT_COUNT = 'foldsnap_opt_root_count';

    /**
     * Apply a `(size, count)` delta to every term meta in a chain.
     *
     * Single bulk UPDATE per non-zero component. No-op for empty chains
     * or zero deltas, so callers can pass partial deltas freely.
     *
     * @param int[] $termIds    Term IDs to adjust (typically an ancestor chain).
     * @param int   $sizeDelta  Signed bytes delta.
     * @param int   $countDelta Signed media-count delta.
     *
     * @return void
     */
    public function applyChainDelta(array $termIds, int $sizeDelta, int $countDelta): void
    {
        if (empty($termIds)) {
            return;
        }

        if (0 !== $sizeDelta) {
            Database::bulkAdjustTermMeta($termIds, FolderModel::META_SIZE, $sizeDelta);
        }
        if (0 !== $countDelta) {
            Database::bulkAdjustTermMeta($termIds, FolderModel::META_COUNT, $countDelta);
        }
    }

    /**
     * Apply signed deltas to the option-backed global Root counters.
     *
     * Also invalidates the unassigned-counter cache so subsequent reads
     * of `getUnassignedSize/Count` recompute against fresh DB state.
     *
     * @param int $sizeDelta  Signed bytes delta.
     * @param int $countDelta Signed media-count delta.
     *
     * @return void
     */
    public function adjustRoot(int $sizeDelta, int $countDelta): void
    {
        if (0 !== $sizeDelta) {
            $current = $this->getIntOption(self::OPT_ROOT_SIZE);
            update_option(self::OPT_ROOT_SIZE, max(0, $current + $sizeDelta));
        }
        if (0 !== $countDelta) {
            $current = $this->getIntOption(self::OPT_ROOT_COUNT);
            update_option(self::OPT_ROOT_COUNT, max(0, $current + $countDelta));
        }

        $this->invalidateUnassignedCache();
    }

    /**
     * Replace the option-backed global Root counters with absolute values.
     *
     * Used by the recalculator after a bottom-up rebuild. Always invalidates
     * the unassigned-counter cache because the underlying attachment set
     * may have shifted.
     *
     * @param int $totalSize  Absolute bytes (not a delta).
     * @param int $totalCount Absolute media count (not a delta).
     *
     * @return void
     */
    public function setRoot(int $totalSize, int $totalCount): void
    {
        update_option(self::OPT_ROOT_SIZE, max(0, $totalSize));
        update_option(self::OPT_ROOT_COUNT, max(0, $totalCount));
        $this->invalidateUnassignedCache();
    }

    /**
     * Invalidate the unassigned-counter cache.
     *
     * Call after any mutation that shifts media in or out of an
     * "unassigned" state (folder assign/unassign, folder delete).
     *
     * @return void
     */
    public function invalidateUnassignedCache(): void
    {
        wp_cache_delete(self::CACHE_ROOT_TOTAL_SIZE, self::CACHE_GROUP);
        wp_cache_delete(self::CACHE_ROOT_MEDIA_COUNT, self::CACHE_GROUP);
    }

    /**
     * Get the count of media items not assigned to any folder.
     *
     * @return int
     */
    public function getUnassignedCount(): int
    {
        $cached = wp_cache_get(self::CACHE_ROOT_MEDIA_COUNT, self::CACHE_GROUP);
        if (is_int($cached)) {
            return $cached;
        }

        $count = Database::countUnassignedMedia(
            TaxonomyService::TAXONOMY_NAME,
            TaxonomyService::POST_TYPE
        );

        wp_cache_set(self::CACHE_ROOT_MEDIA_COUNT, $count, self::CACHE_GROUP);

        return $count;
    }

    /**
     * Get the total size in bytes of media not assigned to any folder.
     *
     * @return int
     */
    public function getUnassignedSize(): int
    {
        $cached = wp_cache_get(self::CACHE_ROOT_TOTAL_SIZE, self::CACHE_GROUP);
        if (is_int($cached)) {
            return $cached;
        }

        $results = Database::getUnassignedAttachmentMeta(
            TaxonomyService::TAXONOMY_NAME,
            TaxonomyService::POST_TYPE
        );

        $total = 0;
        foreach ($results as $serializedMeta) {
            $meta = maybe_unserialize($serializedMeta);
            if (is_array($meta) && isset($meta['filesize']) && is_numeric($meta['filesize'])) {
                $total += (int) $meta['filesize'];
            }
        }

        wp_cache_set(self::CACHE_ROOT_TOTAL_SIZE, $total, self::CACHE_GROUP);

        return $total;
    }

    /**
     * Get the cached global Root total size (bytes).
     *
     * Recursive across the whole site. Reads directly from the option;
     * the option itself is the cache.
     *
     * @return int
     */
    public function getGlobalSize(): int
    {
        return $this->getIntOption(self::OPT_ROOT_SIZE);
    }

    /**
     * Get the cached global Root media count.
     *
     * Recursive across the whole site. Reads directly from the option;
     * the option itself is the cache.
     *
     * @return int
     */
    public function getGlobalCount(): int
    {
        return $this->getIntOption(self::OPT_ROOT_COUNT);
    }

    /**
     * Read an option as an int, with a default for missing/non-numeric values.
     *
     * @param string $optionName Option key.
     * @param int    $default    Fallback value when the option is missing or non-numeric.
     *
     * @return int
     */
    private function getIntOption(string $optionName, int $default = 0): int
    {
        $raw = get_option($optionName, $default);
        return is_numeric($raw) ? (int) $raw : $default;
    }
}

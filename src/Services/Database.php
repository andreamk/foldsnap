<?php

/**
 * Database queries for folder operations
 *
 * Encapsulates direct $wpdb queries that cannot be expressed via
 * standard WordPress API functions. All queries use $wpdb->prepare()
 * with string literals and %i for table identifiers.
 *
 * Several methods use `phpcs:disable WordPress.DB` blocks around their
 * queries. The reason is twofold:
 *
 * 1. IN-clause with N arguments. Building the placeholder string with
 *    implode/array_fill and passing the values via array_merge to
 *    $wpdb->prepare() is the canonical WordPress pattern for variable-
 *    length IN clauses, but the sniffer cannot statically prove it is
 *    safe and emits NotPrepared / UnfinishedPrepare / ReplacementsWrongNumber.
 *    All values fed into the IN are int-validated upstream.
 *
 * 2. Direct queries by design. This class exists specifically to run
 *    SQL that has no high-level WordPress API equivalent, so DirectQuery
 *    and NoCaching are not violations here — they describe the contract.
 *    Caching, when needed, is handled one layer up in FolderRepository.
 *
 * Each disable block is scoped to a single query so the rest of the file
 * remains under normal sniffing.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Services;

class Database
{
    /**
     * Get serialized attachment metadata for media not assigned to any folder
     *
     * @param string $taxonomy Taxonomy name
     * @param string $postType Post type to filter
     *
     * @return string[] Array of serialized _wp_attachment_metadata values
     */
    public static function getUnassignedAttachmentMeta(string $taxonomy, string $postType): array
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT pm.meta_value
                FROM %i p
                INNER JOIN %i pm ON p.ID = pm.post_id
                LEFT JOIN %i tr ON p.ID = tr.object_id
                LEFT JOIN %i tt
                    ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    AND tt.taxonomy = %s
                WHERE p.post_type = %s
                AND p.post_status = %s
                AND tt.term_taxonomy_id IS NULL
                AND pm.meta_key = %s',
                $wpdb->posts,
                $wpdb->postmeta,
                $wpdb->term_relationships,
                $wpdb->term_taxonomy,
                $taxonomy,
                $postType,
                'inherit',
                '_wp_attachment_metadata'
            )
        );

        if (! is_array($results)) {
            return [];
        }

        /** @var string[] $results */
        return $results;
    }

    /**
     * Find sibling term names matching a base name or its numbered variants
     *
     * Returns term names that match either the exact name or the pattern
     * "name (N)" under the specified parent in the given taxonomy.
     * Uses LIKE with esc_like for safe pattern matching.
     *
     * @param string $taxonomy  Taxonomy name
     * @param string $name      Base name to search for
     * @param int    $parentId  Parent term ID (0 for root)
     * @param int    $excludeId Term ID to exclude from results (0 for none)
     *
     * @return string[] Matching term names
     */
    public static function getSiblingNameMatches(
        string $taxonomy,
        string $name,
        int $parentId,
        int $excludeId
    ): array {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $likePattern = $wpdb->esc_like($name) . ' (%)';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT t.name
                FROM %i t
                INNER JOIN %i tt ON t.term_id = tt.term_id
                WHERE tt.taxonomy = %s
                AND tt.parent = %d
                AND t.term_id != %d
                AND (t.name = %s OR t.name LIKE %s)',
                $wpdb->terms,
                $wpdb->term_taxonomy,
                $taxonomy,
                $parentId,
                $excludeId,
                $name,
                $likePattern
            )
        );

        if (! is_array($results)) {
            return [];
        }

        /** @var string[] $results */
        return $results;
    }

    /**
     * Count every attachment of the given post type, regardless of folder
     *
     * Used to compute the Root folder's `total_media_count`. Mirrors the
     * post-status filter (`inherit`) used by the unassigned variant.
     *
     * @param string $postType Post type to filter
     *
     * @return int Total number of attachments
     */
    public static function getGlobalMediaCount(string $postType): int
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status = %s',
                $wpdb->posts,
                $postType,
                'inherit'
            )
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Sum filesize across every attachment of the given post type
     *
     * Used to compute the Root folder's `total_size`. Reads filesize from
     * `_wp_attachment_metadata` (available since WP 6.0; FoldSnap requires
     * 6.5+).
     *
     * @param string $postType Post type to filter
     *
     * @return int Total bytes
     */
    public static function getGlobalTotalSize(string $postType): int
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT pm.meta_value
                FROM %i p
                INNER JOIN %i pm ON p.ID = pm.post_id
                WHERE p.post_type = %s
                AND p.post_status = %s
                AND pm.meta_key = %s',
                $wpdb->posts,
                $wpdb->postmeta,
                $postType,
                'inherit',
                '_wp_attachment_metadata'
            )
        );

        if (! is_array($results)) {
            return 0;
        }

        $total = 0;
        foreach ($results as $serialized) {
            if (! is_string($serialized) || '' === $serialized) {
                continue;
            }
            $meta = maybe_unserialize($serialized);
            if (is_array($meta) && isset($meta['filesize']) && is_numeric($meta['filesize'])) {
                $total += (int) $meta['filesize'];
            }
        }

        return $total;
    }

    /**
     * Count media items not assigned to any folder
     *
     * Single COUNT query — does not load post IDs into memory.
     *
     * @param string $taxonomy Taxonomy name
     * @param string $postType Post type to filter
     *
     * @return int Number of unassigned media items
     */
    public static function countUnassignedMedia(string $taxonomy, string $postType): int
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*)
                FROM %i p
                LEFT JOIN %i tr ON p.ID = tr.object_id
                LEFT JOIN %i tt
                    ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    AND tt.taxonomy = %s
                WHERE p.post_type = %s
                AND p.post_status = %s
                AND tt.term_taxonomy_id IS NULL',
                $wpdb->posts,
                $wpdb->term_relationships,
                $wpdb->term_taxonomy,
                $taxonomy,
                $postType,
                'inherit'
            )
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Get descendant term IDs for each root, grouped by root
     *
     * Iterative BFS: one query per level walking down the parent->child
     * relationships. Compatible with any MySQL/MariaDB version (no CTEs,
     * no window functions). Each root's descendants list does NOT include
     * the root itself.
     *
     * @param int[]  $rootIds  Root term IDs to start the descent from
     * @param string $taxonomy Taxonomy name
     *
     * @return array<int, int[]> Map of root term ID => list of descendant term IDs
     */
    public static function getDescendantIds(array $rootIds, string $taxonomy): array
    {
        /** @var array<int, int[]> $result */
        $result = [];

        // Drop non-positive IDs: they would otherwise show up as keys in the
        // returned map. Duplicates are harmless and not deduped.
        $rootIds = array_filter(array_map('intval', $rootIds), static fn (int $id): bool => $id > 0);

        foreach ($rootIds as $rootId) {
            $result[$rootId] = [];
        }

        if (empty($rootIds)) {
            return $result;
        }

        // Compute descendants per root independently. Each root must produce
        // its own complete descendant list, even if another requested root
        // happens to be one of those descendants.
        foreach ($rootIds as $rootId) {
            $result[$rootId] = self::descendantsOfSingleRoot($rootId, $taxonomy);
        }

        return $result;
    }

    /**
     * Walk descendants of a single root via iterative BFS
     *
     * @param int    $rootId   Root term ID
     * @param string $taxonomy Taxonomy name
     *
     * @return int[] Descendant term IDs (root not included)
     */
    private static function descendantsOfSingleRoot(int $rootId, string $taxonomy): array
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        /** @var int[] $descendants */
        $descendants = [];

        /** @var array<int, true> $seen */
        $seen = [$rootId => true];

        $currentLevel = [$rootId];

        $maxIterations = 64;
        while (! empty($currentLevel) && $maxIterations-- > 0) {
            $placeholders = implode(',', array_fill(0, count($currentLevel), '%d'));

            // phpcs:disable WordPress.DB
            $sql  = $wpdb->prepare(
                'SELECT term_id, parent
                FROM %i
                WHERE taxonomy = %s
                AND parent IN (' . $placeholders . ')',
                array_merge([$wpdb->term_taxonomy, $taxonomy], $currentLevel)
            );
            $rows = $wpdb->get_results($sql);
            // phpcs:enable WordPress.DB

            if (! is_array($rows) || empty($rows)) {
                break;
            }

            $nextLevel = [];
            foreach ($rows as $row) {
                if (! is_object($row)) {
                    continue;
                }

                $childId = property_exists($row, 'term_id') && is_numeric($row->term_id) ? (int) $row->term_id : 0;

                if ($childId <= 0 || isset($seen[$childId])) {
                    continue;
                }

                $seen[$childId] = true;
                $descendants[]  = $childId;
                $nextLevel[]    = $childId;
            }

            $currentLevel = $nextLevel;
        }

        return $descendants;
    }

    /**
     * Count direct children for each parent term
     *
     * Single GROUP BY query covering all requested parents. Returns 0 for
     * parents with no children.
     *
     * @param int[]  $parentIds Parent term IDs (0 = root, ignored)
     * @param string $taxonomy  Taxonomy name
     *
     * @return array<int, int> Map of parent term ID => number of direct children
     */
    public static function getChildrenCounts(array $parentIds, string $taxonomy): array
    {
        /** @var array<int, int> $counts */
        $counts = [];

        $parentIds = array_map('intval', $parentIds);

        foreach ($parentIds as $parentId) {
            $counts[$parentId] = 0;
        }

        if (empty($parentIds)) {
            return $counts;
        }

        /** @var \wpdb $wpdb */
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($parentIds), '%d'));

        // phpcs:disable WordPress.DB
        $sql  = $wpdb->prepare(
            'SELECT parent, COUNT(*) AS child_count
            FROM %i
            WHERE taxonomy = %s
            AND parent IN (' . $placeholders . ')
            GROUP BY parent',
            array_merge([$wpdb->term_taxonomy, $taxonomy], $parentIds)
        );
        $rows = $wpdb->get_results($sql);
        // phpcs:enable WordPress.DB

        if (! is_array($rows)) {
            return $counts;
        }

        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }

            $parentId = property_exists($row, 'parent') && is_numeric($row->parent) ? (int) $row->parent : 0;
            $count    = property_exists($row, 'child_count') && is_numeric($row->child_count) ? (int) $row->child_count : 0;

            if ($parentId > 0) {
                $counts[$parentId] = $count;
            }
        }

        return $counts;
    }

    /**
     * Read direct media counts (term_taxonomy.count) for a list of folders
     *
     * Queries wp_term_taxonomy directly so callers get the freshest count
     * even when the WP_Term object cache may be stale (e.g. immediately
     * after wp_defer_term_counting(false) within the same request).
     *
     * @param int[]  $folderIds Folder term IDs
     * @param string $taxonomy  Taxonomy name
     *
     * @return array<int, int> Map of folder term ID => direct media count
     */
    public static function getDirectCountsForFolders(array $folderIds, string $taxonomy): array
    {
        /** @var array<int, int> $counts */
        $counts = [];

        $folderIds = array_map('intval', $folderIds);

        foreach ($folderIds as $folderId) {
            $counts[$folderId] = 0;
        }

        if (empty($folderIds)) {
            return $counts;
        }

        /** @var \wpdb $wpdb */
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($folderIds), '%d'));

        // phpcs:disable WordPress.DB
        $sql  = $wpdb->prepare(
            'SELECT term_id, count
            FROM %i
            WHERE taxonomy = %s
            AND term_id IN (' . $placeholders . ')',
            array_merge([$wpdb->term_taxonomy, $taxonomy], $folderIds)
        );
        $rows = $wpdb->get_results($sql);
        // phpcs:enable WordPress.DB

        if (! is_array($rows)) {
            return $counts;
        }

        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }

            $termId = property_exists($row, 'term_id') && is_numeric($row->term_id) ? (int) $row->term_id : 0;
            $count  = property_exists($row, 'count') && is_numeric($row->count) ? (int) $row->count : 0;

            if ($termId > 0) {
                $counts[$termId] = $count;
            }
        }

        return $counts;
    }

    /**
     * Read filesize for a list of attachments from `_wp_attachment_metadata`
     *
     * Used by the incremental counter logic to compute size deltas when
     * media is assigned/removed. Returns 0 for attachments whose metadata
     * is missing or has no `filesize` key.
     *
     * @param int[] $mediaIds Attachment post IDs.
     *
     * @return array<int, int> Map of attachment ID => filesize bytes
     */
    public static function getMediaFileSizes(array $mediaIds): array
    {
        /** @var array<int, int> $sizes */
        $sizes = [];

        $mediaIds = array_values(array_filter(array_map('intval', $mediaIds), static fn (int $id): bool => $id > 0));

        foreach ($mediaIds as $id) {
            $sizes[$id] = 0;
        }

        if (empty($mediaIds)) {
            return $sizes;
        }

        /** @var \wpdb $wpdb */
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($mediaIds), '%d'));

        // phpcs:disable WordPress.DB
        $sql  = $wpdb->prepare(
            'SELECT post_id, meta_value
            FROM %i
            WHERE meta_key = %s
            AND post_id IN (' . $placeholders . ')',
            array_merge([$wpdb->postmeta, '_wp_attachment_metadata'], $mediaIds)
        );
        $rows = $wpdb->get_results($sql);
        // phpcs:enable WordPress.DB

        if (! is_array($rows)) {
            return $sizes;
        }

        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }

            $postId    = property_exists($row, 'post_id') && is_numeric($row->post_id) ? (int) $row->post_id : 0;
            $metaValue = property_exists($row, 'meta_value') && is_string($row->meta_value) ? $row->meta_value : '';

            if ($postId <= 0 || '' === $metaValue) {
                continue;
            }

            $unserialized = maybe_unserialize($metaValue);
            if (is_array($unserialized) && isset($unserialized['filesize']) && is_numeric($unserialized['filesize'])) {
                $sizes[$postId] = (int) $unserialized['filesize'];
            }
        }

        return $sizes;
    }

    /**
     * Bulk increment/decrement an integer term meta across many terms
     *
     * Pre-condition: every term in $termIds has a row in wp_termmeta for
     * $metaKey (the meta is initialized at folder creation, see
     * FolderRepository::create and the recalculate migration).
     *
     * Negative deltas are clamped to zero at SQL level via GREATEST so the
     * value never goes below zero, even if a previous drift left it stale.
     *
     * @param int[]  $termIds Term IDs whose meta to adjust.
     * @param string $metaKey Term meta key (foldsnap_folder_size or _count).
     * @param int    $delta   Signed delta to add to the current value.
     *
     * @return void
     */
    public static function bulkAdjustTermMeta(array $termIds, string $metaKey, int $delta): void
    {
        $termIds = array_values(array_filter(array_map('intval', $termIds), static fn (int $id): bool => $id > 0));

        if (empty($termIds) || 0 === $delta) {
            return;
        }

        /** @var \wpdb $wpdb */
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($termIds), '%d'));

        // phpcs:disable WordPress.DB
        $sql = $wpdb->prepare(
            'UPDATE %i
            SET meta_value = GREATEST(0, CAST(meta_value AS SIGNED) + %d)
            WHERE meta_key = %s
            AND term_id IN (' . $placeholders . ')',
            array_merge([$wpdb->termmeta, $delta, $metaKey], $termIds)
        );
        if (is_string($sql)) {
            $wpdb->query($sql);
        }
        // phpcs:enable WordPress.DB

        // Bust the term-meta object cache so subsequent get_term_meta reads
        // hit the database. The 'term_meta' cache group is keyed by term_id.
        wp_cache_delete_multiple($termIds, 'term_meta');
    }

    /**
     * Read folder_count + folder_size for the direct children of each parent
     *
     * Single JOIN query that aggregates per parent: for each parent it
     * returns the sum of the children's foldsnap_folder_count and
     * foldsnap_folder_size term meta. Used by the bottom-up recalculate.
     *
     * @param int[]  $parentIds Parent term IDs.
     * @param string $taxonomy  Taxonomy name.
     *
     * @return array<int, array{count:int, size:int}>
     */
    public static function getChildrenTotalsForFolders(array $parentIds, string $taxonomy): array
    {
        /** @var array<int, array{count:int, size:int}> $result */
        $result = [];

        $parentIds = array_values(array_filter(array_map('intval', $parentIds), static fn (int $id): bool => $id > 0));

        foreach ($parentIds as $id) {
            $result[$id] = [
                'count' => 0,
                'size'  => 0,
            ];
        }

        if (empty($parentIds)) {
            return $result;
        }

        /** @var \wpdb $wpdb */
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($parentIds), '%d'));

        // phpcs:disable WordPress.DB
        $sql  = $wpdb->prepare(
            'SELECT tt.parent AS parent_id,
                COALESCE(SUM(CASE WHEN tm.meta_key = %s THEN CAST(tm.meta_value AS SIGNED) END), 0) AS total_count,
                COALESCE(SUM(CASE WHEN tm.meta_key = %s THEN CAST(tm.meta_value AS SIGNED) END), 0) AS total_size
            FROM %i tt
            LEFT JOIN %i tm
                ON tm.term_id = tt.term_id
                AND tm.meta_key IN (%s, %s)
            WHERE tt.taxonomy = %s
            AND tt.parent IN (' . $placeholders . ')
            GROUP BY tt.parent',
            array_merge(
                [
                    \FoldSnap\Models\FolderModel::META_COUNT,
                    \FoldSnap\Models\FolderModel::META_SIZE,
                    $wpdb->term_taxonomy,
                    $wpdb->termmeta,
                    \FoldSnap\Models\FolderModel::META_COUNT,
                    \FoldSnap\Models\FolderModel::META_SIZE,
                    $taxonomy,
                ],
                $parentIds
            )
        );
        $rows = $wpdb->get_results($sql);
        // phpcs:enable WordPress.DB

        if (! is_array($rows)) {
            return $result;
        }

        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }

            $parentId = property_exists($row, 'parent_id') && is_numeric($row->parent_id) ? (int) $row->parent_id : 0;
            $count    = property_exists($row, 'total_count') && is_numeric($row->total_count) ? (int) $row->total_count : 0;
            $size     = property_exists($row, 'total_size') && is_numeric($row->total_size) ? (int) $row->total_size : 0;

            if ($parentId > 0) {
                $result[$parentId] = [
                    'count' => $count,
                    'size'  => $size,
                ];
            }
        }

        return $result;
    }

    /**
     * Initialize size/count term meta to 0 for any folder missing them
     *
     * Pre-condition for `bulkAdjustTermMeta`: every term must already have
     * a row in wp_termmeta for the keys being incremented. Folders created
     * via FolderRepository::create() satisfy this from the start; this
     * helper covers existing terms during the migration to incremental
     * counters (and is idempotent — running it twice is a no-op).
     *
     * @param int[]  $termIds  Term IDs to normalize.
     * @param string $taxonomy Taxonomy name (only the supplied IDs are touched).
     *
     * @return void
     */
    public static function ensureFolderCountersInitialized(array $termIds, string $taxonomy): void
    {
        $termIds = array_values(array_filter(array_map('intval', $termIds), static fn (int $id): bool => $id > 0));

        if (empty($termIds)) {
            return;
        }

        /** @var \wpdb $wpdb */
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($termIds), '%d'));

        // phpcs:disable WordPress.DB
        $existing = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT tm.term_id, tm.meta_key
                FROM %i tm
                INNER JOIN %i tt ON tt.term_id = tm.term_id
                WHERE tt.taxonomy = %s
                AND tm.meta_key IN (%s, %s)
                AND tm.term_id IN (' . $placeholders . ')',
                array_merge(
                    [
                        $wpdb->termmeta,
                        $wpdb->term_taxonomy,
                        $taxonomy,
                        \FoldSnap\Models\FolderModel::META_SIZE,
                        \FoldSnap\Models\FolderModel::META_COUNT,
                    ],
                    $termIds
                )
            )
        );
        // phpcs:enable WordPress.DB

        /** @var array<int, array<string, true>> $present */
        $present = [];
        if (is_array($existing)) {
            foreach ($existing as $row) {
                if (! is_object($row)) {
                    continue;
                }
                $tid = property_exists($row, 'term_id') && is_numeric($row->term_id) ? (int) $row->term_id : 0;
                $key = property_exists($row, 'meta_key') && is_string($row->meta_key) ? $row->meta_key : '';
                if ($tid > 0 && '' !== $key) {
                    $present[$tid][$key] = true;
                }
            }
        }

        foreach ($termIds as $tid) {
            if (! isset($present[$tid][\FoldSnap\Models\FolderModel::META_SIZE])) {
                add_term_meta($tid, \FoldSnap\Models\FolderModel::META_SIZE, '0', true);
            }
            if (! isset($present[$tid][\FoldSnap\Models\FolderModel::META_COUNT])) {
                add_term_meta($tid, \FoldSnap\Models\FolderModel::META_COUNT, '0', true);
            }
        }
    }

    /**
     * Read direct (non-recursive) sizes per folder
     *
     * @param int[]  $folderIds Folder term IDs.
     * @param string $taxonomy  Taxonomy name.
     *
     * @return array<int, int> Map of folder term ID => bytes
     */
    public static function getDirectSizesForFolders(array $folderIds, string $taxonomy): array
    {
        /** @var array<int, int> $sizes */
        $sizes = [];

        $folderIds = array_map('intval', $folderIds);

        foreach ($folderIds as $folderId) {
            $sizes[$folderId] = 0;
        }

        if (empty($folderIds)) {
            return $sizes;
        }

        /** @var \wpdb $wpdb */
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($folderIds), '%d'));

        // phpcs:disable WordPress.DB
        $sql  = $wpdb->prepare(
            'SELECT tt.term_id AS folder_id, pm.meta_value
            FROM %i tr
            INNER JOIN %i tt
                ON tr.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN %i pm
                ON tr.object_id = pm.post_id
            WHERE tt.taxonomy = %s
            AND tt.term_id IN (' . $placeholders . ')
            AND pm.meta_key = %s',
            array_merge(
                [
                    $wpdb->term_relationships,
                    $wpdb->term_taxonomy,
                    $wpdb->postmeta,
                    $taxonomy,
                ],
                $folderIds,
                ['_wp_attachment_metadata']
            )
        );
        $rows = $wpdb->get_results($sql);
        // phpcs:enable WordPress.DB

        if (! is_array($rows)) {
            return $sizes;
        }

        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }

            $folderId  = property_exists($row, 'folder_id') && is_numeric($row->folder_id) ? (int) $row->folder_id : 0;
            $metaValue = property_exists($row, 'meta_value') && is_string($row->meta_value) ? $row->meta_value : '';

            if ($folderId <= 0 || '' === $metaValue) {
                continue;
            }

            $unserialized = maybe_unserialize($metaValue);
            if (is_array($unserialized) && isset($unserialized['filesize']) && is_numeric($unserialized['filesize'])) {
                $sizes[$folderId] = ($sizes[$folderId] ?? 0) + (int) $unserialized['filesize'];
            }
        }

        return $sizes;
    }
}

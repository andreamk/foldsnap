<?php

/**
 * Database queries for folder operations
 *
 * Encapsulates direct $wpdb queries that cannot be expressed via
 * standard WordPress API functions. All queries use $wpdb->prepare()
 * with string literals and %i for table identifiers.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Services;

class Database
{
    /**
     * Get serialized attachment metadata grouped by folder term ID
     *
     * Returns typed rows with folder_id and meta_value for all attachments
     * assigned to folders in the given taxonomy.
     *
     * @param string $taxonomy Taxonomy name
     *
     * @return array<int, array{folder_id: int, meta_value: string}> Rows with folder_id and meta_value
     */
    public static function getFolderAttachmentMeta(string $taxonomy): array
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT tt.term_id AS folder_id, pm.meta_value
                FROM %i tr
                INNER JOIN %i tt
                    ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN %i pm
                    ON tr.object_id = pm.post_id
                WHERE tt.taxonomy = %s
                AND pm.meta_key = %s',
                $wpdb->term_relationships,
                $wpdb->term_taxonomy,
                $wpdb->postmeta,
                $taxonomy,
                '_wp_attachment_metadata'
            )
        );

        if (! is_array($results)) {
            return [];
        }

        /** @var array<int, array{folder_id: int, meta_value: string}> $typed */
        $typed = [];
        foreach ($results as $row) {
            if (! is_object($row)) {
                continue;
            }

            $folderId  = property_exists($row, 'folder_id') ? $row->folder_id : 0;
            $metaValue = property_exists($row, 'meta_value') ? $row->meta_value : '';

            $typed[] = [
                'folder_id'  => is_numeric($folderId) ? (int) $folderId : 0,
                'meta_value' => is_string($metaValue) ? $metaValue : '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            ];
        }

        return $typed;
    }

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
}

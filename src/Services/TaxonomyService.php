<?php

/**
 * Taxonomy registration service
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Services;

final class TaxonomyService
{
    public const TAXONOMY_NAME = 'foldsnap_folder';
    public const POST_TYPE     = 'attachment';

    /**
     * Register the foldsnap_folder taxonomy
     *
     * @return void
     */
    public static function register(): void
    {
        if (taxonomy_exists(self::TAXONOMY_NAME)) {
            return;
        }

        register_taxonomy(
            self::TAXONOMY_NAME,
            self::POST_TYPE,
            [
                'labels'                => self::getLabels(),
                'hierarchical'          => true,
                'public'                => false,
                'show_ui'               => false,
                'show_in_rest'          => false,
                'show_admin_column'     => false,
                'rewrite'               => false,
                'update_count_callback' => '_update_generic_term_count',
            ]
        );
    }

    /**
     * Build a tax_query array to filter attachments by folder.
     *
     * @param int $folderId Folder term ID. 0 = unassigned (root), >0 = specific folder.
     *
     * @return array<int, array<string, mixed>> tax_query clauses.
     */
    public static function buildFolderTaxQuery(int $folderId): array
    {
        if (0 === $folderId) {
            return [
                [
                    'taxonomy' => self::TAXONOMY_NAME,
                    'operator' => 'NOT EXISTS',
                ],
            ];
        }

        return [
            [
                'taxonomy'         => self::TAXONOMY_NAME,
                'field'            => 'term_id',
                'terms'            => $folderId,
                'include_children' => false,
            ],
        ];
    }

    /**
     * Get taxonomy labels
     *
     * @return array<string, string>
     */
    private static function getLabels(): array
    {
        return [
            'name'          => __('Folders', 'foldsnap'),
            'singular_name' => __('Folder', 'foldsnap'),
            'add_new_item'  => __('Add New Folder', 'foldsnap'),
            'edit_item'     => __('Edit Folder', 'foldsnap'),
            'search_items'  => __('Search Folders', 'foldsnap'),
            'not_found'     => __('No folders found', 'foldsnap'),
        ];
    }
}

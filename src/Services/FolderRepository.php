<?php

/**
 * Repository for folder CRUD operations
 *
 * Abstracts all database interactions for the foldsnap_folder taxonomy.
 * Returns FolderModel instances (immutable DTOs) for all read operations,
 * and fresh instances after write operations.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Services;

use FoldSnap\Models\FolderModel;
use InvalidArgumentException;
use WP_Term;

class FolderRepository
{
    /**
     * Retrieve all folders as a flat list
     *
     * @return FolderModel[]
     */
    public function getAll(): array
    {
        $terms = get_terms(
            [
                'taxonomy'   => TaxonomyService::TAXONOMY_NAME,
                'hide_empty' => false,
            ]
        );

        if (! is_array($terms)) {
            return [];
        }

        $models = [];
        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $models[] = FolderModel::fromTerm($term);
            }
        }

        return $models;
    }

    /**
     * Retrieve all folders as a nested tree, sorted by position
     *
     * @return FolderModel[]
     */
    public function getTree(): array
    {
        return $this->buildTree($this->getAll());
    }

    /**
     * Retrieve a single folder by term ID
     *
     * @param int $termId Term ID
     *
     * @return FolderModel|null
     */
    public function getById(int $termId): ?FolderModel
    {
        $term = get_term($termId, TaxonomyService::TAXONOMY_NAME);

        if (! $term instanceof WP_Term) {
            return null;
        }

        return FolderModel::fromTerm($term);
    }

    /**
     * Create a new folder
     *
     * @param string $name     Folder name
     * @param int    $parentId Parent term ID (0 for root)
     * @param string $color    Hex color code
     * @param int    $position Sort position
     *
     * @return FolderModel
     *
     * @throws InvalidArgumentException If folder creation fails.
     */
    public function create(string $name, int $parentId = 0, string $color = '', int $position = 0): FolderModel
    {
        $args = [];
        if (0 < $parentId) {
            $args['parent'] = $parentId;
        }

        $result = wp_insert_term($name, TaxonomyService::TAXONOMY_NAME, $args);

        if (is_wp_error($result)) {
            throw new InvalidArgumentException(esc_html($result->get_error_message()));
        }

        $termId = (int) $result['term_id'];

        if ('' !== $color) {
            update_term_meta($termId, FolderModel::META_COLOR, $color);
        }

        if (0 !== $position) {
            update_term_meta($termId, FolderModel::META_POSITION, (string) $position);
        }

        return $this->getByIdOrFail($termId);
    }

    /**
     * Update an existing folder
     *
     * Uses -1 as sentinel value for parentId and position to indicate
     * "do not change". This allows callers to update only specific fields.
     *
     * @param int    $termId   Term ID to update
     * @param string $name     New name (empty string = no change)
     * @param int    $parentId New parent ID (-1 = no change)
     * @param string $color    New color (empty string = no change)
     * @param int    $position New position (-1 = no change)
     *
     * @return FolderModel
     *
     * @throws InvalidArgumentException If term does not exist or update fails.
     */
    public function update(
        int $termId,
        string $name = '',
        int $parentId = -1,
        string $color = '',
        int $position = -1
    ): FolderModel {
        $this->validateTermId($termId);

        $args = [];
        if ('' !== $name) {
            $args['name'] = $name;
        }

        if (-1 !== $parentId) {
            $args['parent'] = $parentId;
        }

        if (count($args) > 0) {
            $result = wp_update_term($termId, TaxonomyService::TAXONOMY_NAME, $args);

            if (is_wp_error($result)) {
                throw new InvalidArgumentException(esc_html($result->get_error_message()));
            }
        }

        if ('' !== $color) {
            update_term_meta($termId, FolderModel::META_COLOR, $color);
        }

        if (-1 !== $position) {
            update_term_meta($termId, FolderModel::META_POSITION, (string) $position);
        }

        return $this->getByIdOrFail($termId);
    }

    /**
     * Delete a folder
     *
     * Media items assigned to this folder will automatically lose
     * the term relationship and return to root.
     *
     * @param int $termId Term ID to delete
     *
     * @return bool True if deleted successfully
     *
     * @throws InvalidArgumentException If term does not exist.
     */
    public function delete(int $termId): bool
    {
        $this->validateTermId($termId);

        $result = wp_delete_term($termId, TaxonomyService::TAXONOMY_NAME);

        return true === $result;
    }

    /**
     * Assign media items to a folder
     *
     * Replaces any existing folder assignment for each media item.
     *
     * @param int   $folderId Folder term ID
     * @param int[] $mediaIds Array of attachment post IDs
     *
     * @return void
     *
     * @throws InvalidArgumentException If folder does not exist.
     */
    public function assignMedia(int $folderId, array $mediaIds): void
    {
        $this->validateTermId($folderId);

        foreach ($mediaIds as $mediaId) {
            wp_set_object_terms($mediaId, $folderId, TaxonomyService::TAXONOMY_NAME, false);
        }
    }

    /**
     * Remove media items from a folder
     *
     * @param int   $folderId Folder term ID
     * @param int[] $mediaIds Array of attachment post IDs
     *
     * @return void
     *
     * @throws InvalidArgumentException If folder does not exist.
     */
    public function removeMedia(int $folderId, array $mediaIds): void
    {
        $this->validateTermId($folderId);

        foreach ($mediaIds as $mediaId) {
            wp_remove_object_terms($mediaId, $folderId, TaxonomyService::TAXONOMY_NAME);
        }
    }

    /**
     * Count media items not assigned to any folder
     *
     * @return int
     */
    public function getRootMediaCount(): int
    {
        $query = new \WP_Query(
            [
                'post_type'      => TaxonomyService::POST_TYPE,
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                    [
                        'taxonomy' => TaxonomyService::TAXONOMY_NAME,
                        'operator' => 'NOT EXISTS',
                    ],
                ],
            ]
        );

        return $query->found_posts;
    }

    /**
     * Build a nested tree from a flat list of folder models
     *
     * @param FolderModel[] $folders Flat list of folders
     *
     * @return FolderModel[] Root-level folders with children attached
     */
    private function buildTree(array $folders): array
    {
        usort(
            $folders,
            static function (FolderModel $a, FolderModel $b): int {
                return $a->getPosition() <=> $b->getPosition();
            }
        );

        /** @var array<int, FolderModel> $map */
        $map = [];
        foreach ($folders as $folder) {
            $map[$folder->getId()] = $folder;
        }

        $roots = [];
        foreach ($folders as $folder) {
            $parentId = $folder->getParentId();
            if (0 === $parentId || ! isset($map[$parentId])) {
                $roots[] = $folder;
            } else {
                $map[$parentId]->addChild($folder);
            }
        }

        return $roots;
    }

    /**
     * Validate that a term ID exists in the folder taxonomy
     *
     * @param int $termId Term ID to validate
     *
     * @return void
     *
     * @throws InvalidArgumentException If term does not exist.
     */
    private function validateTermId(int $termId): void
    {
        $term = get_term($termId, TaxonomyService::TAXONOMY_NAME);

        if (! $term instanceof WP_Term) {
            throw new InvalidArgumentException(
                esc_html(sprintf('Folder with ID %d does not exist.', $termId))
            );
        }
    }

    /**
     * Get a folder by ID or throw if not found
     *
     * @param int $termId Term ID
     *
     * @return FolderModel
     *
     * @throws InvalidArgumentException If term does not exist.
     */
    private function getByIdOrFail(int $termId): FolderModel
    {
        $model = $this->getById($termId);

        if (null === $model) {
            throw new InvalidArgumentException(
                esc_html(sprintf('Folder with ID %d does not exist.', $termId))
            );
        }

        return $model;
    }
}

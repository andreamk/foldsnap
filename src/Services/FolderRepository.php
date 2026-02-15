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
     * Injects direct sizes into each folder before building the tree.
     * Recursive totals (getTotalMediaCount, getTotalSize) are computed
     * automatically by FolderModel's recursive getters after tree assembly.
     *
     * @return FolderModel[]
     */
    public function getTree(): array
    {
        $folders = $this->getAll();
        $sizeMap = $this->computeFolderSizes();

        foreach ($folders as $folder) {
            $folderId = $folder->getId();
            if (isset($sizeMap[$folderId])) {
                $folder->setDirectSize($sizeMap[$folderId]);
            }
        }

        return $this->buildTree($folders);
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
        $name = $this->sanitizeName($name);
        $name = $this->ensureUniqueName($name, $parentId);

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
            $name              = $this->sanitizeName($name);
            $effectiveParentId = -1 !== $parentId ? $parentId : $this->getByIdOrFail($termId)->getParentId();
            $name              = $this->ensureUniqueName($name, $effectiveParentId, $termId);
            $args['name']      = $name;
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
        return Database::countUnassignedMedia(
            TaxonomyService::TAXONOMY_NAME,
            TaxonomyService::POST_TYPE
        );
    }

    /**
     * Get total size in bytes of media not assigned to any folder
     *
     * @return int Total size in bytes
     */
    public function getRootTotalSize(): int
    {
        $cached = wp_cache_get('root_total_size', 'foldsnap');
        if (is_int($cached)) {
            return $cached;
        }

        $results = Database::getUnassignedAttachmentMeta(
            TaxonomyService::TAXONOMY_NAME,
            TaxonomyService::POST_TYPE
        );

        $total = $this->sumFileSizesFromMeta($results);

        wp_cache_set('root_total_size', $total, 'foldsnap');

        return $total;
    }

    /**
     * Sanitize a folder name
     *
     * Applies sanitize_text_field, removes dangerous leading characters
     * (Excel injection prevention), strips control characters, trims
     * whitespace, and enforces a 200-character limit (wp_terms.name column).
     *
     * @param string $name Raw folder name
     *
     * @return string Sanitized name
     *
     * @throws InvalidArgumentException If name is empty after sanitization.
     */
    private function sanitizeName(string $name): string
    {
        $name = sanitize_text_field($name);
        $name = ltrim($name, '=+@|');
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name);

        if (mb_strlen($name) > 200) {
            $name = mb_substr($name, 0, 200);
        }

        if ('' === $name) {
            throw new InvalidArgumentException(
                esc_html__('Folder name cannot be empty.', 'foldsnap')
            );
        }

        return $name;
    }

    /**
     * Ensure a folder name is unique among siblings
     *
     * Queries only for the exact name and "name (N)" variants via LIKE,
     * then finds the highest existing suffix and returns the next number.
     * For example, if "Photos" and "Photos (3)" exist, returns "Photos (4)".
     *
     * @param string $name      Sanitized folder name
     * @param int    $parentId  Parent term ID (0 for root)
     * @param int    $excludeId Term ID to exclude from check (for updates)
     *
     * @return string Unique name
     */
    private function ensureUniqueName(string $name, int $parentId, int $excludeId = 0): string
    {
        $matches = Database::getSiblingNameMatches(
            TaxonomyService::TAXONOMY_NAME,
            $name,
            $parentId,
            $excludeId
        );

        if (empty($matches)) {
            return $name;
        }

        $nameLower     = mb_strtolower($name);
        $exactConflict = false;

        foreach ($matches as $match) {
            if (mb_strtolower($match) === $nameLower) {
                $exactConflict = true;
                break;
            }
        }

        if (! $exactConflict) {
            return $name;
        }

        $maxSuffix = 1;
        $pattern   = '/^' . preg_quote($name, '/') . ' \((\d+)\)$/i';

        foreach ($matches as $match) {
            if (1 === preg_match($pattern, $match, $m)) {
                $maxSuffix = max($maxSuffix, (int) $m[1]);
            }
        }

        return sprintf('%s (%d)', $name, $maxSuffix + 1);
    }

    /**
     * Compute direct size in bytes for each folder
     *
     * Queries attachment metadata for all media assigned to folders,
     * extracts the filesize from serialized _wp_attachment_metadata,
     * and groups totals by folder term ID.
     *
     * @return array<int, int> Map of folder term ID => total bytes
     */
    private function computeFolderSizes(): array
    {
        $cached = wp_cache_get('folder_sizes', 'foldsnap');
        if (is_array($cached)) {
            /** @var array<int, int> $cached */
            return $cached;
        }

        $rows = Database::getFolderAttachmentMeta(TaxonomyService::TAXONOMY_NAME);

        /** @var array<int, int> $sizeMap */
        $sizeMap = [];

        foreach ($rows as $row) {
            $folderId = $row['folder_id'];
            $meta     = maybe_unserialize($row['meta_value']);
            $fileSize = 0;

            if (is_array($meta) && isset($meta['filesize']) && is_numeric($meta['filesize'])) {
                $fileSize = (int) $meta['filesize'];
            }

            if (! isset($sizeMap[$folderId])) {
                $sizeMap[$folderId] = 0;
            }

            $sizeMap[$folderId] += $fileSize;
        }

        wp_cache_set('folder_sizes', $sizeMap, 'foldsnap');

        return $sizeMap;
    }

    /**
     * Sum file sizes from an array of serialized attachment metadata values
     *
     * @param string[] $metaValues Array of serialized _wp_attachment_metadata values
     *
     * @return int Total size in bytes
     */
    private function sumFileSizesFromMeta(array $metaValues): int
    {
        $total = 0;

        foreach ($metaValues as $metaValue) {
            $meta = maybe_unserialize($metaValue);

            if (is_array($meta) && isset($meta['filesize']) && is_numeric($meta['filesize'])) {
                $total += (int) $meta['filesize'];
            }
        }

        return $total;
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

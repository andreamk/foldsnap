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

use Exception;
use FoldSnap\Models\FolderModel;
use FoldSnap\Utils\Sanitize;
use InvalidArgumentException;
use WP_Error;
use WP_Term;

class FolderRepository
{
    /** @var string Cache key for root (unassigned) total size */
    private const CACHE_ROOT_TOTAL_SIZE = 'root_total_size';

    /** @var string Cache key for root (unassigned) media count */
    private const CACHE_ROOT_MEDIA_COUNT = 'root_media_count';

    /** @var string Cache group for all FoldSnap cache entries */
    private const CACHE_GROUP = 'foldsnap';

    /** @var int Sanitize max folder length */
    private const MAX_FOLDER_LENGTH = 200;

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
     * Retrieve multiple folders by term IDs
     *
     * @param int[] $termIds Term IDs to fetch
     *
     * @return FolderModel[] Models indexed numerically (not keyed by ID)
     */
    public function getByIds(array $termIds): array
    {
        $termIds = array_map('intval', $termIds);

        if (empty($termIds)) {
            return [];
        }

        $terms = get_terms(
            [
                'taxonomy'   => TaxonomyService::TAXONOMY_NAME,
                'hide_empty' => false,
                'include'    => $termIds,
            ]
        );

        if (is_wp_error($terms) || ! is_array($terms)) {
            return [];
        }

        return $this->termsToModels($terms);
    }

    /**
     * Retrieve direct children for a single parent
     *
     * @param int $parentId Parent term ID (0 for root-level folders)
     *
     * @return FolderModel[] Children sorted by position then name
     */
    public function getByParent(int $parentId): array
    {
        $byParent = $this->getByParents([$parentId]);
        return $byParent[$parentId] ?? [];
    }

    /**
     * Retrieve direct children for several parents in a single query
     *
     * Returns a map keyed by the requested parent IDs. Parents with no
     * children get an empty list. Children are sorted by position ASC,
     * then name ASC, for stable display order.
     *
     * @param int[] $parentIds Parent term IDs (0 for root)
     *
     * @return array<int, FolderModel[]>
     */
    public function getByParents(array $parentIds): array
    {
        $parentIds = array_values(array_unique(array_map('intval', $parentIds)));
        $parentIds = array_values(array_filter($parentIds, static fn (int $id): bool => $id >= 0));

        /** @var array<int, FolderModel[]> $result */
        $result = [];
        foreach ($parentIds as $parentId) {
            $result[$parentId] = [];
        }

        if (empty($parentIds)) {
            return $result;
        }

        // WP_Term_Query's parent__in is unreliable when combined with the
        // 'parent' arg (or its absence). Issue one targeted query per parent.
        foreach ($parentIds as $parentId) {
            $terms = get_terms(
                [
                    'taxonomy'   => TaxonomyService::TAXONOMY_NAME,
                    'hide_empty' => false,
                    'parent'     => $parentId,
                ]
            );

            if (is_wp_error($terms) || ! is_array($terms)) {
                continue;
            }

            $result[$parentId] = $this->termsToModels($terms);
        }

        foreach ($result as $parentId => $children) {
            usort(
                $children,
                static function (FolderModel $a, FolderModel $b): int {
                    $cmp = $a->getPosition() <=> $b->getPosition();
                    if (0 !== $cmp) {
                        return $cmp;
                    }
                    return strcasecmp($a->getName(), $b->getName());
                }
            );
            $result[$parentId] = $children;
        }

        return $result;
    }

    /**
     * Search folders by name (paginated)
     *
     * Uses get_terms() with `name__like` for substring matching. Returns
     * a flat list of matching folders without their hierarchy — callers
     * decorate with breadcrumbs separately when needed.
     *
     * @param string $query   Search string (empty returns no results)
     * @param int    $page    1-indexed page number
     * @param int    $perPage Items per page (clamped 1..100)
     *
     * @return array{folders:FolderModel[], total:int, total_pages:int}
     */
    public function search(string $query, int $page = 1, int $perPage = 50): array
    {
        $trimmed = trim($query);

        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        if ('' === $trimmed) {
            return [
                'folders'     => [],
                'total'       => 0,
                'total_pages' => 0,
            ];
        }

        $countResult = get_terms(
            [
                'taxonomy'   => TaxonomyService::TAXONOMY_NAME,
                'hide_empty' => false,
                'name__like' => $trimmed,
                'fields'     => 'count',
            ]
        );

        $total = is_numeric($countResult) ? (int) $countResult : 0;

        $terms = get_terms(
            [
                'taxonomy'   => TaxonomyService::TAXONOMY_NAME,
                'hide_empty' => false,
                'name__like' => $trimmed,
                'orderby'    => 'name',
                'order'      => 'ASC',
                'number'     => $perPage,
                'offset'     => ($page - 1) * $perPage,
            ]
        );

        $folders = is_array($terms) ? $this->termsToModels($terms) : [];

        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;

        return [
            'folders'     => $folders,
            'total'       => $total,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Resolve the ancestor path (root → target) for a folder
     *
     * @param int $folderId Target folder term ID
     *
     * @return FolderModel[]
     */
    public function getPath(int $folderId): array
    {
        $navigator = new FolderTreeNavigator($this);
        return $navigator->resolvePath($folderId);
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
     * @throws InvalidArgumentException If name is invalid or parent does not exist.
     * @throws Exception If WordPress fails to insert the term.
     */
    public function create(string $name, int $parentId = 0, string $color = '', int $position = 0): FolderModel
    {
        $name = $this->sanitizeName($name);
        $name = $this->ensureUniqueName($name, $parentId);

        $args = [];
        if (0 < $parentId) {
            $this->getByIdOrFail($parentId);
            $args['parent'] = $parentId;
        }

        $result = wp_insert_term($name, TaxonomyService::TAXONOMY_NAME, $args);

        if (is_wp_error($result)) {
            throw new Exception(esc_html($result->get_error_message()));
        }

        $termId = (int) $result['term_id'];

        if ('' !== $color) {
            $color = Sanitize::hexColor($color);
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
     * @throws InvalidArgumentException If term does not exist, name is invalid, or parent does not exist.
     * @throws Exception If WordPress fails to update the term.
     */
    public function update(
        int $termId,
        string $name = '',
        int $parentId = -1,
        string $color = '',
        int $position = -1
    ): FolderModel {
        $folder = $this->getByIdOrFail($termId);

        $args = [];
        if ('' !== $name) {
            $name              = $this->sanitizeName($name);
            $effectiveParentId = -1 !== $parentId ? $parentId : $folder->getParentId();
            $name              = $this->ensureUniqueName($name, $effectiveParentId, $termId);
            $args['name']      = $name;
        }

        if (-1 !== $parentId) {
            if (0 < $parentId) {
                $this->getByIdOrFail($parentId);
            }
            $args['parent'] = $parentId;
        }

        if (count($args) > 0) {
            $result = wp_update_term($termId, TaxonomyService::TAXONOMY_NAME, $args);

            if (is_wp_error($result)) {
                throw new Exception(esc_html($result->get_error_message()));
            }
        }

        if ('' !== $color) {
            $color = Sanitize::hexColor($color);
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
        $this->getByIdOrFail($termId);

        $result = wp_delete_term($termId, TaxonomyService::TAXONOMY_NAME);

        if (true === $result) {
            $this->invalidateRootCache();
        }

        return true === $result;
    }

    /**
     * Assign media items to a folder
     *
     * Replaces any existing folder assignment for each media item. Returns
     * the list of folder IDs the media were previously assigned to (deduped,
     * excluding $folderId itself) so callers can refresh their ancestor
     * totals — those folders just lost media that the destination gained.
     *
     * @param int   $folderId Folder term ID
     * @param int[] $mediaIds Array of attachment post IDs
     *
     * @return int[] Previous folder IDs the media were assigned to, deduped,
     *               with $folderId removed.
     *
     * @throws InvalidArgumentException If folder does not exist.
     */
    public function assignMedia(int $folderId, array $mediaIds): array
    {
        $this->getByIdOrFail($folderId);
        $this->validateAttachmentIds($mediaIds);

        if (empty($mediaIds)) {
            return [];
        }

        // Track previous folder IDs so we can flush their cache too and
        // surface them to the caller for path-totals refresh.
        $previousFolderIds = [];
        foreach ($mediaIds as $mediaId) {
            $current = wp_get_object_terms($mediaId, TaxonomyService::TAXONOMY_NAME, ['fields' => 'ids']);
            if (is_array($current)) {
                foreach ($current as $cid) {
                    if (is_numeric($cid)) {
                        $previousFolderIds[] = (int) $cid;
                    }
                }
            }
            wp_set_object_terms($mediaId, $folderId, TaxonomyService::TAXONOMY_NAME, false);
        }

        // Force an immediate count update — wp_set_object_terms only updates
        // counts via deferred recount. Without this, downstream reads of
        // term_taxonomy.count within the same request return stale values.
        $idsToRecount = array_values(array_unique(array_merge([$folderId], $previousFolderIds)));
        wp_update_term_count_now($idsToRecount, TaxonomyService::TAXONOMY_NAME);

        clean_term_cache($idsToRecount, TaxonomyService::TAXONOMY_NAME);

        $this->invalidateRootCache();

        return array_values(array_unique(array_filter(
            $previousFolderIds,
            static fn (int $id): bool => $id !== $folderId
        )));
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
        $this->getByIdOrFail($folderId);

        foreach ($mediaIds as $mediaId) {
            wp_remove_object_terms($mediaId, $folderId, TaxonomyService::TAXONOMY_NAME);
        }

        wp_update_term_count_now([$folderId], TaxonomyService::TAXONOMY_NAME);
        clean_term_cache([$folderId], TaxonomyService::TAXONOMY_NAME);
        $this->invalidateRootCache();
    }

    /**
     * Count media items not assigned to any folder
     *
     * @return int
     */
    public function getRootMediaCount(): int
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
     * Get total size in bytes of media not assigned to any folder
     *
     * @return int Total size in bytes
     */
    public function getRootTotalSize(): int
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
     * Invalidate cached root counters
     *
     * @return void
     */
    private function invalidateRootCache(): void
    {
        wp_cache_delete(self::CACHE_ROOT_TOTAL_SIZE, self::CACHE_GROUP);
        wp_cache_delete(self::CACHE_ROOT_MEDIA_COUNT, self::CACHE_GROUP);
    }

    /**
     * Convert a list of WP_Term objects to FolderModel instances
     *
     * Pre-fetches term meta in bulk to avoid N+1 queries when models
     * read their color/position via FolderModel::fromTerm.
     *
     * @param WP_Term[] $terms WP_Term objects
     *
     * @return FolderModel[]
     */
    private function termsToModels(array $terms): array
    {
        $termIds = array_map(static fn (WP_Term $t): int => $t->term_id, $terms);
        if (! empty($termIds)) {
            update_termmeta_cache($termIds);
        }

        $models = [];
        foreach ($terms as $term) {
            $models[] = FolderModel::fromTerm($term);
        }
        return $models;
    }

    /**
     * Sanitize a folder name
     *
     * Applies sanitize_text_field, removes dangerous leading characters
     * (Excel injection prevention), strips control characters, trims
     * whitespace, and enforces a MAX_FOLDER_LENGTH character limit.
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

        if (mb_strlen($name) > self::MAX_FOLDER_LENGTH) {
            $name = mb_substr($name, 0, self::MAX_FOLDER_LENGTH);
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
     * Validate that all IDs are attachment post IDs
     *
     * Uses a single bulk query to verify all IDs at once.
     *
     * @param int[] $mediaIds Array of post IDs to validate
     *
     * @return void
     *
     * @throws InvalidArgumentException If any ID is not a valid attachment.
     */
    private function validateAttachmentIds(array $mediaIds): void
    {
        if (empty($mediaIds)) {
            return;
        }

        $mediaIds = array_map('intval', $mediaIds);
        $mediaIds = array_values(
            array_filter(
                $mediaIds,
                static function (int $id): bool {
                    return $id > 0;
                }
            )
        );

        if (empty($mediaIds)) {
            throw new InvalidArgumentException(
                esc_html__('No valid media IDs provided.', 'foldsnap')
            );
        }

        /** @var int[] $validIds */
        $validIds = get_posts(
            [
                'post__in'       => $mediaIds,
                'post_type'      => TaxonomyService::POST_TYPE,
                'post_status'    => 'any',
                'posts_per_page' => count($mediaIds),
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]
        );

        $invalidIds = array_diff($mediaIds, $validIds);

        if (! empty($invalidIds)) {
            throw new InvalidArgumentException(
                esc_html(
                    sprintf(
                        'Invalid attachment IDs: %s',
                        implode(', ', $invalidIds)
                    )
                )
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

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

    /** @var string Option holding the global Root total size (bytes) */
    public const OPT_ROOT_SIZE = 'foldsnap_opt_root_size';

    /** @var string Option holding the global Root media count */
    public const OPT_ROOT_COUNT = 'foldsnap_opt_root_count';

    /**
     * Retrieve a single folder by term ID
     *
     * Term ID 0 yields the synthetic Root folder (no database row backs it);
     * its direct media count is the number of unassigned attachments.
     *
     * @param int $termId Term ID (0 = virtual Root)
     *
     * @return FolderModel|null
     */
    public function getById(int $termId): ?FolderModel
    {
        if (FolderModel::ROOT_ID === $termId) {
            return $this->buildRootModel();
        }

        $term = get_term($termId, TaxonomyService::TAXONOMY_NAME);

        if (! $term instanceof WP_Term) {
            return null;
        }

        return FolderModel::fromTerms([$term])[0];
    }

    /**
     * Build the virtual Root model with current global counters and has_children
     *
     * @return FolderModel
     */
    private function buildRootModel(): FolderModel
    {
        $topLevelCounts = Database::getChildrenCounts([0], TaxonomyService::TAXONOMY_NAME);
        $hasChildren    = ($topLevelCounts[0] ?? 0) > 0;

        return FolderModel::root(
            $this->getRootMediaCount(),
            $this->getRootGlobalMediaCount(),
            $this->getRootGlobalTotalSize(),
            $hasChildren
        );
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

        $models = [];

        // Inject the virtual Root if requested; get_terms() can't return it.
        if (in_array(FolderModel::ROOT_ID, $termIds, true)) {
            $models[] = $this->buildRootModel();
        }

        $realIds = array_values(array_filter(
            $termIds,
            static fn (int $id): bool => $id > 0
        ));

        if (empty($realIds)) {
            return $models;
        }

        $terms = get_terms(
            [
                'taxonomy'   => TaxonomyService::TAXONOMY_NAME,
                'hide_empty' => false,
                'include'    => $realIds,
            ]
        );

        if (is_wp_error($terms) || ! is_array($terms)) {
            return $models;
        }

        return array_merge($models, FolderModel::fromTerms($terms));
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

            $result[$parentId] = FolderModel::fromTerms($terms);
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

        $folders = is_array($terms) ? FolderModel::fromTerms($terms) : [];

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

        // Initialize all four meta keys explicitly. The counter keys MUST exist
        // before the first bulk UPDATE on this folder; the color/position keys
        // are kept consistent for the same reason (uniform shape per term).
        $color = '' !== $color ? Sanitize::hexColor($color) : '';
        add_term_meta($termId, FolderModel::META_COLOR, $color, true);
        add_term_meta($termId, FolderModel::META_POSITION, (string) $position, true);
        add_term_meta($termId, FolderModel::META_SIZE, '0', true);
        add_term_meta($termId, FolderModel::META_COUNT, '0', true);

        return $this->getByIdOrFail($termId);
    }

    /**
     * Update an existing folder. Pass null for any field to leave it unchanged.
     *
     * @param int         $termId   Term ID
     * @param string|null $name     New name, or null to keep
     * @param int|null    $parentId New parent ID, or null to keep
     * @param string|null $color    New color, or null to keep
     * @param int|null    $position New position, or null to keep
     *
     * @return FolderModel
     *
     * @throws InvalidArgumentException If term does not exist, name is invalid, or parent does not exist.
     * @throws Exception If WordPress fails to update the term.
     */
    public function update(
        int $termId,
        ?string $name = null,
        ?int $parentId = null,
        ?string $color = null,
        ?int $position = null
    ): FolderModel {
        $this->guardNotRoot($termId);

        $folder = $this->getByIdOrFail($termId);

        $args = [];
        if (null !== $name) {
            $name              = $this->sanitizeName($name);
            $effectiveParentId = $parentId ?? $folder->getParentId();
            $name              = $this->ensureUniqueName($name, $effectiveParentId, $termId);
            $args['name']      = $name;
        }

        $oldParentId = $folder->getParentId();
        $isReparent  = null !== $parentId && $parentId !== $oldParentId;

        if (null !== $parentId) {
            if (0 < $parentId) {
                $this->getByIdOrFail($parentId);
            }
            $args['parent'] = $parentId;
        }

        $subtreeSize  = $isReparent ? $folder->getTotalSize() : 0;
        $subtreeCount = $isReparent ? $folder->getTotalMediaCount() : 0;

        if (count($args) > 0) {
            $result = wp_update_term($termId, TaxonomyService::TAXONOMY_NAME, $args);

            if (is_wp_error($result)) {
                throw new Exception(esc_html($result->get_error_message()));
            }
        }

        if (null !== $color) {
            update_term_meta($termId, FolderModel::META_COLOR, Sanitize::hexColor($color));
        }

        if (null !== $position) {
            update_term_meta($termId, FolderModel::META_POSITION, (string) $position);
        }

        if ($isReparent && ($subtreeSize > 0 || $subtreeCount > 0)) {
            $oldChain = $oldParentId > 0 ? $this->ancestorChainIncluding($oldParentId) : [];
            $this->applyChainDelta($oldChain, -$subtreeSize, -$subtreeCount);

            $newChain = ($parentId ?? 0) > 0 ? $this->ancestorChainIncluding((int) $parentId) : [];
            $this->applyChainDelta($newChain, $subtreeSize, $subtreeCount);
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
        $this->guardNotRoot($termId);

        $folder = $this->getByIdOrFail($termId);

        // Snapshot the data needed for delta math before the term is gone.
        // - direct counters of $termId (its direct media will end up in Root,
        //   so the ancestor chain loses these)
        // - parent ancestor chain EXCLUDING $termId (its sub-folders get
        //   promoted to grandparent; their subtrees were already aggregated
        //   into the ancestors, so no delta is needed for sub-folder moves)
        $directCount = $folder->getMediaCount();
        $directSize  = 0;
        if ($directCount > 0) {
            $directSizes = Database::getDirectSizesForFolders([$termId], TaxonomyService::TAXONOMY_NAME);
            $directSize  = $directSizes[$termId] ?? 0;
        }

        $parentId       = $folder->getParentId();
        $ancestorsAbove = $parentId > 0 ? $this->ancestorChainIncluding($parentId) : [];

        $result = wp_delete_term($termId, TaxonomyService::TAXONOMY_NAME);

        if (true === $result) {
            $this->applyChainDelta($ancestorsAbove, -$directSize, -$directCount);
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
        // Assigning to Root is semantically "unassign from any folder" —
        // Root has no term, so the media simply lose their existing folder
        // relationships and surface as unassigned.
        if (FolderModel::ROOT_ID === $folderId) {
            return $this->unassignMedia($mediaIds);
        }

        $this->getByIdOrFail($folderId);
        $this->validateAttachmentIds($mediaIds);

        if (empty($mediaIds)) {
            return [];
        }

        // Read current folder for each media. Media already in $folderId are
        // skipped in delta math (idempotent), but we still re-set them so the
        // term relationship is unambiguous.
        /** @var array<int, int> $currentFolderByMedia */
        $currentFolderByMedia = [];
        foreach ($mediaIds as $mediaId) {
            $current = wp_get_object_terms($mediaId, TaxonomyService::TAXONOMY_NAME, ['fields' => 'ids']);
            $cid     = 0;
            if (is_array($current) && ! empty($current) && is_numeric($current[0])) {
                $cid = (int) $current[0];
            }
            $currentFolderByMedia[(int) $mediaId] = $cid;
        }

        // Filesizes for delta math. Single bulk query.
        $sizeByMedia = Database::getMediaFileSizes(array_keys($currentFolderByMedia));

        // Group media that actually move: by origin folder ID (0 = was in Root).
        /** @var array<int, array{count:int, size:int}> $deltaByOrigin */
        $deltaByOrigin = [];
        $destAddCount  = 0;
        $destAddSize   = 0;
        foreach ($currentFolderByMedia as $mediaId => $originId) {
            if ($originId === $folderId) {
                continue;
            }
            $bytes         = $sizeByMedia[$mediaId] ?? 0;
            $destAddCount += 1;
            $destAddSize  += $bytes;

            if ($originId > 0) {
                if (! isset($deltaByOrigin[$originId])) {
                    $deltaByOrigin[$originId] = [
                        'count' => 0,
                        'size'  => 0,
                    ];
                }
                $deltaByOrigin[$originId]['count'] += 1;
                $deltaByOrigin[$originId]['size']  += $bytes;
            }
        }

        $previousFolderIds = [];
        foreach ($mediaIds as $mediaId) {
            $previousFolderIds[] = $currentFolderByMedia[(int) $mediaId];
            wp_set_object_terms($mediaId, $folderId, TaxonomyService::TAXONOMY_NAME, false);
        }

        // Force an immediate count update — wp_set_object_terms only updates
        // counts via deferred recount. Without this, downstream reads of
        // term_taxonomy.count within the same request return stale values.
        $idsToRecount = array_values(array_unique(array_filter(
            array_merge([$folderId], $previousFolderIds),
            static fn (int $id): bool => $id > 0
        )));
        if (! empty($idsToRecount)) {
            wp_update_term_count_now($idsToRecount, TaxonomyService::TAXONOMY_NAME);
            clean_term_cache($idsToRecount, TaxonomyService::TAXONOMY_NAME);
        }

        // Apply deltas to ancestor chains.
        // Origins: subtract per-origin aggregate from origin's chain.
        foreach ($deltaByOrigin as $originId => $delta) {
            $chain = $this->ancestorChainIncluding($originId);
            $this->applyChainDelta($chain, -$delta['size'], -$delta['count']);
        }
        // Destination: add total moved-in to destination chain.
        if ($destAddCount > 0 || $destAddSize > 0) {
            $destChain = $this->ancestorChainIncluding($folderId);
            $this->applyChainDelta($destChain, $destAddSize, $destAddCount);
        }

        $this->invalidateRootCache();

        return array_values(array_unique(array_filter(
            $previousFolderIds,
            static fn (int $id): bool => $id > 0 && $id !== $folderId
        )));
    }

    /**
     * Strip every folder term from the given media items
     *
     * Used when "assigning" media to the virtual Root folder.
     *
     * @param int[] $mediaIds Attachment IDs.
     *
     * @return int[] Folder IDs the media were previously assigned to.
     *
     * @throws InvalidArgumentException If any ID is not a valid attachment.
     */
    private function unassignMedia(array $mediaIds): array
    {
        $this->validateAttachmentIds($mediaIds);

        if (empty($mediaIds)) {
            return [];
        }

        // Read current folder per media for delta math.
        /** @var array<int, int> $currentFolderByMedia */
        $currentFolderByMedia = [];
        foreach ($mediaIds as $mediaId) {
            $current = wp_get_object_terms($mediaId, TaxonomyService::TAXONOMY_NAME, ['fields' => 'ids']);
            $cid     = 0;
            if (is_array($current) && ! empty($current) && is_numeric($current[0])) {
                $cid = (int) $current[0];
            }
            $currentFolderByMedia[(int) $mediaId] = $cid;
        }

        $sizeByMedia = Database::getMediaFileSizes(array_keys($currentFolderByMedia));

        /** @var array<int, array{count:int, size:int}> $deltaByOrigin */
        $deltaByOrigin = [];
        foreach ($currentFolderByMedia as $mediaId => $originId) {
            if ($originId <= 0) {
                continue;
            }
            $bytes = $sizeByMedia[$mediaId] ?? 0;
            if (! isset($deltaByOrigin[$originId])) {
                $deltaByOrigin[$originId] = [
                    'count' => 0,
                    'size'  => 0,
                ];
            }
            $deltaByOrigin[$originId]['count'] += 1;
            $deltaByOrigin[$originId]['size']  += $bytes;
        }

        $previousFolderIds = [];
        foreach ($mediaIds as $mediaId) {
            $previousFolderIds[] = $currentFolderByMedia[(int) $mediaId];
            // Clearing all terms by passing an empty array.
            wp_set_object_terms($mediaId, [], TaxonomyService::TAXONOMY_NAME, false);
        }

        $previousFolderIds = array_values(array_unique(array_filter(
            $previousFolderIds,
            static fn (int $id): bool => $id > 0
        )));

        if (! empty($previousFolderIds)) {
            wp_update_term_count_now($previousFolderIds, TaxonomyService::TAXONOMY_NAME);
            clean_term_cache($previousFolderIds, TaxonomyService::TAXONOMY_NAME);
        }

        // Subtract from each origin's ancestor chain. Root global counters are
        // invariant — the media is still on the site, just unassigned.
        foreach ($deltaByOrigin as $originId => $delta) {
            $chain = $this->ancestorChainIncluding($originId);
            $this->applyChainDelta($chain, -$delta['size'], -$delta['count']);
        }

        $this->invalidateRootCache();

        return $previousFolderIds;
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
        $this->guardNotRoot($folderId);
        $this->getByIdOrFail($folderId);

        // Only media currently assigned to $folderId contribute to the delta;
        // wp_remove_object_terms is a no-op for the rest, but we want exact
        // counts.
        $assigned = [];
        foreach ($mediaIds as $mediaId) {
            $current = wp_get_object_terms($mediaId, TaxonomyService::TAXONOMY_NAME, ['fields' => 'ids']);
            if (is_array($current) && in_array($folderId, array_map('intval', $current), true)) {
                $assigned[] = (int) $mediaId;
            }
        }

        foreach ($mediaIds as $mediaId) {
            wp_remove_object_terms($mediaId, $folderId, TaxonomyService::TAXONOMY_NAME);
        }

        wp_update_term_count_now([$folderId], TaxonomyService::TAXONOMY_NAME);
        clean_term_cache([$folderId], TaxonomyService::TAXONOMY_NAME);

        if (! empty($assigned)) {
            $sizeByMedia = Database::getMediaFileSizes($assigned);
            $sizeDelta   = 0;
            foreach ($sizeByMedia as $bytes) {
                $sizeDelta += $bytes;
            }
            $countDelta = count($assigned);

            $chain = $this->ancestorChainIncluding($folderId);
            $this->applyChainDelta($chain, -$sizeDelta, -$countDelta);
        }

        // Root global counters are invariant: the media is now unassigned (in
        // Root direct), but still inside the site total.
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

    /**
     * Reject mutations against the virtual Root folder
     *
     * Root has no underlying term and is conceptually a fixed anchor for the
     * tree. Rename, reparent, delete, and removeMedia are forbidden.
     *
     * @param int $termId Term ID being mutated.
     *
     * @return void
     *
     * @throws InvalidArgumentException If $termId is the Root sentinel.
     */
    private function guardNotRoot(int $termId): void
    {
        if (FolderModel::ROOT_ID === $termId) {
            throw new InvalidArgumentException(
                esc_html__('Root folder cannot be modified.', 'foldsnap')
            );
        }
    }

    /**
     * Resolve ancestor chain of a folder, excluding Root
     *
     * Returns the list of term IDs whose `total_*` aggregates include this
     * folder: the folder itself first, then its parent, grandparent, ... up
     * to the top-level folder. Stops just before the virtual Root (id 0,
     * which has its own option-backed counters).
     *
     * @param int $termId Folder term ID (must be > 0).
     *
     * @return int[] Term IDs from leaf up to top-level (no Root).
     */
    private function ancestorChainIncluding(int $termId): array
    {
        if ($termId <= 0) {
            return [];
        }

        $chain     = [];
        $currentId = $termId;
        $maxDepth  = 64;

        while ($currentId > 0 && $maxDepth-- > 0) {
            $chain[]   = $currentId;
            $term      = get_term($currentId, TaxonomyService::TAXONOMY_NAME);
            $currentId = $term instanceof WP_Term ? (int) $term->parent : 0;
        }

        return $chain;
    }

    /**
     * Apply a (size, count) delta to every term meta in a chain
     *
     * @param int[] $termIds    Term IDs to adjust (typically an ancestor chain).
     * @param int   $sizeDelta  Signed bytes delta.
     * @param int   $countDelta Signed media-count delta.
     *
     * @return void
     */
    private function applyChainDelta(array $termIds, int $sizeDelta, int $countDelta): void
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
     * Adjust the global Root counters (option-backed)
     *
     * Used only by attachment lifecycle hooks (add/delete attachment) — folder
     * mutations leave the global Root totals invariant because media stays in
     * the site, only the sub-folder it lives in changes.
     *
     * @param int $sizeDelta  Signed bytes delta.
     * @param int $countDelta Signed media-count delta.
     *
     * @return void
     */
    public function updateRootCounters(int $sizeDelta, int $countDelta): void
    {
        if (0 !== $sizeDelta) {
            $current = $this->getIntOption(self::OPT_ROOT_SIZE);
            update_option(self::OPT_ROOT_SIZE, max(0, $current + $sizeDelta));
        }
        if (0 !== $countDelta) {
            $current = $this->getIntOption(self::OPT_ROOT_COUNT);
            update_option(self::OPT_ROOT_COUNT, max(0, $current + $countDelta));
        }

        $this->invalidateRootCache();
    }

    /**
     * Get the cached global Root total size (bytes)
     *
     * @return int
     */
    public function getRootGlobalTotalSize(): int
    {
        return $this->getIntOption(self::OPT_ROOT_SIZE);
    }

    /**
     * Get the cached global Root media count
     *
     * @return int
     */
    public function getRootGlobalMediaCount(): int
    {
        return $this->getIntOption(self::OPT_ROOT_COUNT);
    }

    /**
     * Read an option as an int with a safe default
     *
     * Wraps the inherently-mixed return of get_option in a typed access so
     * PHPStan doesn't flag the cast — and so a corrupted option string
     * never propagates as 0 silently when the value should fall back.
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

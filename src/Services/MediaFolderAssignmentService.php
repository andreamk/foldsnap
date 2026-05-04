<?php

/**
 * Media ↔ folder assignment.
 *
 * Owns every code path that changes which folder an attachment lives in:
 * assigning to a folder, removing from a folder, and the "assign to Root"
 * shortcut that strips every folder term from a media. Each operation
 * keeps the ancestor-chain counters and the cached unassigned counters
 * coherent through FolderCounterService.
 *
 * Extracted from FolderRepository so the repository can stay a focused
 * CRUD surface.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Services;

use FoldSnap\Models\FolderModel;
use InvalidArgumentException;
use WP_Term;

class MediaFolderAssignmentService
{
    private FolderRepository $repository;
    private FolderCounterService $counters;

    /**
     * @param FolderRepository     $repository Used to validate folder IDs.
     * @param FolderCounterService $counters   Chain-delta + Root cache writer.
     */
    public function __construct(FolderRepository $repository, FolderCounterService $counters)
    {
        $this->repository = $repository;
        $this->counters   = $counters;
    }

    /**
     * Assign media items to a folder.
     *
     * Replaces any existing folder assignment for each media item.
     * Assigning to Root is semantically "unassign from any folder" — Root
     * has no backing term, so the media surface as unassigned.
     *
     * @param int   $folderId Folder term ID
     * @param int[] $mediaIds Array of attachment post IDs
     *
     * @return int[] Folder IDs the media were previously assigned to,
     *               deduped, with $folderId itself removed.
     *
     * @throws InvalidArgumentException If folder does not exist.
     */
    public function assign(int $folderId, array $mediaIds): array
    {
        if (FolderModel::ROOT_ID === $folderId) {
            return $this->unassign($mediaIds);
        }

        if (null === $this->repository->getById($folderId)) {
            throw new InvalidArgumentException(
                esc_html(sprintf('Folder with ID %d does not exist.', $folderId))
            );
        }

        $this->validateAttachmentIds($mediaIds);

        if (empty($mediaIds)) {
            return [];
        }

        // Read current folder for each media. Media already in $folderId are
        // skipped in delta math (idempotent), but we still re-set them so the
        // term relationship is unambiguous.
        $currentFolderByMedia = $this->currentFolderByMedia($mediaIds);

        // Filesizes for delta math. Single bulk query.
        $sizeByMedia = Database::getMediaFileSizes(array_keys($currentFolderByMedia));

        // Group media that actually move: by origin folder ID (0 = was in Root).
        $deltaByOrigin = $this->aggregateOriginDeltas($currentFolderByMedia, $sizeByMedia, $folderId);

        // Destination delta covers every media that moves, including those
        // coming from Root (origin 0) which the per-origin aggregation skips.
        $destAddCount = 0;
        $destAddSize  = 0;
        foreach ($currentFolderByMedia as $mediaId => $originId) {
            if ($originId === $folderId) {
                continue;
            }
            $destAddCount += 1;
            $destAddSize  += $sizeByMedia[$mediaId] ?? 0;
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
            $chain = FolderTreeNavigator::ancestorIds($originId);
            $this->counters->applyChainDelta($chain, -$delta['size'], -$delta['count']);
        }
        // Destination: add total moved-in to destination chain.
        if ($destAddCount > 0 || $destAddSize > 0) {
            $destChain = FolderTreeNavigator::ancestorIds($folderId);
            $this->counters->applyChainDelta($destChain, $destAddSize, $destAddCount);
        }

        $this->counters->invalidateUnassignedCache();

        return array_values(array_unique(array_filter(
            $previousFolderIds,
            static fn (int $id): bool => $id > 0 && $id !== $folderId
        )));
    }

    /**
     * Strip every folder term from the given media items.
     *
     * @param int[] $mediaIds Attachment IDs.
     *
     * @return int[] Folder IDs the media were previously assigned to.
     *
     * @throws InvalidArgumentException If any ID is not a valid attachment.
     */
    public function unassign(array $mediaIds): array
    {
        $this->validateAttachmentIds($mediaIds);

        if (empty($mediaIds)) {
            return [];
        }

        // Read current folder per media for delta math.
        $currentFolderByMedia = $this->currentFolderByMedia($mediaIds);

        $sizeByMedia = Database::getMediaFileSizes(array_keys($currentFolderByMedia));

        $deltaByOrigin = $this->aggregateOriginDeltas($currentFolderByMedia, $sizeByMedia);

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
            $chain = FolderTreeNavigator::ancestorIds($originId);
            $this->counters->applyChainDelta($chain, -$delta['size'], -$delta['count']);
        }

        $this->counters->invalidateUnassignedCache();

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
    public function remove(int $folderId, array $mediaIds): void
    {
        if (FolderModel::ROOT_ID === $folderId) {
            throw new InvalidArgumentException(
                esc_html__('Root folder cannot be modified.', 'foldsnap')
            );
        }

        if (null === $this->repository->getById($folderId)) {
            throw new InvalidArgumentException(
                esc_html(sprintf('Folder with ID %d does not exist.', $folderId))
            );
        }

        // Only media currently assigned to $folderId contribute to the delta;
        // wp_remove_object_terms is a no-op for the rest, but we want exact
        // counts.
        $currentFolderByMedia = $this->currentFolderByMedia($mediaIds);
        $assigned             = [];
        foreach ($currentFolderByMedia as $mediaId => $currentFolderId) {
            if ($currentFolderId === $folderId) {
                $assigned[] = $mediaId;
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

            $chain = FolderTreeNavigator::ancestorIds($folderId);
            $this->counters->applyChainDelta($chain, -$sizeDelta, -$countDelta);
        }

        // Root global counters are invariant: the media is now unassigned (in
        // Root direct), but still inside the site total.
        $this->counters->invalidateUnassignedCache();
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
     * Resolve the current folder term ID for each media in a single query.
     *
     * `wp_get_object_terms` accepts an array of object IDs and returns every
     * (object, term) pair via `'all_with_object_id'`, so we get the full map
     * without an N+1 loop.
     *
     * Each media gets at most one folder; if multiple terms come back for the
     * same media (legacy data) the first one wins, matching the previous
     * single-fetch behaviour.
     *
     * @param int[] $mediaIds Attachment IDs (already validated as positive).
     *
     * @return array<int,int> Map of media ID => folder term ID (0 = unassigned).
     */
    private function currentFolderByMedia(array $mediaIds): array
    {
        /** @var array<int,int> $byMedia */
        $byMedia = [];
        foreach ($mediaIds as $mediaId) {
            $byMedia[(int) $mediaId] = 0;
        }

        if (empty($byMedia)) {
            return [];
        }

        $terms = wp_get_object_terms(
            array_keys($byMedia),
            TaxonomyService::TAXONOMY_NAME,
            ['fields' => 'all_with_object_id']
        );

        if (! is_array($terms)) {
            return $byMedia;
        }

        foreach ($terms as $term) {
            if (! ($term instanceof WP_Term)) {
                continue;
            }
            // `object_id` is dynamically attached by 'all_with_object_id'; not
            // declared on WP_Term so PHPStan sees it as mixed.
            $rawObjectId = $term->object_id ?? null;
            $mediaId     = is_numeric($rawObjectId) ? (int) $rawObjectId : 0;
            if ($mediaId <= 0 || ! isset($byMedia[$mediaId])) {
                continue;
            }
            // First term wins; ignore any duplicates from legacy data.
            if (0 === $byMedia[$mediaId]) {
                $byMedia[$mediaId] = (int) $term->term_id;
            }
        }

        return $byMedia;
    }

    /**
     * Aggregate per-origin (count, size) deltas for a batch of moves.
     *
     * @param array<int,int> $currentFolderByMedia Map of media ID => origin folder ID.
     * @param array<int,int> $sizeByMedia          Map of media ID => byte size.
     * @param int            $skipFolderId         Folder to skip (e.g. destination
     *                                             on assign — moves into self are
     *                                             a no-op). Use 0 to skip nothing.
     *
     * @return array<int, array{count:int, size:int}> Map keyed by origin folder ID.
     *                                                Origin 0 (unassigned) is
     *                                                excluded — it has no chain.
     */
    private function aggregateOriginDeltas(
        array $currentFolderByMedia,
        array $sizeByMedia,
        int $skipFolderId = 0
    ): array {
        /** @var array<int, array{count:int, size:int}> $deltaByOrigin */
        $deltaByOrigin = [];

        foreach ($currentFolderByMedia as $mediaId => $originId) {
            if ($originId <= 0 || $originId === $skipFolderId) {
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

        return $deltaByOrigin;
    }
}

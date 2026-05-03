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
}

<?php

/**
 * Tree navigation utilities for folder hierarchy
 *
 * Computes recursive aggregates (total media count, total size) and
 * walks the parent chain to resolve paths. Pure read service: it does
 * not mutate any state.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Services;

use FoldSnap\Models\FolderModel;

class FolderTreeNavigator
{
    private FolderRepository $repository;

    /**
     * Constructor
     *
     * @param FolderRepository $repository Folder repository instance
     */
    public function __construct(FolderRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Compute total_media_count and total_size for each requested folder
     *
     * Walks descendants once via Database::getDescendantIds, then issues
     * one bulk query for direct sizes covering each folder + all its
     * descendants. Direct media counts come from the FolderModel itself
     * (WP_Term->count). Returns a map keyed by folder ID.
     *
     * @param FolderModel[] $folders Folders to compute totals for
     *
     * @return array<int, array{total_media_count:int,total_size:int}>
     */
    public function computeTotals(array $folders): array
    {
        /** @var array<int, array{total_media_count:int,total_size:int}> $result */
        $result = [];

        if (empty($folders)) {
            return $result;
        }

        $rootIds           = array_map(static fn (FolderModel $f): int => $f->getId(), $folders);
        $descendantsByRoot = Database::getDescendantIds($rootIds, TaxonomyService::TAXONOMY_NAME);

        // Collect every folder ID we need a direct size for: each requested folder + every descendant.
        /** @var int[] $allIds */
        $allIds = $rootIds;
        foreach ($descendantsByRoot as $descendants) {
            foreach ($descendants as $id) {
                $allIds[] = $id;
            }
        }
        $allIds = array_values(array_unique($allIds));

        $directSizeMap  = Database::getDirectSizesForFolders($allIds, TaxonomyService::TAXONOMY_NAME);
        $directCountMap = Database::getDirectCountsForFolders($allIds, TaxonomyService::TAXONOMY_NAME);

        foreach ($folders as $folder) {
            $rootId    = $folder->getId();
            $totalSize = $directSizeMap[$rootId] ?? 0;
            // Read root count from the same fresh DB map for consistency, falling
            // back to the FolderModel's cached value only if the row is missing.
            $totalCount = $directCountMap[$rootId] ?? $folder->getMediaCount();

            $descendants = $descendantsByRoot[$rootId] ?? [];
            foreach ($descendants as $descendantId) {
                $totalSize  += $directSizeMap[$descendantId] ?? 0;
                $totalCount += $directCountMap[$descendantId] ?? 0;
            }

            $result[$rootId] = [
                'total_media_count' => $totalCount,
                'total_size'        => $totalSize,
            ];
        }

        return $result;
    }

    /**
     * Determine which folders have direct children
     *
     * @param int[] $folderIds Folder term IDs to check
     *
     * @return array<int, bool> Map of folder ID => has_children
     */
    public function hasChildren(array $folderIds): array
    {
        $counts = Database::getChildrenCounts($folderIds, TaxonomyService::TAXONOMY_NAME);

        /** @var array<int, bool> $result */
        $result = [];
        foreach ($counts as $folderId => $count) {
            $result[$folderId] = $count > 0;
        }

        return $result;
    }

    /**
     * Resolve the path from root to the given folder (inclusive)
     *
     * @param int $folderId Target folder term ID
     *
     * @return FolderModel[] Ordered list: root → ... → target
     */
    public function resolvePath(int $folderId): array
    {
        if ($folderId <= 0) {
            return [];
        }

        /** @var FolderModel[] $reverse */
        $reverse = [];

        $currentId = $folderId;
        $maxDepth  = 64;

        while ($currentId > 0 && $maxDepth-- > 0) {
            $folder = $this->repository->getById($currentId);
            if (null === $folder) {
                return [];
            }

            $reverse[] = $folder;
            $currentId = $folder->getParentId();
        }

        return array_reverse($reverse);
    }
}

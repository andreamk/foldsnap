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
     * Reads pre-computed aggregates from term meta (foldsnap_folder_count,
     * foldsnap_folder_size) maintained incrementally by FolderRepository.
     * Pre-fetches both keys in bulk through update_termmeta_cache to avoid
     * N+1 queries. Root reads its option-backed global totals.
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

        $realFolderIds = [];
        foreach ($folders as $folder) {
            if ($folder->isRoot()) {
                $result[$folder->getId()] = $this->computeRootTotals();
                continue;
            }
            $realFolderIds[] = $folder->getId();
        }

        if (! empty($realFolderIds)) {
            update_termmeta_cache($realFolderIds);

            foreach ($realFolderIds as $id) {
                $rawCount = get_term_meta($id, FolderModel::META_COUNT, true);
                $rawSize  = get_term_meta($id, FolderModel::META_SIZE, true);

                $result[$id] = [
                    'total_media_count' => is_numeric($rawCount) ? (int) $rawCount : 0,
                    'total_size'        => is_numeric($rawSize) ? (int) $rawSize : 0,
                ];
            }
        }

        return $result;
    }

    /**
     * Compute the totals for the virtual Root folder
     *
     * The Root represents every attachment on the site. Totals are read from
     * the cached options (foldsnap_opt_root_count / foldsnap_opt_root_size)
     * maintained by attachment-lifecycle hooks. If the options are missing
     * (e.g. before the first migration run), falls back to a one-shot global
     * query so the UI never shows zero pre-recalculate.
     *
     * @return array{total_media_count:int,total_size:int}
     */
    private function computeRootTotals(): array
    {
        $countOpt = get_option(FolderRepository::OPT_ROOT_COUNT, null);
        $sizeOpt  = get_option(FolderRepository::OPT_ROOT_SIZE, null);

        $count = is_numeric($countOpt)
            ? (int) $countOpt
            : Database::getGlobalMediaCount(TaxonomyService::POST_TYPE);
        $size  = is_numeric($sizeOpt)
            ? (int) $sizeOpt
            : Database::getGlobalTotalSize(TaxonomyService::POST_TYPE);

        return [
            'total_media_count' => $count,
            'total_size'        => $size,
        ];
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
     * Resolve the path from Root to the given folder (inclusive)
     *
     * The first entry is always the virtual Root folder, even when the
     * target is Root itself (the chain is then `[Root]`). For a real folder
     * id, the chain runs `[Root, ancestor1, ..., target]`.
     *
     * Returns an empty array only when a non-Root id refers to a folder that
     * does not exist.
     *
     * @param int $folderId Target folder term ID (0 = Root).
     *
     * @return FolderModel[] Ordered list from Root to target.
     */
    public function resolvePath(int $folderId): array
    {
        $root = $this->repository->getById(FolderModel::ROOT_ID);
        if (null === $root) {
            return [];
        }

        if (FolderModel::ROOT_ID === $folderId) {
            return [$root];
        }

        if ($folderId < 0) {
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

        return array_merge([$root], array_reverse($reverse));
    }
}

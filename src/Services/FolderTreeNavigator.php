<?php

/**
 * Tree navigation utilities for folder hierarchy
 *
 * Walks the parent chain to resolve paths and answers "does folder X have
 * direct children?" via a single GROUP BY query. Pure read service: it
 * does not mutate any state.
 *
 * Recursive aggregates (total_media_count, total_size) are no longer the
 * navigator's concern — they live on the FolderModel itself, populated
 * from term meta by `FolderModel::fromTerm`, maintained incrementally by
 * FolderRepository on every mutation.
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

<?php

/**
 * Tree navigation utilities for folder hierarchy
 *
 * Walks the parent chain to resolve paths (instance method, requires the
 * repository to materialise FolderModel) and to compute the ancestor-id
 * chain used by counter delta math (static, only needs `get_term`).
 * Pure read service — never mutates state.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Services;

use FoldSnap\Models\FolderModel;
use WP_Term;

class FolderTreeNavigator
{
    /** @var int Maximum chain depth to guard against pathological data */
    private const MAX_DEPTH = 64;

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
     * Walk the parent chain leaf-first, returning every term ID up to (and
     * excluding) Root.
     *
     * Single source of truth for ancestor-chain math used by counter deltas.
     *
     * @param int $termId Folder term ID (must be > 0).
     *
     * @return int[] Term IDs from $termId up to top-level (Root excluded).
     */
    public static function ancestorIds(int $termId): array
    {
        if ($termId <= 0) {
            return [];
        }

        $chain     = [];
        $currentId = $termId;
        $maxDepth  = self::MAX_DEPTH;

        while ($currentId > 0 && $maxDepth-- > 0) {
            $chain[] = $currentId;
            $term    = get_term($currentId, TaxonomyService::TAXONOMY_NAME);
            if (! ($term instanceof WP_Term)) {
                break;
            }
            $currentId = (int) $term->parent;
        }

        return $chain;
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

        /** @var int[] $ancestors */
        $ancestors = get_ancestors($folderId, TaxonomyService::TAXONOMY_NAME, 'taxonomy');

        // Chain runs leaf-first ([target, parent, ..., topLevel]); cap to guard pathological data.
        $chainIds = array_slice(
            array_merge([$folderId], $ancestors),
            0,
            self::MAX_DEPTH
        );

        $models   = $this->repository->getByIds($chainIds);
        $modelMap = [];
        foreach ($models as $model) {
            $modelMap[$model->getId()] = $model;
        }

        // Any missing id signals a stale chain — surface as "not found" like the previous walker.
        $ordered = [];
        foreach (array_reverse($chainIds) as $id) {
            if (! isset($modelMap[$id])) {
                return [];
            }
            $ordered[] = $modelMap[$id];
        }

        return array_merge([$root], $ordered);
    }
}

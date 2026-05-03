<?php

/**
 * Presenter helpers shared by folder REST controllers
 *
 * `decorateFolders` is just a map over `FolderModel::toArray()`, kept as a
 * named helper for readability at every call site. `buildAffectedParents`
 * carries parent IDs the client must refresh; their `has_children` is
 * looked up in bulk because the IDs are not necessarily already loaded.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Controllers;

use FoldSnap\Models\FolderModel;
use FoldSnap\Services\Database;
use FoldSnap\Services\TaxonomyService;

/**
 * @phpstan-import-type FolderArray from \FoldSnap\Models\FolderModel
 */
trait RestApiFolderPresenter
{
    /**
     * Serialize folders for the wire
     *
     * @param FolderModel[] $folders Folders to serialize
     *
     * @return FolderArray[]
     */
    private function decorateFolders(array $folders): array
    {
        return array_values(array_map(static fn (FolderModel $f): array => $f->toArray(), $folders));
    }

    /**
     * Build affected_parents payload for chevron refresh
     *
     * Each entry: {id: int, has_children: bool}. Parent ID 0 (root) is
     * skipped because root has no chevron in the tree UI.
     *
     * @param int[] $parentIds Parent term IDs
     *
     * @return array<int, array{id:int,has_children:bool}>
     */
    private function buildAffectedParents(array $parentIds): array
    {
        $parentIds = array_values(array_unique(array_filter($parentIds, static fn (int $id): bool => $id > 0)));

        if (empty($parentIds)) {
            return [];
        }

        $counts = Database::getChildrenCounts($parentIds, TaxonomyService::TAXONOMY_NAME);

        $result = [];
        foreach ($parentIds as $id) {
            $result[] = [
                'id'           => $id,
                'has_children' => ($counts[$id] ?? 0) > 0,
            ];
        }

        return $result;
    }
}

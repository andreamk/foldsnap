<?php

/**
 * Presenter helpers shared by folder REST controllers.
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
     * Build the affected_parents payload.
     *
     * Returns one `{id, has_children}` entry per non-root parent ID,
     * deduplicated. Parent ID 0 (root) is skipped.
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

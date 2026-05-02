<?php

/**
 * Presenter helpers shared by folder REST controllers
 *
 * Decorates pure FolderModel DTOs with computed view fields (totals,
 * has_children) before they hit the wire, and builds the affected_parents
 * envelope used by mutation responses to drive client-side chevron refresh.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Controllers;

use FoldSnap\Models\FolderModel;
use FoldSnap\Services\FolderTreeNavigator;

trait RestApiFolderPresenter
{
    /**
     * Get the navigator instance to use for total/has_children computation
     *
     * @return FolderTreeNavigator
     */
    abstract protected function navigator(): FolderTreeNavigator;

    /**
     * Decorate FolderModel instances with totals and has_children
     *
     * @param FolderModel[] $folders Folders to decorate
     *
     * @return array<int, array<string, mixed>> Indexed by position in $folders
     */
    private function decorateFolders(array $folders): array
    {
        if (empty($folders)) {
            return [];
        }

        $totals      = $this->navigator()->computeTotals($folders);
        $hasChildren = $this->navigator()->hasChildren(
            array_map(static fn (FolderModel $f): int => $f->getId(), $folders)
        );

        $decorated = [];
        foreach ($folders as $folder) {
            $id    = $folder->getId();
            $array = $folder->toArray();

            $array['total_media_count'] = $totals[$id]['total_media_count'] ?? $folder->getMediaCount();
            $array['total_size']        = $totals[$id]['total_size'] ?? 0;
            $array['has_children']      = $hasChildren[$id] ?? false;

            $decorated[] = $array;
        }

        return $decorated;
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

        $hasChildren = $this->navigator()->hasChildren($parentIds);

        $result = [];
        foreach ($parentIds as $id) {
            $result[] = [
                'id'           => $id,
                'has_children' => $hasChildren[$id] ?? false,
            ];
        }

        return $result;
    }
}

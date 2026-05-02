<?php

/**
 * REST controller for folder write operations
 *
 * Owns create / update / delete on folders, plus media assignment. Each
 * mutation returns a uniform envelope with the affected folder, its full
 * ancestor path (with refreshed totals), and the parents whose has_children
 * may have flipped, so clients can patch their cached tree without a full
 * refetch.
 *
 * Routes are registered by RestApiController, which forwards to the methods
 * here as callbacks.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Controllers;

use FoldSnap\Services\FolderRepository;
use FoldSnap\Services\FolderTreeNavigator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RestApiFolderMutationsController
{
    use RestApiRequestUtils;
    use RestApiFolderPresenter;

    private FolderRepository $repository;
    private FolderTreeNavigator $navigatorInstance;

    /**
     * Constructor
     *
     * @param FolderRepository    $repository Folder repository instance
     * @param FolderTreeNavigator $navigator  Tree navigator instance
     */
    public function __construct(FolderRepository $repository, FolderTreeNavigator $navigator)
    {
        $this->repository        = $repository;
        $this->navigatorInstance = $navigator;
    }

    /**
     * Get navigator instance (used by RestApiFolderPresenter)
     *
     * @return FolderTreeNavigator
     */
    protected function navigator(): FolderTreeNavigator
    {
        return $this->navigatorInstance;
    }

    /**
     * POST /foldsnap/v1/folders
     *
     * @param WP_REST_Request $request REST request object
     *
     * @return WP_REST_Response|WP_Error
     */
    public function createFolder(WP_REST_Request $request)
    {
        $name     = sanitize_text_field($this->getStringParam($request, 'name'));
        $parentId = absint($this->getStringParam($request, 'parent_id'));
        $color    = sanitize_text_field($this->getStringParam($request, 'color'));
        $position = absint($this->getStringParam($request, 'position'));

        if ('' === $name) {
            return new WP_Error('missing_name', __('Folder name is required.', 'foldsnap'), ['status' => 400]);
        }

        return $this->handleRestRequest(function () use ($name, $parentId, $color, $position): WP_REST_Response {
            $folder = $this->repository->create($name, $parentId, $color, $position);

            return new WP_REST_Response(
                $this->buildMutationResponse($folder, [$parentId]),
                201
            );
        });
    }

    /**
     * PUT /foldsnap/v1/folders/{id}
     *
     * @param WP_REST_Request $request REST request object
     *
     * @return WP_REST_Response|WP_Error
     */
    public function updateFolder(WP_REST_Request $request)
    {
        $id       = absint($this->getStringParam($request, 'id'));
        $name     = sanitize_text_field($this->getStringParam($request, 'name'));
        $color    = sanitize_text_field($this->getStringParam($request, 'color'));
        $parentId = $this->getOptionalIntParam($request, 'parent_id');
        $position = $this->getOptionalIntParam($request, 'position');

        return $this->handleRestRequest(function () use ($id, $name, $parentId, $color, $position): WP_REST_Response {
            $before      = $this->repository->getById($id);
            $oldParentId = null !== $before ? $before->getParentId() : -1;

            $folder = $this->repository->update($id, $name, $parentId, $color, $position);

            $affectedParents = [$folder->getParentId()];
            if (-1 !== $oldParentId && $oldParentId !== $folder->getParentId()) {
                $affectedParents[] = $oldParentId;
            }

            return new WP_REST_Response(
                $this->buildMutationResponse($folder, $affectedParents),
                200
            );
        });
    }

    /**
     * DELETE /foldsnap/v1/folders/{id}
     *
     * @param WP_REST_Request $request REST request object
     *
     * @return WP_REST_Response|WP_Error
     */
    public function deleteFolder(WP_REST_Request $request)
    {
        $id = absint($this->getStringParam($request, 'id'));

        return $this->handleRestRequest(function () use ($id): WP_REST_Response {
            $before      = $this->repository->getById($id);
            $oldParentId = null !== $before ? $before->getParentId() : 0;

            $this->repository->delete($id);

            return new WP_REST_Response(
                [
                    'deleted'          => true,
                    'id'               => $id,
                    'affected_parents' => $this->buildAffectedParents([$oldParentId]),
                    'root_media_count' => $this->repository->getRootMediaCount(),
                    'root_total_size'  => $this->repository->getRootTotalSize(),
                ],
                200
            );
        });
    }

    /**
     * POST /foldsnap/v1/folders/{id}/media
     *
     * @param WP_REST_Request $request REST request object
     *
     * @return WP_REST_Response|WP_Error
     */
    public function assignMedia(WP_REST_Request $request)
    {
        $folderId = absint($this->getStringParam($request, 'id'));
        $mediaIds = $this->parseMediaIds($request);

        if (empty($mediaIds)) {
            return new WP_Error(
                'missing_media_ids',
                __('media_ids is required and must be a non-empty array.', 'foldsnap'),
                ['status' => 400]
            );
        }

        return $this->handleRestRequest(function () use ($folderId, $mediaIds): WP_REST_Response {
            $this->repository->assignMedia($folderId, $mediaIds);

            $folder = $this->repository->getById($folderId);
            if (null === $folder) {
                return new WP_REST_Response(['assigned' => true], 200);
            }

            return new WP_REST_Response(
                array_merge(
                    ['assigned' => true],
                    $this->buildMutationResponse($folder, [$folder->getParentId()])
                ),
                200
            );
        });
    }

    /**
     * DELETE /foldsnap/v1/folders/{id}/media
     *
     * @param WP_REST_Request $request REST request object
     *
     * @return WP_REST_Response|WP_Error
     */
    public function removeMedia(WP_REST_Request $request)
    {
        $folderId = absint($this->getStringParam($request, 'id'));
        $mediaIds = $this->parseMediaIds($request);

        if (empty($mediaIds)) {
            return new WP_Error(
                'missing_media_ids',
                __('media_ids is required and must be a non-empty array.', 'foldsnap'),
                ['status' => 400]
            );
        }

        return $this->handleRestRequest(function () use ($folderId, $mediaIds): WP_REST_Response {
            $this->repository->removeMedia($folderId, $mediaIds);

            $folder = $this->repository->getById($folderId);
            if (null === $folder) {
                return new WP_REST_Response(['removed' => true], 200);
            }

            return new WP_REST_Response(
                array_merge(
                    ['removed' => true],
                    $this->buildMutationResponse($folder, [$folder->getParentId()])
                ),
                200
            );
        });
    }

    /**
     * Build the unified mutation envelope returned by every write endpoint
     *
     * @param \FoldSnap\Models\FolderModel $folder            The folder that was created/updated/touched
     * @param int[]                        $affectedParentIds Parent IDs whose has_children may have changed
     *
     * @return array<string, mixed>
     */
    private function buildMutationResponse(\FoldSnap\Models\FolderModel $folder, array $affectedParentIds): array
    {
        $folderArray = $this->decorateFolders([$folder])[0];
        $path        = $this->repository->getPath($folder->getId());

        return [
            'folder'           => $folderArray,
            'path'             => $this->decorateFolders($path),
            'affected_parents' => $this->buildAffectedParents($affectedParentIds),
            'root_media_count' => $this->repository->getRootMediaCount(),
            'root_total_size'  => $this->repository->getRootTotalSize(),
        ];
    }
}

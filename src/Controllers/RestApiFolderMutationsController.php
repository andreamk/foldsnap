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
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @phpstan-import-type FolderArray from \FoldSnap\Models\FolderModel
 *
 * @phpstan-type MutationEnvelope array{
 *     folder: FolderArray,
 *     paths: array<array<FolderArray>>,
 *     affected_parents: array<int, array{id: int, has_children: bool}>,
 *     root: FolderArray|null
 * }
 */
final class RestApiFolderMutationsController
{
    use RestApiRequestUtils;
    use RestApiFolderPresenter;

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
        $rawName  = $request->get_param('name');
        $rawColor = $request->get_param('color');
        $name     = is_string($rawName) ? sanitize_text_field($rawName) : null;
        $color    = is_string($rawColor) ? sanitize_text_field($rawColor) : null;
        $parentId = $this->getOptionalIntParam($request, 'parent_id');
        $position = $this->getOptionalIntParam($request, 'position');

        return $this->handleRestRequest(function () use ($id, $name, $parentId, $color, $position): WP_REST_Response {
            $before      = $this->repository->getById($id);
            $oldParentId = null !== $before ? $before->getParentId() : null;

            $folder = $this->repository->update($id, $name, $parentId, $color, $position);

            $affectedParents = [$folder->getParentId()];
            if (null !== $oldParentId && $oldParentId !== $folder->getParentId()) {
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

            $rootFolder = $this->repository->getById(\FoldSnap\Models\FolderModel::ROOT_ID);

            return new WP_REST_Response(
                [
                    'deleted'          => true,
                    'id'               => $id,
                    'affected_parents' => $this->buildAffectedParents([$oldParentId]),
                    'root'             => null !== $rootFolder
                        ? $this->decorateFolders([$rootFolder])[0]
                        : null,
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
            $previousFolderIds = $this->repository->assignMedia($folderId, $mediaIds);

            $folder = $this->repository->getById($folderId);
            if (null === $folder) {
                return new WP_REST_Response(['assigned' => true], 200);
            }

            // Each origin folder also needs its ancestor totals refreshed —
            // a media item just left it and joined the destination chain.
            $affectedParents = array_merge([$folder->getParentId()], $previousFolderIds);

            return new WP_REST_Response(
                array_merge(
                    ['assigned' => true],
                    $this->buildMutationResponse($folder, $affectedParents, $previousFolderIds)
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
     * `paths` is the list of ancestor chains whose totals the client should
     * apply. The first chain is always for `$folder`; any IDs in
     * `$extraPathFolderIds` get their own chain appended (used by assignMedia
     * to refresh origin folders too). Chains keyed by an unknown / deleted
     * folder ID are skipped silently.
     *
     * @param \FoldSnap\Models\FolderModel $folder             The folder that was created/updated/touched
     * @param int[]                        $affectedParentIds  Parent IDs whose has_children may have changed
     * @param int[]                        $extraPathFolderIds Additional folder IDs whose ancestor chain
     *                                                         totals should be included in `paths`.
     *
     * @return MutationEnvelope
     */
    private function buildMutationResponse(
        \FoldSnap\Models\FolderModel $folder,
        array $affectedParentIds,
        array $extraPathFolderIds = []
    ): array {
        $folderArray = $this->decorateFolders([$folder])[0];

        $pathFolderIds = array_merge([$folder->getId()], $extraPathFolderIds);
        $pathFolderIds = array_values(array_unique(array_filter(
            $pathFolderIds,
            static fn (int $id): bool => $id > 0
        )));

        $paths = [];
        foreach ($pathFolderIds as $id) {
            $chain = $this->repository->getPath($id);
            if (! empty($chain)) {
                $paths[] = $this->decorateFolders($chain);
            }
        }

        $rootFolder = $this->repository->getById(\FoldSnap\Models\FolderModel::ROOT_ID);

        return [
            'folder'           => $folderArray,
            'paths'            => $paths,
            'affected_parents' => $this->buildAffectedParents($affectedParentIds),
            'root'             => null !== $rootFolder
                ? $this->decorateFolders([$rootFolder])[0]
                : null,
        ];
    }
}

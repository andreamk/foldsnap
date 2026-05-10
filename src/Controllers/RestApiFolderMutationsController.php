<?php

/**
 * REST controller for folder write operations.
 *
 * Owns create / update / delete on folders plus media assignment, returning
 * a uniform mutation envelope. Routes are registered by RestApiController.
 *
 * See docs/02_1_API_rest-endpoints.md for the envelope shape and contract.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Controllers;

use FoldSnap\Models\FolderModel;
use FoldSnap\Services\FolderRepository;
use FoldSnap\Services\MediaFolderAssignmentService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @phpstan-import-type FolderArray from FolderModel
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
    private MediaFolderAssignmentService $assignments;

    /**
     * @param FolderRepository             $repository  Folder CRUD repository.
     * @param MediaFolderAssignmentService $assignments Media ↔ folder writer.
     */
    public function __construct(
        FolderRepository $repository,
        MediaFolderAssignmentService $assignments
    ) {
        $this->repository  = $repository;
        $this->assignments = $assignments;
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
        /** @var string */
        $name = $request['name'];
        /** @var int */
        $parentId = $request['parent_id'];
        /** @var string */
        $color = $request['color'];
        /** @var int */
        $position = $request['position'];

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
        /** @var int */
        $id = $request['id'];
        /** @var ?string */
        $name = $request['name'] ?? null;
        /** @var ?string */
        $color = $request['color'] ?? null;
        /** @var ?int */
        $parentId = $request['parent_id'] ?? null;
        /** @var ?int */
        $position = $request['position'] ?? null;

        return $this->handleRestRequest(function () use ($id, $name, $parentId, $color, $position): WP_REST_Response {
            $before      = $this->repository->getById($id);
            $oldParentId = null !== $before ? $before->getParentId() : null;

            $folder = $this->repository->update($id, $name, $parentId, $color, $position);

            $affectedParents    = [$folder->getParentId()];
            $extraPathFolderIds = [];
            if (null !== $oldParentId && $oldParentId !== $folder->getParentId()) {
                $affectedParents[] = $oldParentId;
                // Reparent: include the old parent's ancestor chain in `paths`
                // so the client refreshes counts/sizes upstream of the source.
                $extraPathFolderIds[] = $oldParentId;
            }

            return new WP_REST_Response(
                $this->buildMutationResponse($folder, $affectedParents, $extraPathFolderIds),
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
        /** @var int */
        $id = $request['id'];

        return $this->handleRestRequest(function () use ($id): WP_REST_Response {
            $before      = $this->repository->getById($id);
            $oldParentId = null !== $before ? $before->getParentId() : 0;

            $this->repository->delete($id);

            $rootFolder = $this->repository->getById(FolderModel::ROOT_ID);

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
        /** @var int */
        $folderId = $request['id'];
        $mediaIds = $this->parseMediaIds($request);

        if (empty($mediaIds)) {
            return new WP_Error(
                'missing_media_ids',
                __('media_ids is required and must be a non-empty array.', 'foldsnap'),
                ['status' => 400]
            );
        }

        return $this->handleRestRequest(function () use ($folderId, $mediaIds): WP_REST_Response {
            $previousFolderIds = $this->assignments->assign($folderId, $mediaIds);

            $folder = $this->repository->getById($folderId);
            if (null === $folder) {
                return new WP_REST_Response(['assigned' => true], 200);
            }

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
        /** @var int */
        $folderId = $request['id'];
        $mediaIds = $this->parseMediaIds($request);

        if (empty($mediaIds)) {
            return new WP_Error(
                'missing_media_ids',
                __('media_ids is required and must be a non-empty array.', 'foldsnap'),
                ['status' => 400]
            );
        }

        return $this->handleRestRequest(function () use ($folderId, $mediaIds): WP_REST_Response {
            $this->assignments->remove($folderId, $mediaIds);

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
     * Build the unified mutation envelope.
     *
     * `paths` always starts with `$folder`'s ancestor chain; each ID in
     * `$extraPathFolderIds` gets its own chain appended. Chains for unknown
     * or deleted folder IDs are skipped silently.
     *
     * @param FolderModel $folder             The folder that was created/updated/touched.
     * @param int[]       $affectedParentIds  Parent IDs whose has_children may have changed.
     * @param int[]       $extraPathFolderIds Additional folder IDs whose ancestor chain
     *                                        should be included in `paths`.
     *
     * @return MutationEnvelope
     */
    private function buildMutationResponse(
        FolderModel $folder,
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

        $rootFolder = $this->repository->getById(FolderModel::ROOT_ID);

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

<?php // phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

/**
 * REST API controller for folder management
 *
 * Registers and handles all REST API endpoints under the foldsnap/v1 namespace.
 * Provides CRUD operations for folders and media assignment/removal.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Controllers;

defined('ABSPATH') || exit;

use Exception;
use FoldSnap\Services\FolderRepository;
use FoldSnap\Services\TaxonomyService;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestApiController
{
    private const REST_NAMESPACE = 'foldsnap/v1';

    /** @var self|null */
    private static ?self $instance = null;

    private FolderRepository $repository;

    /**
     * Get singleton instance
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self(new FolderRepository());
        }

        return self::$instance;
    }

    /**
     * Constructor
     *
     * @param FolderRepository $repository Folder repository instance
     */
    private function __construct(FolderRepository $repository)
    {
        $this->repository = $repository;
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function registerRoutes(): void
    {
        register_rest_route(
            self::REST_NAMESPACE,
            '/folders',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [
                        $this,
                        'getFolders',
                    ],
                    'permission_callback' => [
                        $this,
                        'checkPermission',
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [
                        $this,
                        'createFolder',
                    ],
                    'permission_callback' => [
                        $this,
                        'checkPermission',
                    ],
                    'args'                => [
                        'name'      => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'parent_id' => [
                            'type'              => 'integer',
                            'default'           => 0,
                            'sanitize_callback' => 'absint',
                        ],
                        'color'     => [
                            'type'              => 'string',
                            'default'           => '',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'position'  => [
                            'type'              => 'integer',
                            'default'           => 0,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/folders/(?P<id>\d+)',
            [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [
                        $this,
                        'updateFolder',
                    ],
                    'permission_callback' => [
                        $this,
                        'checkPermission',
                    ],
                    'args'                => [
                        'id'        => [
                            'required'          => true,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'name'      => [
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'parent_id' => [
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'color'     => [
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'position'  => [
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [
                        $this,
                        'deleteFolder',
                    ],
                    'permission_callback' => [
                        $this,
                        'checkPermission',
                    ],
                    'args'                => [
                        'id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/folders/(?P<id>\d+)/media',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [
                        $this,
                        'assignMedia',
                    ],
                    'permission_callback' => [
                        $this,
                        'checkPermission',
                    ],
                    'args'                => [
                        'id'        => [
                            'required'          => true,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'media_ids' => [
                            'required' => true,
                            'type'     => 'array',
                            'items'    => ['type' => 'integer'],
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [
                        $this,
                        'removeMedia',
                    ],
                    'permission_callback' => [
                        $this,
                        'checkPermission',
                    ],
                    'args'                => [
                        'id'        => [
                            'required'          => true,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'media_ids' => [
                            'required' => true,
                            'type'     => 'array',
                            'items'    => ['type' => 'integer'],
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/media',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [
                        $this,
                        'getMedia',
                    ],
                    'permission_callback' => [
                        $this,
                        'checkPermission',
                    ],
                    'args'                => [
                        'folder_id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'page'      => [
                            'type'              => 'integer',
                            'default'           => 1,
                            'sanitize_callback' => 'absint',
                        ],
                        'per_page'  => [
                            'type'              => 'integer',
                            'default'           => 40,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Check if the current user has permission to manage media
     *
     * @return bool
     */
    public function checkPermission(): bool
    {
        return current_user_can('upload_files');
    }

    /**
     * GET /foldsnap/v1/folders
     *
     * @return WP_REST_Response
     */
    public function getFolders(): WP_REST_Response
    {
        $tree = $this->repository->getTree();

        $foldersArray = array_map(
            static function ($folder): array {
                return $folder->toArray();
            },
            $tree
        );

        return new WP_REST_Response(
            [
                'folders'          => $foldersArray,
                'root_media_count' => $this->repository->getRootMediaCount(),
                'root_total_size'  => $this->repository->getRootTotalSize(),
            ],
            200
        );
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
            return new WP_Error(
                'missing_name',
                __('Folder name is required.', 'foldsnap'),
                ['status' => 400]
            );
        }

        return $this->handleRestRequest(function () use ($name, $parentId, $color, $position): WP_REST_Response {
            $folder = $this->repository->create($name, $parentId, $color, $position);

            return new WP_REST_Response($folder->toArray(), 201);
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
            $folder = $this->repository->update($id, $name, $parentId, $color, $position);

            return new WP_REST_Response($folder->toArray(), 200);
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
            $this->repository->delete($id);

            return new WP_REST_Response(['deleted' => true], 200);
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

            return new WP_REST_Response(['assigned' => true], 200);
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

            return new WP_REST_Response(['removed' => true], 200);
        });
    }

    /**
     * GET /foldsnap/v1/media
     *
     * @param WP_REST_Request $request REST request object
     *
     * @return WP_REST_Response|WP_Error
     */
    public function getMedia(WP_REST_Request $request)
    {
        $rawFolderId = $request->get_param('folder_id');
        if (null === $rawFolderId) {
            return new WP_Error(
                'missing_folder_id',
                __('folder_id parameter is required.', 'foldsnap'),
                ['status' => 400]
            );
        }

        $folderId   = absint($this->getStringParam($request, 'folder_id'));
        $page       = max(1, absint($this->getStringParam($request, 'page')));
        $rawPerPage = $this->getStringParam($request, 'per_page');
        $perPage    = '' === $rawPerPage ? 40 : max(1, min(100, absint($rawPerPage)));

        $queryArgs = [
            'post_type'      => TaxonomyService::POST_TYPE,
            'post_status'    => 'inherit',
            'posts_per_page' => $perPage,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
        $queryArgs['tax_query'] = TaxonomyService::buildFolderTaxQuery($folderId);

        $query = new \WP_Query($queryArgs);

        /** @var int[] $postIds */
        $postIds = wp_list_pluck($query->posts, 'ID');
        if (! empty($postIds)) {
            update_meta_cache('post', $postIds);
        }

        $media = [];
        foreach ($query->posts as $post) {
            /** @var \WP_Post $post */
            $media[] = $this->formatMediaItem($post);
        }

        $response = new WP_REST_Response(
            [
                'media'       => $media,
                'total'       => (int) $query->found_posts,
                'total_pages' => (int) $query->max_num_pages,
            ],
            200
        );

        $response->header('X-WP-Total', (string) $query->found_posts);
        $response->header('X-WP-TotalPages', (string) $query->max_num_pages);

        return $response;
    }

    /**
     * Format a WP_Post attachment into a media item array
     *
     * @param \WP_Post $post Attachment post object
     *
     * @return array<string, mixed>
     */
    private function formatMediaItem(\WP_Post $post): array
    {
        $metadata = wp_get_attachment_metadata($post->ID);
        $fileSize = 0;
        if (is_array($metadata) && isset($metadata['filesize']) && is_numeric($metadata['filesize'])) {
            $fileSize = (int) $metadata['filesize'];
        }

        $thumbnailUrl = wp_get_attachment_image_url($post->ID, 'thumbnail');

        return [
            'id'            => $post->ID,
            'title'         => get_the_title($post),
            'filename'      => wp_basename(get_attached_file($post->ID) ?: ''),
            'thumbnail_url' => $thumbnailUrl ?: '',
            'url'           => (string) wp_get_attachment_url($post->ID),
            'file_size'     => $fileSize,
            'mime_type'     => $post->post_mime_type,
            'date'          => $post->post_date,
        ];
    }

    /**
     * Execute a REST callback with standardized exception handling
     *
     * @param callable(): WP_REST_Response $callback Business logic returning a response
     *
     * @return WP_REST_Response|WP_Error
     */
    private function handleRestRequest(callable $callback)
    {
        try {
            return $callback();
        } catch (InvalidArgumentException $e) {
            return new WP_Error(
                'invalid_argument',
                $e->getMessage(),
                ['status' => 400]
            );
        } catch (Exception $e) {
            return new WP_Error(
                'server_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Parse media_ids from request body
     *
     * @param WP_REST_Request $request REST request object
     *
     * @return int[]
     */
    private function parseMediaIds(WP_REST_Request $request): array
    {
        $rawIds = $request->get_param('media_ids');

        if (! is_array($rawIds)) {
            return [];
        }

        $intIds = [];
        foreach ($rawIds as $rawId) {
            $intIds[] = absint(is_scalar($rawId) ? (string) $rawId : '');
        }

        return array_values(
            array_filter(
                $intIds,
                static function (int $id): bool {
                    return $id > 0;
                }
            )
        );
    }

    /**
     * Get an optional integer parameter from request, returning -1 (sentinel) if not provided
     *
     * @param WP_REST_Request $request   REST request object
     * @param string          $paramName Parameter name
     *
     * @return int Parameter value, or -1 if not provided
     */
    private function getOptionalIntParam(WP_REST_Request $request, string $paramName): int
    {
        $value = $request->get_param($paramName);

        if (null === $value) {
            return -1;
        }

        return absint(is_scalar($value) ? (string) $value : '');
    }

    /**
     * Get a string parameter from request, safely handling mixed return type
     *
     * @param WP_REST_Request $request   REST request object
     * @param string          $paramName Parameter name
     *
     * @return string Parameter value as string, or empty string if not provided/not scalar
     */
    private function getStringParam(WP_REST_Request $request, string $paramName): string
    {
        $value = $request->get_param($paramName);

        if (null === $value || ! is_scalar($value)) {
            return '';
        }

        return (string) $value;
    }
}

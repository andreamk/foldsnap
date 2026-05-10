<?php

/**
 * Top-level REST controller for the foldsnap/v1 namespace
 *
 * Owns route registration and read endpoints (folder discovery via
 * children/search modes, folder path lookup, paginated media listing).
 * Write endpoints (create / update / delete / media assign / media remove)
 * are delegated to RestApiFolderMutationsController, which is registered
 * here as the callback target.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Controllers;

use FoldSnap\Models\FolderModel;
use FoldSnap\Services\CountersRecalculator;
use FoldSnap\Services\Database;
use FoldSnap\Services\FolderCounterService;
use FoldSnap\Services\FolderNameSanitizer;
use FoldSnap\Services\FolderRepository;
use FoldSnap\Services\MediaFolderAssignmentService;
use FoldSnap\Services\TaxonomyService;
use FoldSnap\Utils\Sanitize;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * @phpstan-import-type FolderArray from FolderModel
 */
final class RestApiController
{
    use RestApiRequestUtils;
    use RestApiFolderPresenter;

    private const REST_NAMESPACE = 'foldsnap/v1';

    /** @var int Default page size for the children-fetch mode of /folders */
    public const FOLDERS_PER_PAGE = 100;

    /** @var int Default page size for the search mode of /folders */
    public const SEARCH_PER_PAGE = 50;

    /** @var int Default page size for /media */
    public const MEDIA_PER_PAGE = 40;

    /** @var int Hard upper bound for the children-fetch mode */
    public const FOLDERS_MAX_PER_PAGE = 200;

    /** @var int Hard upper bound for search and /media */
    public const ITEMS_MAX_PER_PAGE = 100;

    /** @var self|null */
    private static ?self $instance = null;

    private FolderRepository $repository;
    private RestApiFolderMutationsController $mutations;

    /**
     * Get singleton instance
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (null === self::$instance) {
            $counters       = new FolderCounterService();
            $repository     = new FolderRepository(new FolderNameSanitizer(), $counters);
            $assignments    = new MediaFolderAssignmentService($repository, $counters);
            $mutations      = new RestApiFolderMutationsController($repository, $assignments);
            self::$instance = new self($repository, $mutations);
        }

        return self::$instance;
    }

    /**
     * Constructor
     *
     * @param FolderRepository                 $repository Folder repository instance
     * @param RestApiFolderMutationsController $mutations  Write-endpoints controller
     */
    private function __construct(
        FolderRepository $repository,
        RestApiFolderMutationsController $mutations
    ) {
        $this->repository = $repository;
        $this->mutations  = $mutations;
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
                    'args'                => [
                        'search'     => [
                            'type'              => 'string',
                            'default'           => '',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'parent_ids' => [
                            'type'    => 'array',
                            'default' => [],
                            'items'   => ['type' => 'integer'],
                        ],
                        'page'       => [
                            'type'              => 'integer',
                            'default'           => 1,
                            'sanitize_callback' => 'absint',
                        ],
                        'per_page'   => [
                            'type'              => 'integer',
                            'default'           => self::FOLDERS_PER_PAGE,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [
                        $this->mutations,
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
                        $this->mutations,
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
                        $this->mutations,
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
            '/folders/(?P<id>\d+)/path',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [
                    $this,
                    'getFolderPath',
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
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/folders/(?P<id>\d+)/media',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [
                        $this->mutations,
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
                        $this->mutations,
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
            '/folders/recalculate',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [
                    $this,
                    'recalculate',
                ],
                'permission_callback' => [
                    $this,
                    'checkAdminPermission',
                ],
                'args'                => [
                    'limit' => [
                        'type'              => 'integer',
                        'default'           => CountersRecalculator::DEFAULT_LIMIT,
                        'sanitize_callback' => 'absint',
                    ],
                    'reset' => [
                        'type'    => 'boolean',
                        'default' => false,
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
                            'default'           => self::MEDIA_PER_PAGE,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Permission callback shared by every endpoint
     *
     * @return bool
     */
    public function checkPermission(): bool
    {
        return current_user_can('upload_files');
    }

    /**
     * Permission callback for admin-only endpoints (recalculate)
     *
     * @return bool
     */
    public function checkAdminPermission(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * POST /foldsnap/v1/folders/recalculate
     *
     * Runs one chunk of the bottom-up counter recalculate. With `reset=true`
     * the existing stack is cleared first, forcing a full rebuild on the
     * next call. Returns the same envelope as CountersRecalculator.
     *
     * @param WP_REST_Request $request REST request object.
     *
     * @return WP_REST_Response
     */
    public function recalculate(WP_REST_Request $request): WP_REST_Response
    {
        /** @var int */
        $limit = $request['limit'];
        /** @var bool */
        $reset        = $request['reset'];
        $recalculator = new CountersRecalculator(new FolderCounterService());

        if ($reset) {
            $recalculator->reset();
        }

        $result = $recalculator->processChunk($limit);

        return new WP_REST_Response($result, 200);
    }

    /**
     * GET /foldsnap/v1/folders
     *
     * Two mutually exclusive modes:
     * - children fetch (default): ?parent_ids[]=0&parent_ids[]=42&page=1&per_page=100
     * - search:                   ?search=foo&page=1&per_page=50
     *
     * @param WP_REST_Request $request REST request object
     *
     * @return WP_REST_Response
     */
    public function getFolders(WP_REST_Request $request): WP_REST_Response
    {
        /** @var string */
        $search = $request['search'];
        $search = trim($search);

        if ('' !== $search) {
            return $this->getFoldersSearch($request, $search);
        }

        return $this->getFoldersChildren($request);
    }

    /**
     * GET /foldsnap/v1/folders/{id}/path
     *
     * Returns the ancestor chain (root → target) decorated with totals and
     * has_children, or `{ path: [] }` if the target does not exist.
     *
     * @param WP_REST_Request $request REST request object
     *
     * @return WP_REST_Response|WP_Error
     */
    public function getFolderPath(WP_REST_Request $request)
    {
        /** @var int */
        $id = $request['id'];

        return $this->handleRestRequest(function () use ($id): WP_REST_Response {
            $path = $this->repository->getPath($id);

            if (empty($path)) {
                return new WP_REST_Response(['path' => []], 200);
            }

            return new WP_REST_Response(['path' => $this->decorateFolders($path)], 200);
        });
    }

    /**
     * GET /foldsnap/v1/media
     *
     * @param WP_REST_Request $request REST request object
     *
     * @return WP_REST_Response
     */
    public function getMedia(WP_REST_Request $request): WP_REST_Response
    {
        /** @var int */
        $folderId = $request['folder_id'];
        /** @var int */
        $page = max(1, $request['page']);
        /** @var int */
        $perPage = max(1, min(self::ITEMS_MAX_PER_PAGE, $request['per_page']));

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
     * Children-fetch mode for GET /folders.
     *
     * Returns paginated direct children for the requested parent IDs as a
     * flat list, plus the refreshed Root folder.
     *
     * @param WP_REST_Request $request REST request object
     *
     * @return WP_REST_Response
     */
    private function getFoldersChildren(WP_REST_Request $request): WP_REST_Response
    {
        /** @var array<int, mixed> $rawParentIds */
        $rawParentIds = $request['parent_ids'];
        $parentIds    = array_values(array_unique(array_map(
            static fn ($v): int => Sanitize::toInt($v),
            $rawParentIds
        )));

        /** @var int */
        $page = max(1, $request['page']);
        /** @var int */
        $perPage = max(1, min(self::FOLDERS_MAX_PER_PAGE, $request['per_page']));

        if (empty($parentIds)) {
            $parentIds = [0];
        }

        $byParent = $this->repository->getByParents($parentIds);

        // Aggregate children across all requested parents into a flat list;
        // each FolderModel carries its parent_id so the client can rebuild
        // the children-by-parent map. Pagination is applied to the combined
        // list, ordered as the repository returns each parent's children.
        /** @var FolderModel[] $allChildren */
        $allChildren = [];
        foreach ($parentIds as $parentId) {
            foreach ($byParent[$parentId] ?? [] as $child) {
                $allChildren[] = $child;
            }
        }

        $total      = count($allChildren);
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;
        $offset     = ($page - 1) * $perPage;
        $pageSlice  = array_slice($allChildren, $offset, $perPage);

        $rootFolder = $this->repository->getById(FolderModel::ROOT_ID);

        $response = new WP_REST_Response(
            [
                'mode'                 => 'children',
                'folders'              => $this->decorateFolders($pageSlice),
                'requested_parent_ids' => $parentIds,
                'total'                => $total,
                'total_pages'          => $totalPages,
                'root'                 => null !== $rootFolder
                    ? $this->decorateFolders([$rootFolder])[0]
                    : null,
            ],
            200
        );

        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) $totalPages);

        return $response;
    }

    /**
     * Search mode for GET /folders
     *
     * @param WP_REST_Request $request REST request
     * @param string          $search  Trimmed search query
     *
     * @return WP_REST_Response
     */
    private function getFoldersSearch(WP_REST_Request $request, string $search): WP_REST_Response
    {
        /** @var int */
        $page = max(1, $request['page']);
        /** @var int */
        $perPage = max(1, min(self::ITEMS_MAX_PER_PAGE, $request['per_page']));

        $result  = $this->repository->search($search, $page, $perPage);
        $folders = $this->decorateFolders($result['folders']);

        $results = [];
        foreach ($result['folders'] as $index => $folder) {
            // Breadcrumb = ancestors only, so drop the folder itself (last element of getPath).
            $ancestors = array_slice($this->repository->getPath($folder->getId()), 0, -1);

            $results[] = [
                'folder'     => $folders[$index],
                'breadcrumb' => array_map(
                    static function (FolderModel $f): array {
                        return [
                            'id'      => $f->getId(),
                            'name'    => $f->getName(),
                            'is_root' => $f->isRoot(),
                        ];
                    },
                    $ancestors
                ),
            ];
        }

        $response = new WP_REST_Response(
            [
                'mode'        => 'search',
                'query'       => $search,
                'results'     => $results,
                'total'       => $result['total'],
                'total_pages' => $result['total_pages'],
            ],
            200
        );

        $response->header('X-WP-Total', (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) $result['total_pages']);

        return $response;
    }

    /**
     * Format a WP_Post attachment into a media item array
     *
     * @param \WP_Post $post Attachment post object
     *
     * @return array{id: int, title: string, filename: string, thumbnail_url: string, url: string, file_size: int, mime_type: string, date: string}
     */
    private function formatMediaItem(\WP_Post $post): array
    {
        $fileSize     = Database::extractFileSize(wp_get_attachment_metadata($post->ID));
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
}

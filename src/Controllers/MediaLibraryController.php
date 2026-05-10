<?php

/**
 * Media Library controller
 *
 * Enqueues FoldSnap assets exclusively on the Media Library screen (upload.php).
 * The sidebar container is created and positioned in the DOM by the JS bundle.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Controllers;

use FoldSnap\Services\TaxonomyService;
use FoldSnap\Services\UserPreferencesService;
use FoldSnap\Utils\SanitizeInput;

final class MediaLibraryController
{
    /** @var ?self */
    private static ?self $instance = null;

    /**
     * Return the singleton instance and register hooks on first call.
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Class constructor — registers WordPress hooks.
     */
    private function __construct()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_filter('ajax_query_attachments_args', [$this, 'filterAttachmentsByFolder']);
        add_action('pre_get_posts', [$this, 'filterListModeByFolder']);
    }

    /**
     * Resolve the current media library mode (grid or list).
     *
     * URL `mode` parameter takes priority, then the per-user option,
     * defaulting to 'grid'.
     *
     * @return string 'grid' or 'list'
     */
    public static function getMediaMode(): string
    {
        $urlMode = SanitizeInput::str(INPUT_GET, 'mode');
        if ('grid' === $urlMode || 'list' === $urlMode) {
            return $urlMode;
        }

        $userMode = get_user_option('media_library_mode', get_current_user_id());

        return is_string($userMode) && '' !== $userMode ? $userMode : 'grid';
    }

    /**
     * Add a folder tax_query to the grid AJAX attachment query.
     *
     * Hooked on `ajax_query_attachments_args`. No-op when
     * `$_REQUEST['query']['foldsnap_folder_id']` is absent.
     *
     * @param array<string, mixed> $query WP_Query args for attachments.
     *
     * @return array<string, mixed> Modified query args.
     */
    public function filterAttachmentsByFolder(array $query): array
    {
        $folderId = SanitizeInput::toInt(
            SanitizeInput::INPUT_REQUEST,
            [
                'query',
                'foldsnap_folder_id',
            ],
            -1
        );

        if ($folderId >= 0) {
            $query['tax_query'] = TaxonomyService::buildFolderTaxQuery($folderId); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
        }
        return $query;
    }

    /**
     * Add a folder tax_query to the main attachment query in list mode.
     *
     * Hooked on `pre_get_posts`. Only runs for the admin main query when
     * the post type is `attachment` and `foldsnap_folder_id` is in
     * `$_GET`.
     *
     * @param \WP_Query $query The current query.
     *
     * @return void
     */
    public function filterListModeByFolder(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query() || 'attachment' !== $query->get('post_type')) {
            return;
        }

        // Only in list mode — grid mode loads via AJAX (filterAttachmentsByFolder).
        if ('list' !== self::getMediaMode()) {
            return;
        }

        $folderId = SanitizeInput::toInt(INPUT_GET, 'foldsnap_folder_id', -1);

        if ($folderId >= 0) {
            $query->set('tax_query', TaxonomyService::buildFolderTaxQuery($folderId)); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
        }
    }

    /**
     * Enqueue scripts and styles only on the Media Library screen.
     *
     * @return void
     */
    public function enqueueAssets(): void
    {
        $screen = get_current_screen();
        if (null === $screen || 'upload' !== $screen->id) {
            return;
        }

        $assetFile = FOLDSNAP_PATH . '/assets/js/foldsnap-admin.asset.php';
        if (!file_exists($assetFile)) {
            return;
        }

        /** @var array{dependencies: string[], version: string} $asset */
        $asset = require $assetFile;

        wp_enqueue_script(
            'foldsnap-admin',
            FOLDSNAP_PLUGIN_URL . '/assets/js/foldsnap-admin.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_set_script_translations('foldsnap-admin', 'foldsnap', FOLDSNAP_PATH . '/languages');

        $foldsnapData = [
            'restUrl'           => rest_url('foldsnap/v1/'),
            'mediaMode'         => self::getMediaMode(),
            'preferences'       => (new UserPreferencesService())->getAll(get_current_user_id()),
            'sidebarWidthMin'   => 200,
            'sidebarWidthMax'   => 600,
            'foldersPerPage'    => RestApiController::FOLDERS_PER_PAGE,
            'foldersMaxPerPage' => RestApiController::FOLDERS_MAX_PER_PAGE,
            'searchPerPage'     => RestApiController::SEARCH_PER_PAGE,
            'mediaPerPage'      => RestApiController::MEDIA_PER_PAGE,
        ];

        wp_add_inline_script(
            'foldsnap-admin',
            'var foldsnap_data = ' . wp_json_encode($foldsnapData) . ';',
            'before'
        );

        wp_enqueue_script(
            'foldsnap-dragdrop',
            FOLDSNAP_PLUGIN_URL . '/assets/js/foldsnap-dragdrop.js',
            [
                'jquery',
                'jquery-ui-draggable',
                'jquery-ui-droppable',
                'wp-i18n',
                'foldsnap-admin',
            ],
            FOLDSNAP_VERSION,
            true
        );

        wp_set_script_translations('foldsnap-dragdrop', 'foldsnap', FOLDSNAP_PATH . '/languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            'foldsnap-admin',
            FOLDSNAP_PLUGIN_URL . '/assets/css/foldsnap-admin.css',
            [],
            (string) filemtime(FOLDSNAP_PATH . '/assets/css/foldsnap-admin.css')
        );
    }
}

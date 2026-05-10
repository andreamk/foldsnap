<?php

/**
 * Plugin bootstrap
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Core;

use FoldSnap\Controllers\MediaLibraryController;
use FoldSnap\Controllers\RestApiController;
use FoldSnap\Controllers\UserPreferencesRestController;
use FoldSnap\Core\Controllers\ControllersManager;
use FoldSnap\Services\AttachmentLifecycleService;
use FoldSnap\Services\CountersRecalculator;
use FoldSnap\Services\FolderCounterService;
use FoldSnap\Services\TaxonomyService;
use FoldSnap\Services\UserPreferencesService;

final class Bootstrap
{
    /**
     * Initialize the plugin
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('init', [self::class, 'onInit']);
    }

    /**
     * Run plugin initialization on the WordPress init hook
     *
     * @return void
     */
    public static function onInit(): void
    {
        TaxonomyService::register();
        RestApiController::getInstance();
        MediaLibraryController::getInstance();

        // Per-user UI preferences (sidebar state, settings).
        new UserPreferencesRestController(new UserPreferencesService());

        // Folder counter incremental updates: hook attachment lifecycle.
        $counters  = new FolderCounterService();
        $lifecycle = new AttachmentLifecycleService($counters);
        $lifecycle->register();

        // Cron handler for chunked recalculate.
        add_action(CountersRecalculator::CRON_ACTION, [self::class, 'runRecalculateChunk']);

        // First-boot bootstrap of folder counters: schedule the initial
        // recalculate if it has never run on this site (needed even on a
        // fresh install whose Media Library is already populated).
        // Subsequent chunks self-reschedule until done.
        $initialized = get_option(CountersRecalculator::OPT_INITIALIZED, '');
        if ('1' !== $initialized) {
            if (! wp_next_scheduled(CountersRecalculator::CRON_ACTION)) {
                wp_schedule_single_event(time() + 5, CountersRecalculator::CRON_ACTION);
            }
        }

        if (is_admin()) {
            ControllersManager::getInstance();

            $menuHook = is_multisite() ? 'network_admin_menu' : 'admin_menu';
            add_action($menuHook, [self::class, 'menu']);
        }
    }

    /**
     * Cron callback: process one recalculate chunk and re-schedule if needed
     *
     * @return void
     */
    public static function runRecalculateChunk(): void
    {
        $recalculator = new CountersRecalculator(new FolderCounterService());
        $result       = $recalculator->processChunk();

        if (! $result['done']) {
            wp_schedule_single_event(time() + 30, CountersRecalculator::CRON_ACTION);
        }
    }

    /**
     * Register admin menu pages
     *
     * @return void
     */
    public static function menu(): void
    {
        ControllersManager::getInstance()->registerMenu();
    }
}

<?php

/**
 * Uninstall handler
 *
 * Cleans up all plugin data from the database when the plugin is deleted.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Core;

final class Uninstall
{
    public const OPTIONS_PREFIX    = 'foldsnap_opt_';
    public const TRANSIENTS_PREFIX = 'foldsnap_';

    /**
     * Run the uninstall process, handling multisite if needed.
     *
     * @return void
     */
    public static function run(): void
    {
        try {
            if (is_multisite()) {
                $sites = get_sites(['fields' => 'ids']);

                foreach ($sites as $siteId) {
                    switch_to_blog((int) $siteId);
                    self::cleanSite();
                    restore_current_blog();
                }
            } else {
                self::cleanSite();
            }
        } catch (\Throwable $t) {
            wp_trigger_error(self::class . '::run', $t->getMessage(), E_USER_WARNING);
        }
    }

    /**
     * Clean up plugin data for the current site.
     *
     * @return void
     */
    private static function cleanSite(): void
    {
        self::deleteOptions();
        self::deleteTransients();
        wp_cache_flush();
    }

    /**
     * Delete all plugin options.
     *
     * @return void
     */
    private static function deleteOptions(): void
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        /** @var string[] $optionNames */
        // WordPress Options API has no function to search options by prefix (LIKE pattern).
        // Direct query is the only way. Caching is unnecessary since results are immediately deleted.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $optionNames = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT option_name FROM %i WHERE option_name LIKE %s',
                $wpdb->options,
                $wpdb->esc_like(self::OPTIONS_PREFIX) . '%'
            )
        );

        foreach ($optionNames as $optionName) {
            delete_option((string) $optionName);
        }
    }

    /**
     * Delete all plugin transients.
     *
     * @return void
     */
    private static function deleteTransients(): void
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        /** @var string[] $transientNames */
        // WordPress Transients API has no function to search transients by prefix (LIKE pattern).
        // Direct query is the only way. Caching is unnecessary since results are immediately deleted.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $transientNames = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
                $wpdb->options,
                $wpdb->esc_like('_transient_' . self::TRANSIENTS_PREFIX) . '%',
                $wpdb->esc_like('_transient_timeout_' . self::TRANSIENTS_PREFIX) . '%'
            )
        );

        foreach ($transientNames as $transientName) {
            delete_option((string) $transientName);
        }
    }
}

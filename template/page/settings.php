<?php

/**
 * FoldSnap settings page template
 *
 * Internal tooling — exposes maintenance actions for the plugin.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @var \FoldSnap\Core\Views\TplMng $tplMng */
?>
<div class="foldsnap-settings">
    <h1><?php echo esc_html__('FoldSnap Settings', 'foldsnap'); ?></h1>

    <div class="foldsnap-settings__section">
        <h2><?php echo esc_html__('Maintenance', 'foldsnap'); ?></h2>

        <p>
            <?php echo esc_html__(
                'Recompute every folder count and size from scratch. 
                Use this if the sidebar shows stale numbers after a manual database change or a failed mutation.',
                'foldsnap'
            ); ?>
        </p>

        <p>
            <button
                type="button"
                class="button button-primary"
                id="foldsnap-recount-btn"
            >
                <?php echo esc_html__('Recount folders', 'foldsnap'); ?>
            </button>
            <span
                id="foldsnap-recount-status"
                class="foldsnap-settings__status"
                role="status"
                aria-live="polite"
            ></span>
        </p>
    </div>
</div>

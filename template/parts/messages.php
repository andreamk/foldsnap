<?php

/**
 * Messages template (success/error notifications)
 *
 * @package FoldSnap
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @var \FoldSnap\Core\Views\TplMng $tplMng */

$successMessage = $tplMng->getDataValueString('successMessage');
$errorMessage   = $tplMng->getDataValueString('errorMessage');

if (strlen($successMessage) > 0) : ?>
    <div class="notice notice-success is-dismissible foldsnap-notice">
        <p><?php echo wp_kses_post($successMessage); ?></p>
    </div>
<?php endif;

if (strlen($errorMessage) > 0) : ?>
    <div class="notice notice-error is-dismissible foldsnap-notice">
        <p><?php echo wp_kses_post($errorMessage); ?></p>
    </div>
<?php endif;

<?php

/**
 * Admin body header template
 *
 * @package FoldSnap
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @var \FoldSnap\Core\Views\TplMng $tplMng */

$pageTitle = $tplMng->getDataValueString('pageTitle');

/** @var \FoldSnap\Core\Controllers\SubMenuItem[] $menuItemsL2 */
$menuItemsL2 = $tplMng->getDataValueArray('menuItemsL2', []);
?>
<h1><?php echo esc_html($pageTitle); ?></h1>
<?php if (count($menuItemsL2) > 1) : ?>
    <nav class="nav-tab-wrapper foldsnap-tabs-l2">
        <?php foreach ($menuItemsL2 as $item) : ?>
            <a href="<?php echo esc_url($item->link); ?>"
               class="nav-tab <?php echo $item->active ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($item->label); ?>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif;

<?php

/**
 * Level 3 tabs menu template
 *
 * @package FoldSnap
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * @var \FoldSnap\Core\Views\TplMng $tplMng
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the TplMng::render() include scope, not actually global.
 */

/** @var \FoldSnap\Core\Controllers\SubMenuItem[] $menuItemsL3 */
$menuItemsL3 = $tplMng->getDataValueArray('menuItemsL3', []);

if (count($menuItemsL3) === 0) {
    return;
}
?>
<ul class="subsubsub foldsnap-tabs-l3">
    <?php foreach ($menuItemsL3 as $index => $item) : ?>
        <li>
            <a href="<?php echo esc_url($item->link); ?>"
               class="<?php echo $item->active ? 'current' : ''; ?>">
                <?php echo esc_html($item->label); ?>
            </a>
            <?php if ($index < count($menuItemsL3) - 1) : ?>
                |
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
<br class="clear">

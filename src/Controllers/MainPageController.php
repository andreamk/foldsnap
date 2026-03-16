<?php

/**
 * Main page controller
 *
 * Registers a "FoldSnap" sub-menu entry under Media. Currently acts as a
 * placeholder for future plugin settings. All media-library asset injection
 * is handled by MediaLibraryController.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Controllers;

use FoldSnap\Core\Controllers\AbstractMenuPageController;
use FoldSnap\Core\Controllers\ControllersManager;

final class MainPageController extends AbstractMenuPageController
{
    /**
     * Class constructor
     */
    protected function __construct()
    {
        $this->pageSlug     = ControllersManager::MAIN_MENU_SLUG;
        $this->pageTitle    = __('FoldSnap', 'foldsnap');
        $this->menuLabel    = __('FoldSnap', 'foldsnap');
        $this->capatibility = 'upload_files';
        $this->menuPos      = 10;
        $this->parentSlug   = 'upload.php';
    }
}

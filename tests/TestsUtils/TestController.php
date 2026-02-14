<?php

/**
 * Concrete controller for testing abstract controller classes
 *
 * @package FoldSnap\Tests\TestsUtils
 */

declare(strict_types=1);

namespace FoldSnap\Tests\TestsUtils;

use FoldSnap\Core\Controllers\AbstractMenuPageController;

class TestController extends AbstractMenuPageController
{
    /** @var static[] */
    private static array $testInstances = [];

    /**
     * Create a new TestController with custom configuration
     *
     * @param string $slug       page slug
     * @param string $pageTitle  page title
     * @param string $menuLabel  menu label
     * @param string $capability required capability
     * @param string $parentSlug parent slug (empty = top-level menu)
     * @param int    $menuPos    menu position
     * @param string $iconUrl    icon URL
     */
    protected function __construct(
        string $slug = 'test-page',
        string $pageTitle = 'Test Page',
        string $menuLabel = 'Test Menu',
        string $capability = 'manage_options',
        string $parentSlug = '',
        int $menuPos = 50,
        string $iconUrl = ''
    ) {
        $this->pageSlug     = $slug;
        $this->pageTitle    = $pageTitle;
        $this->menuLabel    = $menuLabel;
        $this->capatibility = $capability;
        $this->parentSlug   = $parentSlug;
        $this->menuPos      = $menuPos;
        $this->iconUrl      = $iconUrl;
    }

    /**
     * Create a new TestController instance, bypassing singleton cache
     *
     * @param string $slug       page slug
     * @param string $pageTitle  page title
     * @param string $menuLabel  menu label
     * @param string $capability required capability
     * @param string $parentSlug parent slug (empty = top-level menu)
     * @param int    $menuPos    menu position
     * @param string $iconUrl    icon URL
     *
     * @return static
     */
    public static function create(
        string $slug = 'test-page',
        string $pageTitle = 'Test Page',
        string $menuLabel = 'Test Menu',
        string $capability = 'manage_options',
        string $parentSlug = '',
        int $menuPos = 50,
        string $iconUrl = ''
    ): self {
        return new self($slug, $pageTitle, $menuLabel, $capability, $parentSlug, $menuPos, $iconUrl);
    }

    /**
     * Reset menuHookSuffix for testing
     *
     * @return void
     */
    public function resetMenuHookSuffix(): void
    {
        $this->menuHookSuffix = false;
    }
}

<?php

/**
 * Sub menu item class
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Core\Controllers;

/**
 * Sub menu item class
 */
class SubMenuItem
{
    public string $slug   = '';
    public string $label  = '';
    public string $parent = '';
    /** @var bool|string */
    public $capatibility = true;
    public int $position = 10;
    public string $link  = '';
    public bool $active  = false;
    /** @var array<string,string> */
    public array $attributes = [];

    /**
     * Class constructor
     *
     * @param string      $slug         item slug
     * @param string      $label        menu label
     * @param string      $parent       parent slug
     * @param bool|string $capatibility item capability, true if have parent permission
     * @param int         $position     position
     */
    public function __construct(
        string $slug,
        string $label = '',
        string $parent = '',
        $capatibility = true,
        int $position = 10
    ) {
        $this->slug         = $slug;
        $this->label        = $label;
        $this->parent       = $parent;
        $this->capatibility = $capatibility;
        $this->position     = $position;
    }

    /**
     * Check if user can see this item
     *
     * @return bool
     */
    public function userCan(): bool
    {
        if (true === $this->capatibility) {
            return true;
        }

        return is_string($this->capatibility) && current_user_can($this->capatibility);
    }
}

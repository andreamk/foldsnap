<?php

/**
 * Controller interface
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Core\Controllers;

interface ControllerInterface
{
    /**
     * Method called on WordPress hook init action
     *
     * @return void
     */
    public function hookWpInit(): void;

    /**
     * Excecute controller
     *
     * @return void
     */
    public function run(): void;

    /**
     * Render page
     *
     * @return void
     */
    public function render(): void;
}

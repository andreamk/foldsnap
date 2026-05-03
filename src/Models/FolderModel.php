<?php

/**
 * Immutable DTO representing a folder (taxonomy term)
 *
 * Pure value object: holds the 7 core properties of a folder. Recursive
 * concerns (children, total media count, total size) live elsewhere —
 * the controller layer decorates the array form when needed.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Models;

use WP_Term;

final class FolderModel
{
    public const META_COLOR    = 'foldsnap_folder_color';
    public const META_POSITION = 'foldsnap_folder_position';

    public const ROOT_ID = 0;

    private int $id;
    private string $name;
    private string $slug;
    private int $parentId;
    private int $mediaCount;
    private string $color;
    private int $position;
    private bool $isRoot;

    /**
     * Constructor
     *
     * @param int    $id         Term ID
     * @param string $name       Folder name
     * @param string $slug       Folder slug
     * @param int    $parentId   Parent term ID (0 for root)
     * @param int    $mediaCount Number of media directly assigned
     * @param string $color      Hex color code
     * @param int    $position   Sort position
     * @param bool   $isRoot     Whether this is the virtual root folder
     */
    public function __construct(
        int $id,
        string $name,
        string $slug,
        int $parentId,
        int $mediaCount,
        string $color,
        int $position,
        bool $isRoot = false
    ) {
        $this->id         = $id;
        $this->name       = $name;
        $this->slug       = $slug;
        $this->parentId   = $parentId;
        $this->mediaCount = $mediaCount;
        $this->color      = $color;
        $this->position   = $position;
        $this->isRoot     = $isRoot;
    }

    /**
     * Get term ID
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get folder name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get folder slug
     *
     * @return string
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Get parent term ID (0 for root)
     *
     * @return int
     */
    public function getParentId(): int
    {
        return $this->parentId;
    }

    /**
     * Get number of media directly assigned (not recursive)
     *
     * @return int
     */
    public function getMediaCount(): int
    {
        return $this->mediaCount;
    }

    /**
     * Get hex color code
     *
     * @return string
     */
    public function getColor(): string
    {
        return $this->color;
    }

    /**
     * Get sort position
     *
     * @return int
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * Whether this model represents the virtual Root folder
     *
     * The Root folder is a synthetic FolderModel with id 0; the database
     * has no term backing it. Modifying it (rename, delete, parent change)
     * is forbidden by the repository.
     *
     * @return bool
     */
    public function isRoot(): bool
    {
        return $this->isRoot;
    }

    /**
     * Build the virtual Root FolderModel
     *
     * Identity-only factory. The repository populates `mediaCount` with the
     * count of unassigned attachments before handing this to the rest of
     * the system; recursive totals come from FolderTreeNavigator which has
     * its own branch for `isRoot()`.
     *
     * @param int $mediaCount Direct media count (unassigned attachments).
     *
     * @return self
     */
    public static function root(int $mediaCount = 0): self
    {
        return new self(
            self::ROOT_ID,
            __('Root', 'foldsnap'),
            'root',
            self::ROOT_ID,
            $mediaCount,
            '',
            0,
            true
        );
    }

    /**
     * Create a FolderModel from a WP_Term
     *
     * @param WP_Term $term WordPress term object
     *
     * @return self
     */
    public static function fromTerm(WP_Term $term): self
    {
        $color = get_term_meta($term->term_id, self::META_COLOR, true);
        if (! is_string($color)) {
            $color = '';
        }

        $position = get_term_meta($term->term_id, self::META_POSITION, true);
        $position = is_numeric($position) ? (int) $position : 0;

        return new self(
            $term->term_id,
            $term->name,
            $term->slug,
            $term->parent,
            $term->count,
            $color,
            $position
        );
    }

    /**
     * Serialize the model to its core properties
     *
     * @return array{id:int,name:string,slug:string,parent_id:int,media_count:int,color:string,position:int,is_root:bool}
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'parent_id'   => $this->parentId,
            'media_count' => $this->mediaCount,
            'color'       => $this->color,
            'position'    => $this->position,
            'is_root'     => $this->isRoot,
        ];
    }
}

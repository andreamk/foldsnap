<?php

/**
 * Immutable DTO representing a folder (taxonomy term)
 *
 * This class is read-only by design. It captures a snapshot of a folder's
 * state at a given point in time. To modify folder data, use FolderRepository
 * which performs the DB writes and returns a fresh FolderModel instance.
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

    private int $id;
    private string $name;
    private string $slug;
    private int $parentId;
    private int $mediaCount;
    private string $color;
    private int $position;

    /** @var FolderModel[] */
    private array $children = [];

    /**
     * Constructor
     *
     * @param int    $id         Term ID
     * @param string $name       Folder name
     * @param string $slug       Folder slug
     * @param int    $parentId   Parent term ID (0 for root)
     * @param int    $mediaCount Number of media assigned
     * @param string $color      Hex color code
     * @param int    $position   Sort position
     */
    public function __construct(
        int $id,
        string $name,
        string $slug,
        int $parentId,
        int $mediaCount,
        string $color,
        int $position
    ) {
        $this->id         = $id;
        $this->name       = $name;
        $this->slug       = $slug;
        $this->parentId   = $parentId;
        $this->mediaCount = $mediaCount;
        $this->color      = $color;
        $this->position   = $position;
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
     * Get parent term ID
     *
     * @return int
     */
    public function getParentId(): int
    {
        return $this->parentId;
    }

    /**
     * Get number of assigned media
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
     * Get children folders
     *
     * @return FolderModel[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * Add a child folder
     *
     * @param FolderModel $child Child folder model
     *
     * @return void
     */
    public function addChild(FolderModel $child): void
    {
        $this->children[] = $child;
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
     * Serialize the model to an array (recursive, includes children)
     *
     * @return array<string, mixed>
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
            'children'    => array_map(
                static function (FolderModel $child): array {
                    return $child->toArray();
                },
                $this->children
            ),
        ];
    }
}

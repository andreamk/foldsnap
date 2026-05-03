<?php

/**
 * Immutable DTO representing a folder (taxonomy term).
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Models;

use FoldSnap\Services\Database;
use FoldSnap\Services\TaxonomyService;
use WP_Term;

/**
 * @phpstan-type FolderArray array{
 *     id: int,
 *     name: string,
 *     slug: string,
 *     parent_id: int,
 *     media_count: int,
 *     total_media_count: int,
 *     total_size: int,
 *     color: string,
 *     position: int,
 *     has_children: bool,
 *     is_root: bool
 * }
 */
final class FolderModel
{
    public const META_COLOR    = 'foldsnap_folder_color';
    public const META_POSITION = 'foldsnap_folder_position';
    public const META_SIZE     = 'foldsnap_folder_size';
    public const META_COUNT    = 'foldsnap_folder_count';

    public const ROOT_ID   = 0;
    public const NO_PARENT = -1;

    private int $id;
    private string $name;
    private string $slug;
    private int $parentId;
    private int $mediaCount;
    private int $totalMediaCount;
    private int $totalSize;
    private string $color;
    private int $position;
    private bool $hasChildren;
    private bool $isRoot;

    /**
     * @param int    $id              Term ID (0 for Root)
     * @param string $name            Folder name
     * @param string $slug            Folder slug
     * @param int    $parentId        -1 = Root, 0 = child of Root, >0 = nested
     * @param int    $mediaCount      Direct media count (term_taxonomy.count)
     * @param int    $totalMediaCount Recursive media count
     * @param int    $totalSize       Recursive size in bytes
     * @param string $color           Hex color code
     * @param int    $position        Sort position
     * @param bool   $hasChildren     Whether the folder has direct children
     * @param bool   $isRoot          Virtual root flag
     */
    public function __construct(
        int $id,
        string $name,
        string $slug,
        int $parentId,
        int $mediaCount,
        int $totalMediaCount,
        int $totalSize,
        string $color,
        int $position,
        bool $hasChildren = false,
        bool $isRoot = false
    ) {
        $this->id              = $id;
        $this->name            = $name;
        $this->slug            = $slug;
        $this->parentId        = $parentId;
        $this->mediaCount      = $mediaCount;
        $this->totalMediaCount = $totalMediaCount;
        $this->totalSize       = $totalSize;
        $this->color           = $color;
        $this->position        = $position;
        $this->hasChildren     = $hasChildren;
        $this->isRoot          = $isRoot;
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
     * Get parent term ID (-1 = Root, 0 = child of Root, >0 = nested)
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
     * Get recursive media count (direct + every descendant's direct)
     *
     * @return int
     */
    public function getTotalMediaCount(): int
    {
        return $this->totalMediaCount;
    }

    /**
     * Get recursive size in bytes (direct + every descendant's direct)
     *
     * @return int
     */
    public function getTotalSize(): int
    {
        return $this->totalSize;
    }

    /**
     * Whether this folder has at least one direct child folder
     *
     * @return bool
     */
    public function hasChildren(): bool
    {
        return $this->hasChildren;
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
     * Identity-only factory. The repository populates the counts/size with
     * the option-backed Root globals before handing this to the rest of
     * the system; for Root those globals ARE the recursive totals.
     *
     * @param int  $mediaCount      Direct media count (unassigned attachments).
     * @param int  $totalMediaCount Global recursive media count (every attachment).
     * @param int  $totalSize       Global recursive total size in bytes.
     * @param bool $hasChildren     Whether top-level folders exist
     *
     * @return self
     */
    public static function root(
        int $mediaCount = 0,
        int $totalMediaCount = 0,
        int $totalSize = 0,
        bool $hasChildren = false
    ): self {
        return new self(
            self::ROOT_ID,
            __('Root', 'foldsnap'),
            'root',
            self::NO_PARENT,
            $mediaCount,
            $totalMediaCount,
            $totalSize,
            '',
            0,
            $hasChildren,
            true
        );
    }

    /**
     * Build models from a list of WP_Term objects with bulk-loaded meta
     *
     * Pre-fetches term meta (`update_termmeta_cache`) and children counts
     * (`Database::getChildrenCounts`) in a single round-trip each, so the
     * resulting models are fully populated without N+1 queries.
     *
     * @param WP_Term[] $terms WordPress term objects
     *
     * @return self[]
     */
    public static function fromTerms(array $terms): array
    {
        if (empty($terms)) {
            return [];
        }

        $termIds = array_map(static fn (WP_Term $t): int => $t->term_id, $terms);

        update_termmeta_cache($termIds);
        $childrenCounts = Database::getChildrenCounts(
            $termIds,
            TaxonomyService::TAXONOMY_NAME
        );

        $models = [];
        foreach ($terms as $term) {
            $models[] = self::buildFromTerm($term, ($childrenCounts[$term->term_id] ?? 0) > 0);
        }
        return $models;
    }

    /**
     * Build a model from a single WP_Term
     *
     * Convenience wrapper over `fromTerms`. Use `fromTerms` directly when
     * loading more than one term to avoid N+1 queries.
     *
     * @param WP_Term $term WordPress term object
     *
     * @return self
     */
    public static function fromTerm(WP_Term $term): self
    {
        return self::fromTerms([$term])[0];
    }

    /**
     * Internal: build a single model assuming meta is already cached
     *
     * @param WP_Term $term        WordPress term object
     * @param bool    $hasChildren Whether the term has at least one direct child
     *
     * @return self
     */
    private static function buildFromTerm(WP_Term $term, bool $hasChildren): self
    {
        $color = get_term_meta($term->term_id, self::META_COLOR, true);
        if (! is_string($color)) {
            $color = '';
        }

        $position = get_term_meta($term->term_id, self::META_POSITION, true);
        $position = is_numeric($position) ? (int) $position : 0;

        $totalCount = get_term_meta($term->term_id, self::META_COUNT, true);
        $totalCount = is_numeric($totalCount) ? (int) $totalCount : 0;

        $totalSize = get_term_meta($term->term_id, self::META_SIZE, true);
        $totalSize = is_numeric($totalSize) ? (int) $totalSize : 0;

        return new self(
            $term->term_id,
            $term->name,
            $term->slug,
            $term->parent,
            $term->count,
            $totalCount,
            $totalSize,
            $color,
            $position,
            $hasChildren
        );
    }

    /**
     * Serialize the model to its full set of properties
     *
     * @return FolderArray
     */
    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'slug'              => $this->slug,
            'parent_id'         => $this->parentId,
            'media_count'       => $this->mediaCount,
            'total_media_count' => $this->totalMediaCount,
            'total_size'        => $this->totalSize,
            'color'             => $this->color,
            'position'          => $this->position,
            'has_children'      => $this->hasChildren,
            'is_root'           => $this->isRoot,
        ];
    }
}

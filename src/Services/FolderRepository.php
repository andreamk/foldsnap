<?php

/**
 * Repository for folder CRUD operations
 *
 * Owns folder lookup, creation, update, and deletion against the
 * `foldsnap_folder` taxonomy. Counter math, name sanitisation, and
 * media-folder assignment live in dedicated services so this class
 * stays a focused CRUD repository.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Services;

use Exception;
use FoldSnap\Models\FolderModel;
use FoldSnap\Utils\Sanitize;
use InvalidArgumentException;
use WP_Term;

class FolderRepository
{
    private FolderNameSanitizer $sanitizer;
    private FolderCounterService $counters;
    private ?FolderTreeNavigator $navigator = null;

    /**
     * @param FolderNameSanitizer  $sanitizer Folder name sanitiser/uniqueness resolver.
     * @param FolderCounterService $counters  Counter writer + Root accessor.
     */
    public function __construct(FolderNameSanitizer $sanitizer, FolderCounterService $counters)
    {
        $this->sanitizer = $sanitizer;
        $this->counters  = $counters;
    }

    /**
     * Lazy-initialised navigator. Reused across calls in the same request
     * (e.g. one path resolution per row in a paginated search).
     *
     * @return FolderTreeNavigator
     */
    private function navigator(): FolderTreeNavigator
    {
        if (null === $this->navigator) {
            $this->navigator = new FolderTreeNavigator($this);
        }
        return $this->navigator;
    }

    /**
     * Hydrate FolderModel instances from WP_Term objects.
     *
     * Pre-warms the term-meta cache and fetches the children-count map
     * in a single query each, then hands both off to the pure model
     * factory. The model layer never queries the DB itself.
     *
     * @param WP_Term[] $terms WordPress term objects to hydrate.
     *
     * @return FolderModel[]
     */
    private function materializeModels(array $terms): array
    {
        if (empty($terms)) {
            return [];
        }

        $termIds = array_map(static fn (WP_Term $t): int => $t->term_id, $terms);

        update_termmeta_cache($termIds);
        $counts = Database::getChildrenCounts($termIds, TaxonomyService::TAXONOMY_NAME);

        $hasChildrenById = [];
        foreach ($counts as $termId => $count) {
            $hasChildrenById[$termId] = $count > 0;
        }

        return FolderModel::fromTerms($terms, $hasChildrenById);
    }

    /**
     * Retrieve a single folder by term ID
     *
     * Term ID 0 yields the synthetic Root folder (no database row backs it);
     * its direct media count is the number of unassigned attachments.
     *
     * @param int $termId Term ID (0 = virtual Root)
     *
     * @return FolderModel|null
     */
    public function getById(int $termId): ?FolderModel
    {
        if (FolderModel::ROOT_ID === $termId) {
            return $this->buildRootModel();
        }

        $term = get_term($termId, TaxonomyService::TAXONOMY_NAME);

        if (! $term instanceof WP_Term) {
            return null;
        }

        return $this->materializeModels([$term])[0];
    }

    /**
     * Build the virtual Root model with current global counters and has_children
     *
     * @return FolderModel
     */
    private function buildRootModel(): FolderModel
    {
        $topLevelCounts = Database::getChildrenCounts([0], TaxonomyService::TAXONOMY_NAME);
        $hasChildren    = ($topLevelCounts[0] ?? 0) > 0;

        return FolderModel::root(
            $this->counters->getUnassignedCount(),
            $this->counters->getGlobalCount(),
            $this->counters->getGlobalSize(),
            $hasChildren
        );
    }

    /**
     * Retrieve multiple folders by term IDs
     *
     * @param int[] $termIds Term IDs to fetch
     *
     * @return FolderModel[] Models indexed numerically (not keyed by ID)
     */
    public function getByIds(array $termIds): array
    {
        $termIds = array_map('intval', $termIds);

        if (empty($termIds)) {
            return [];
        }

        $models = [];

        // Inject the virtual Root if requested; get_terms() can't return it.
        if (in_array(FolderModel::ROOT_ID, $termIds, true)) {
            $models[] = $this->buildRootModel();
        }

        $realIds = array_values(array_filter(
            $termIds,
            static fn (int $id): bool => $id > 0
        ));

        if (empty($realIds)) {
            return $models;
        }

        $terms = get_terms(
            [
                'taxonomy'   => TaxonomyService::TAXONOMY_NAME,
                'hide_empty' => false,
                'include'    => $realIds,
            ]
        );

        if (is_wp_error($terms) || ! is_array($terms)) {
            return $models;
        }

        return array_merge($models, $this->materializeModels($terms));
    }

    /**
     * Retrieve direct children for a single parent
     *
     * @param int $parentId Parent term ID (0 for root-level folders)
     *
     * @return FolderModel[] Children sorted by position then name
     */
    public function getByParent(int $parentId): array
    {
        $byParent = $this->getByParents([$parentId]);
        return $byParent[$parentId] ?? [];
    }

    /**
     * Retrieve direct children for several parents in a single query
     *
     * Returns a map keyed by the requested parent IDs. Parents with no
     * children get an empty list. Children are sorted by position ASC,
     * then name ASC, for stable display order.
     *
     * @param int[] $parentIds Parent term IDs (0 for root)
     *
     * @return array<int, FolderModel[]>
     */
    public function getByParents(array $parentIds): array
    {
        $parentIds = array_values(array_unique(array_map('intval', $parentIds)));
        $parentIds = array_values(array_filter($parentIds, static fn (int $id): bool => $id >= 0));

        /** @var array<int, FolderModel[]> $result */
        $result = [];
        foreach ($parentIds as $parentId) {
            $result[$parentId] = [];
        }

        if (empty($parentIds)) {
            return $result;
        }

        // WP_Term_Query's parent__in is unreliable when combined with the
        // 'parent' arg (or its absence). Issue one targeted query per parent.
        foreach ($parentIds as $parentId) {
            $terms = get_terms(
                [
                    'taxonomy'   => TaxonomyService::TAXONOMY_NAME,
                    'hide_empty' => false,
                    'parent'     => $parentId,
                ]
            );

            if (is_wp_error($terms) || ! is_array($terms)) {
                continue;
            }

            $result[$parentId] = $this->materializeModels($terms);
        }

        foreach ($result as $parentId => $children) {
            usort(
                $children,
                static function (FolderModel $a, FolderModel $b): int {
                    $cmp = $a->getPosition() <=> $b->getPosition();
                    if (0 !== $cmp) {
                        return $cmp;
                    }
                    return strcasecmp($a->getName(), $b->getName());
                }
            );
            $result[$parentId] = $children;
        }

        return $result;
    }

    /**
     * Search folders by name (paginated, flat list).
     *
     * Uses get_terms() with `name__like` for substring matching.
     *
     * @param string $query   Search string (empty returns no results)
     * @param int    $page    1-indexed page number
     * @param int    $perPage Items per page (clamped 1..100)
     *
     * @return array{folders:FolderModel[], total:int, total_pages:int}
     */
    public function search(string $query, int $page = 1, int $perPage = 50): array
    {
        $trimmed = trim($query);

        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        if ('' === $trimmed) {
            return [
                'folders'     => [],
                'total'       => 0,
                'total_pages' => 0,
            ];
        }

        $countResult = get_terms(
            [
                'taxonomy'   => TaxonomyService::TAXONOMY_NAME,
                'hide_empty' => false,
                'name__like' => $trimmed,
                'fields'     => 'count',
            ]
        );

        $total = is_numeric($countResult) ? (int) $countResult : 0;

        $terms = get_terms(
            [
                'taxonomy'   => TaxonomyService::TAXONOMY_NAME,
                'hide_empty' => false,
                'name__like' => $trimmed,
                'orderby'    => 'name',
                'order'      => 'ASC',
                'number'     => $perPage,
                'offset'     => ($page - 1) * $perPage,
            ]
        );

        $folders = is_array($terms) ? $this->materializeModels($terms) : [];

        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;

        return [
            'folders'     => $folders,
            'total'       => $total,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Resolve the ancestor path (root → target) for a folder
     *
     * @param int $folderId Target folder term ID
     *
     * @return FolderModel[]
     */
    public function getPath(int $folderId): array
    {
        return $this->navigator()->resolvePath($folderId);
    }

    /**
     * Create a new folder
     *
     * @param string $name     Folder name
     * @param int    $parentId Parent term ID (0 for root)
     * @param string $color    Hex color code
     * @param int    $position Sort position
     *
     * @return FolderModel
     *
     * @throws InvalidArgumentException If name is invalid or parent does not exist.
     * @throws Exception If WordPress fails to insert the term.
     */
    public function create(string $name, int $parentId = 0, string $color = '', int $position = 0): FolderModel
    {
        $name = $this->sanitizer->sanitize($name);
        $name = $this->sanitizer->ensureUnique($name, $parentId);

        $args = [];
        if (0 < $parentId) {
            $this->getByIdOrFail($parentId);
            $args['parent'] = $parentId;
        }

        $result = wp_insert_term($name, TaxonomyService::TAXONOMY_NAME, $args);

        if (is_wp_error($result)) {
            throw new Exception(esc_html($result->get_error_message()));
        }

        $termId = (int) $result['term_id'];

        // Initialize all four meta keys explicitly. The counter keys MUST exist
        // before the first bulk UPDATE on this folder; the color/position keys
        // are kept consistent for the same reason (uniform shape per term).
        $color = '' !== $color ? Sanitize::hexColor($color) : '';
        add_term_meta($termId, FolderModel::META_COLOR, $color, true);
        add_term_meta($termId, FolderModel::META_POSITION, (string) $position, true);
        add_term_meta($termId, FolderModel::META_SIZE, '0', true);
        add_term_meta($termId, FolderModel::META_COUNT, '0', true);

        return $this->getByIdOrFail($termId);
    }

    /**
     * Update an existing folder. Pass null for any field to leave it unchanged.
     *
     * @param int         $termId   Term ID
     * @param string|null $name     New name, or null to keep
     * @param int|null    $parentId New parent ID, or null to keep
     * @param string|null $color    New color, or null to keep
     * @param int|null    $position New position, or null to keep
     *
     * @return FolderModel
     *
     * @throws InvalidArgumentException If term does not exist, name is invalid, or parent does not exist.
     * @throws Exception If WordPress fails to update the term.
     */
    public function update(
        int $termId,
        ?string $name = null,
        ?int $parentId = null,
        ?string $color = null,
        ?int $position = null
    ): FolderModel {
        $this->guardNotRoot($termId);

        $folder = $this->getByIdOrFail($termId);

        $args = [];
        if (null !== $name) {
            $name              = $this->sanitizer->sanitize($name);
            $effectiveParentId = $parentId ?? $folder->getParentId();
            $name              = $this->sanitizer->ensureUnique($name, $effectiveParentId, $termId);
            $args['name']      = $name;
        }

        $oldParentId = $folder->getParentId();
        $isReparent  = null !== $parentId && $parentId !== $oldParentId;

        if (null !== $parentId) {
            if (0 < $parentId) {
                $this->getByIdOrFail($parentId);
            }
            $args['parent'] = $parentId;
        }

        $subtreeSize  = $isReparent ? $folder->getTotalSize() : 0;
        $subtreeCount = $isReparent ? $folder->getTotalMediaCount() : 0;

        if (count($args) > 0) {
            $result = wp_update_term($termId, TaxonomyService::TAXONOMY_NAME, $args);

            if (is_wp_error($result)) {
                throw new Exception(esc_html($result->get_error_message()));
            }
        }

        if (null !== $color) {
            update_term_meta($termId, FolderModel::META_COLOR, Sanitize::hexColor($color));
        }

        if (null !== $position) {
            update_term_meta($termId, FolderModel::META_POSITION, (string) $position);
        }

        if ($isReparent && ($subtreeSize > 0 || $subtreeCount > 0)) {
            $oldChain = $oldParentId > 0 ? FolderTreeNavigator::ancestorIds($oldParentId) : [];
            $this->counters->applyChainDelta($oldChain, -$subtreeSize, -$subtreeCount);

            $newChain = ($parentId ?? 0) > 0 ? FolderTreeNavigator::ancestorIds((int) $parentId) : [];
            $this->counters->applyChainDelta($newChain, $subtreeSize, $subtreeCount);
        }

        return $this->getByIdOrFail($termId);
    }

    /**
     * Delete a folder
     *
     * Media items assigned to this folder will automatically lose
     * the term relationship and return to root.
     *
     * @param int $termId Term ID to delete
     *
     * @return bool True if deleted successfully
     *
     * @throws InvalidArgumentException If term does not exist.
     */
    public function delete(int $termId): bool
    {
        $this->guardNotRoot($termId);

        $folder = $this->getByIdOrFail($termId);

        // Snapshot the data needed for delta math before the term is gone.
        // - direct counters of $termId (its direct media will end up in Root,
        //   so the ancestor chain loses these)
        // - parent ancestor chain EXCLUDING $termId (its sub-folders get
        //   promoted to grandparent; their subtrees were already aggregated
        //   into the ancestors, so no delta is needed for sub-folder moves)
        $directCount = $folder->getMediaCount();
        $directSize  = 0;
        if ($directCount > 0) {
            $directSizes = Database::getDirectSizesForFolders([$termId], TaxonomyService::TAXONOMY_NAME);
            $directSize  = $directSizes[$termId] ?? 0;
        }

        $parentId       = $folder->getParentId();
        $ancestorsAbove = $parentId > 0 ? FolderTreeNavigator::ancestorIds($parentId) : [];

        $result = wp_delete_term($termId, TaxonomyService::TAXONOMY_NAME);

        if (true === $result) {
            $this->counters->applyChainDelta($ancestorsAbove, -$directSize, -$directCount);
            $this->counters->invalidateUnassignedCache();
        }

        return true === $result;
    }

    /**
     * Get a folder by ID or throw if not found
     *
     * @param int $termId Term ID
     *
     * @return FolderModel
     *
     * @throws InvalidArgumentException If term does not exist.
     */
    private function getByIdOrFail(int $termId): FolderModel
    {
        $model = $this->getById($termId);

        if (null === $model) {
            throw new InvalidArgumentException(
                esc_html(sprintf('Folder with ID %d does not exist.', $termId))
            );
        }

        return $model;
    }

    /**
     * Throws if $termId is the virtual Root sentinel (0).
     *
     * @param int $termId Term ID being mutated.
     *
     * @return void
     *
     * @throws InvalidArgumentException If $termId is the Root sentinel.
     */
    private function guardNotRoot(int $termId): void
    {
        if (FolderModel::ROOT_ID === $termId) {
            throw new InvalidArgumentException(
                esc_html__('Root folder cannot be modified.', 'foldsnap')
            );
        }
    }
}

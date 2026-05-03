<?php

/**
 * Tests for FolderTreeNavigator
 *
 * @package FoldSnap\Tests\Feature\Services
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Feature\Services;

use FoldSnap\Services\FolderCounterService;
use FoldSnap\Services\FolderNameSanitizer;
use FoldSnap\Services\FolderRepository;
use FoldSnap\Services\FolderTreeNavigator;
use FoldSnap\Services\TaxonomyService;
use WP_UnitTestCase;

class FolderTreeNavigatorTests extends WP_UnitTestCase
{
    private FolderRepository $repository;
    private FolderTreeNavigator $navigator;

    /**
     * Set up test environment
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        TaxonomyService::register();
        $this->repository = new FolderRepository(
            new FolderNameSanitizer(),
            new FolderCounterService()
        );
        $this->navigator  = new FolderTreeNavigator($this->repository);
    }

    /**
     * Test ancestorIds walks the chain leaf-first, excluding Root
     *
     * @return void
     */
    public function test_ancestor_ids_walks_chain_leaf_first(): void
    {
        $topId   = $this->createTerm('Top');
        $childId = $this->createTerm('Child', ['parent' => $topId]);
        $grandId = $this->createTerm('Grand', ['parent' => $childId]);

        $this->assertSame(
            [
                $grandId,
                $childId,
                $topId,
            ],
            FolderTreeNavigator::ancestorIds($grandId)
        );
    }

    /**
     * Test ancestorIds returns empty for non-positive IDs
     *
     * @return void
     */
    public function test_ancestor_ids_rejects_non_positive(): void
    {
        $this->assertSame([], FolderTreeNavigator::ancestorIds(0));
        $this->assertSame([], FolderTreeNavigator::ancestorIds(-1));
    }

    /**
     * Test resolvePath returns ordered chain Root → target
     *
     * @return void
     */
    public function test_resolve_path_full_chain(): void
    {
        $topId   = $this->createTerm('Top');
        $childId = $this->createTerm('Child', ['parent' => $topId]);
        $grandId = $this->createTerm('Grand', ['parent' => $childId]);

        $path = $this->navigator->resolvePath($grandId);

        $this->assertCount(4, $path);
        $this->assertTrue($path[0]->isRoot());
        $this->assertSame($topId, $path[1]->getId());
        $this->assertSame($childId, $path[2]->getId());
        $this->assertSame($grandId, $path[3]->getId());
    }

    /**
     * Test resolvePath returns empty for non-existent folder
     *
     * @return void
     */
    public function test_resolve_path_missing_folder(): void
    {
        $this->assertSame([], $this->navigator->resolvePath(999999));
    }

    /**
     * Test resolvePath(0) returns just the Root folder
     *
     * @return void
     */
    public function test_resolve_path_for_root(): void
    {
        $path = $this->navigator->resolvePath(0);

        $this->assertCount(1, $path);
        $this->assertTrue($path[0]->isRoot());
    }

    /**
     * Test resolvePath returns empty for invalid (negative) folder ID
     *
     * @return void
     */
    public function test_resolve_path_invalid_id(): void
    {
        $this->assertSame([], $this->navigator->resolvePath(-1));
    }

    /**
     * Create a taxonomy term and return its ID
     *
     * @param string               $name Term name
     * @param array<string, mixed> $args Optional args
     *
     * @return int
     */
    private function createTerm(string $name, array $args = []): int
    {
        $result = wp_insert_term($name, TaxonomyService::TAXONOMY_NAME, $args);
        return (int) $result['term_id'];
    }
}

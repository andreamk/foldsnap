<?php

/**
 * Tests for FolderTreeNavigator
 *
 * @package FoldSnap\Tests\Feature\Services
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Feature\Services;

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
        $this->repository = new FolderRepository();
        $this->navigator  = new FolderTreeNavigator($this->repository);
    }

    /**
     * Test hasChildren returns true for parents and false for leaves
     *
     * @return void
     */
    public function test_has_children_distinguishes_leaves(): void
    {
        $parentId = $this->createTerm('Parent');
        $leafId   = $this->createTerm('Leaf');
        $this->createTerm('Child', ['parent' => $parentId]);

        $result = $this->navigator->hasChildren([$parentId, $leafId]);

        $this->assertTrue($result[$parentId]);
        $this->assertFalse($result[$leafId]);
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

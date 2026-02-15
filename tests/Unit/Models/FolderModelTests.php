<?php

/**
 * Tests for FolderModel class
 *
 * @package FoldSnap\Tests\Unit\Models
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Unit\Models;

use FoldSnap\Models\FolderModel;
use FoldSnap\Services\TaxonomyService;
use WP_UnitTestCase;

class FolderModelTests extends WP_UnitTestCase
{
    /**
     * Set up test environment
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        TaxonomyService::register();
    }

    /**
     * Test constructor sets all properties
     *
     * @return void
     */
    public function test_constructor_sets_all_properties(): void
    {
        $model = new FolderModel(10, 'Photos', 'photos', 0, 5, '#ff0000', 3);

        $this->assertSame(10, $model->getId());
        $this->assertSame('Photos', $model->getName());
        $this->assertSame('photos', $model->getSlug());
        $this->assertSame(0, $model->getParentId());
        $this->assertSame(5, $model->getMediaCount());
        $this->assertSame('#ff0000', $model->getColor());
        $this->assertSame(3, $model->getPosition());
        $this->assertSame([], $model->getChildren());
    }

    /**
     * Test addChild adds a child folder
     *
     * @return void
     */
    public function test_add_child_adds_folder(): void
    {
        $parent = new FolderModel(1, 'Parent', 'parent', 0, 0, '', 0);
        $child  = new FolderModel(2, 'Child', 'child', 1, 0, '', 0);

        $parent->addChild($child);

        $this->assertCount(1, $parent->getChildren());
        $this->assertSame($child, $parent->getChildren()[0]);
    }

    /**
     * Test addChild can add multiple children
     *
     * @return void
     */
    public function test_add_child_adds_multiple_children(): void
    {
        $parent = new FolderModel(1, 'Parent', 'parent', 0, 0, '', 0);
        $child1 = new FolderModel(2, 'Child A', 'child-a', 1, 0, '', 0);
        $child2 = new FolderModel(3, 'Child B', 'child-b', 1, 0, '', 1);

        $parent->addChild($child1);
        $parent->addChild($child2);

        $this->assertCount(2, $parent->getChildren());
        $this->assertSame($child1, $parent->getChildren()[0]);
        $this->assertSame($child2, $parent->getChildren()[1]);
    }

    /**
     * Test fromTerm creates model from a WP_Term
     *
     * @return void
     */
    public function test_from_term_creates_model(): void
    {
        $termData = wp_insert_term('Documents', TaxonomyService::TAXONOMY_NAME);
        /** @var int $termId */
        $termId = $termData['term_id'];

        update_term_meta($termId, FolderModel::META_COLOR, '#00ff00');
        update_term_meta($termId, FolderModel::META_POSITION, '2');

        $term  = get_term($termId, TaxonomyService::TAXONOMY_NAME);
        $model = FolderModel::fromTerm($term);

        $this->assertSame($termId, $model->getId());
        $this->assertSame('Documents', $model->getName());
        $this->assertSame('documents', $model->getSlug());
        $this->assertSame(0, $model->getParentId());
        $this->assertSame(0, $model->getMediaCount());
        $this->assertSame('#00ff00', $model->getColor());
        $this->assertSame(2, $model->getPosition());
    }

    /**
     * Test fromTerm uses defaults when meta is missing
     *
     * @return void
     */
    public function test_from_term_uses_defaults_when_meta_missing(): void
    {
        $termData = wp_insert_term('No Meta', TaxonomyService::TAXONOMY_NAME);
        /** @var int $termId */
        $termId = $termData['term_id'];

        $term  = get_term($termId, TaxonomyService::TAXONOMY_NAME);
        $model = FolderModel::fromTerm($term);

        $this->assertSame('', $model->getColor());
        $this->assertSame(0, $model->getPosition());
    }

    /**
     * Test fromTerm maps parent correctly
     *
     * @return void
     */
    public function test_from_term_maps_parent_id(): void
    {
        $parentData = wp_insert_term('Parent', TaxonomyService::TAXONOMY_NAME);
        /** @var int $parentId */
        $parentId = $parentData['term_id'];

        $childData = wp_insert_term(
            'Child',
            TaxonomyService::TAXONOMY_NAME,
            ['parent' => $parentId]
        );
        /** @var int $childId */
        $childId = $childData['term_id'];

        $childTerm = get_term($childId, TaxonomyService::TAXONOMY_NAME);
        $model     = FolderModel::fromTerm($childTerm);

        $this->assertSame($parentId, $model->getParentId());
    }

    /**
     * Test directSize defaults to zero
     *
     * @return void
     */
    public function test_direct_size_defaults_to_zero(): void
    {
        $model = new FolderModel(1, 'Folder', 'folder', 0, 0, '', 0);

        $this->assertSame(0, $model->getDirectSize());
    }

    /**
     * Test setDirectSize and getDirectSize
     *
     * @return void
     */
    public function test_set_and_get_direct_size(): void
    {
        $model = new FolderModel(1, 'Folder', 'folder', 0, 0, '', 0);

        $model->setDirectSize(1048576);

        $this->assertSame(1048576, $model->getDirectSize());
    }

    /**
     * Test getTotalMediaCount without children returns own count
     *
     * @return void
     */
    public function test_total_media_count_without_children(): void
    {
        $model = new FolderModel(1, 'Folder', 'folder', 0, 10, '', 0);

        $this->assertSame(10, $model->getTotalMediaCount());
    }

    /**
     * Test getTotalMediaCount with nested children sums recursively
     *
     * @return void
     */
    public function test_total_media_count_with_nested_children(): void
    {
        $grandchild = new FolderModel(3, 'GC', 'gc', 2, 3, '', 0);
        $child      = new FolderModel(2, 'Child', 'child', 1, 5, '', 0);
        $parent     = new FolderModel(1, 'Parent', 'parent', 0, 2, '', 0);

        $child->addChild($grandchild);
        $parent->addChild($child);

        $this->assertSame(10, $parent->getTotalMediaCount());
        $this->assertSame(8, $child->getTotalMediaCount());
        $this->assertSame(3, $grandchild->getTotalMediaCount());
    }

    /**
     * Test getTotalSize without children returns own directSize
     *
     * @return void
     */
    public function test_total_size_without_children(): void
    {
        $model = new FolderModel(1, 'Folder', 'folder', 0, 0, '', 0);
        $model->setDirectSize(5000);

        $this->assertSame(5000, $model->getTotalSize());
    }

    /**
     * Test getTotalSize with nested children sums recursively
     *
     * @return void
     */
    public function test_total_size_with_nested_children(): void
    {
        $grandchild = new FolderModel(3, 'GC', 'gc', 2, 0, '', 0);
        $grandchild->setDirectSize(1000);

        $child = new FolderModel(2, 'Child', 'child', 1, 0, '', 0);
        $child->setDirectSize(2000);

        $parent = new FolderModel(1, 'Parent', 'parent', 0, 0, '', 0);
        $parent->setDirectSize(3000);

        $child->addChild($grandchild);
        $parent->addChild($child);

        $this->assertSame(6000, $parent->getTotalSize());
        $this->assertSame(3000, $child->getTotalSize());
        $this->assertSame(1000, $grandchild->getTotalSize());
    }

    /**
     * Test toArray returns correct structure without children
     *
     * @return void
     */
    public function test_to_array_returns_correct_structure(): void
    {
        $model = new FolderModel(5, 'Music', 'music', 0, 10, '#0000ff', 1);

        $expected = [
            'id'                => 5,
            'name'              => 'Music',
            'slug'              => 'music',
            'parent_id'         => 0,
            'media_count'       => 10,
            'total_media_count' => 10,
            'color'             => '#0000ff',
            'position'          => 1,
            'direct_size'       => 0,
            'total_size'        => 0,
            'children'          => [],
        ];

        $this->assertSame($expected, $model->toArray());
    }

    /**
     * Test toArray includes children recursively with totals
     *
     * @return void
     */
    public function test_to_array_includes_children_recursively(): void
    {
        $grandchild = new FolderModel(3, 'Sub-sub', 'sub-sub', 2, 0, '', 0);
        $child      = new FolderModel(2, 'Sub', 'sub', 1, 3, '#aabb00', 1);
        $parent     = new FolderModel(1, 'Root', 'root', 0, 5, '#ff0000', 0);

        $child->addChild($grandchild);
        $parent->addChild($child);

        $result = $parent->toArray();

        $this->assertSame(8, $result['total_media_count']);
        $this->assertCount(1, $result['children']);
        $this->assertSame(2, $result['children'][0]['id']);
        $this->assertSame(3, $result['children'][0]['total_media_count']);

        $this->assertCount(1, $result['children'][0]['children']);
        $this->assertSame(3, $result['children'][0]['children'][0]['id']);
        $this->assertSame([], $result['children'][0]['children'][0]['children']);
    }

    /**
     * Test toArray includes size fields
     *
     * @return void
     */
    public function test_to_array_includes_size_fields(): void
    {
        $child = new FolderModel(2, 'Child', 'child', 1, 0, '', 0);
        $child->setDirectSize(500);

        $parent = new FolderModel(1, 'Parent', 'parent', 0, 0, '', 0);
        $parent->setDirectSize(1000);
        $parent->addChild($child);

        $result = $parent->toArray();

        $this->assertSame(1000, $result['direct_size']);
        $this->assertSame(1500, $result['total_size']);
        $this->assertSame(500, $result['children'][0]['direct_size']);
        $this->assertSame(500, $result['children'][0]['total_size']);
    }

    /**
     * Test meta constants are defined correctly
     *
     * @return void
     */
    public function test_meta_constants_are_defined(): void
    {
        $this->assertSame('foldsnap_folder_color', FolderModel::META_COLOR);
        $this->assertSame('foldsnap_folder_position', FolderModel::META_POSITION);
    }

    /**
     * Test fromTerm handles non-numeric position meta
     *
     * @return void
     */
    public function test_from_term_handles_non_numeric_position(): void
    {
        $termData = wp_insert_term('Bad Position', TaxonomyService::TAXONOMY_NAME);
        /** @var int $termId */
        $termId = $termData['term_id'];

        update_term_meta($termId, FolderModel::META_POSITION, 'not-a-number');

        $term  = get_term($termId, TaxonomyService::TAXONOMY_NAME);
        $model = FolderModel::fromTerm($term);

        $this->assertSame(0, $model->getPosition());
    }
}

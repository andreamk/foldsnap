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
     * Test toArray returns correct structure without children
     *
     * @return void
     */
    public function test_to_array_returns_correct_structure(): void
    {
        $model = new FolderModel(5, 'Music', 'music', 0, 10, '#0000ff', 1);

        $expected = [
            'id'          => 5,
            'name'        => 'Music',
            'slug'        => 'music',
            'parent_id'   => 0,
            'media_count' => 10,
            'color'       => '#0000ff',
            'position'    => 1,
            'children'    => [],
        ];

        $this->assertSame($expected, $model->toArray());
    }

    /**
     * Test toArray includes children recursively
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

        $this->assertCount(1, $result['children']);
        $this->assertSame(2, $result['children'][0]['id']);
        $this->assertSame('Sub', $result['children'][0]['name']);

        $this->assertCount(1, $result['children'][0]['children']);
        $this->assertSame(3, $result['children'][0]['children'][0]['id']);
        $this->assertSame('Sub-sub', $result['children'][0]['children'][0]['name']);
        $this->assertSame([], $result['children'][0]['children'][0]['children']);
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

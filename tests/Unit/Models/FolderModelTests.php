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
        $model = new FolderModel(10, 'Photos', 'photos', 0, 5, 12, 4096, '#ff0000', 3, true);

        $this->assertSame(10, $model->getId());
        $this->assertSame('Photos', $model->getName());
        $this->assertSame('photos', $model->getSlug());
        $this->assertSame(0, $model->getParentId());
        $this->assertSame(5, $model->getMediaCount());
        $this->assertSame(12, $model->getTotalMediaCount());
        $this->assertSame(4096, $model->getTotalSize());
        $this->assertSame('#ff0000', $model->getColor());
        $this->assertSame(3, $model->getPosition());
        $this->assertTrue($model->hasChildren());
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
        update_term_meta($termId, FolderModel::META_COUNT, '7');
        update_term_meta($termId, FolderModel::META_SIZE, '8192');

        $term  = get_term($termId, TaxonomyService::TAXONOMY_NAME);
        $model = FolderModel::fromTerm($term);

        $this->assertSame($termId, $model->getId());
        $this->assertSame('Documents', $model->getName());
        $this->assertSame('documents', $model->getSlug());
        $this->assertSame(0, $model->getParentId());
        $this->assertSame(0, $model->getMediaCount());
        $this->assertSame(7, $model->getTotalMediaCount());
        $this->assertSame(8192, $model->getTotalSize());
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
        $this->assertSame(0, $model->getTotalMediaCount());
        $this->assertSame(0, $model->getTotalSize());
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
     * Test toArray returns the core properties
     *
     * @return void
     */
    public function test_to_array_returns_core_properties(): void
    {
        $model = new FolderModel(5, 'Music', 'music', 0, 10, 25, 51200, '#0000ff', 1, false);

        $expected = [
            'id'                => 5,
            'name'              => 'Music',
            'slug'              => 'music',
            'parent_id'         => 0,
            'media_count'       => 10,
            'total_media_count' => 25,
            'total_size'        => 51200,
            'color'             => '#0000ff',
            'position'          => 1,
            'has_children'      => false,
            'is_root'           => false,
        ];

        $this->assertSame($expected, $model->toArray());
    }

    /**
     * Test root() factory builds the virtual Root folder
     *
     * @return void
     */
    public function test_root_factory_builds_virtual_root(): void
    {
        $root = FolderModel::root(7, 42, 99999);

        $this->assertSame(0, $root->getId());
        $this->assertSame(FolderModel::NO_PARENT, $root->getParentId());
        $this->assertSame('Root', $root->getName());
        $this->assertSame(7, $root->getMediaCount());
        $this->assertSame(42, $root->getTotalMediaCount());
        $this->assertSame(99999, $root->getTotalSize());
        $this->assertTrue($root->isRoot());
    }

    /**
     * Test root() factory defaults all counts to zero
     *
     * @return void
     */
    public function test_root_factory_defaults_to_zero(): void
    {
        $root = FolderModel::root();

        $this->assertSame(0, $root->getMediaCount());
        $this->assertSame(0, $root->getTotalMediaCount());
        $this->assertSame(0, $root->getTotalSize());
        $this->assertFalse($root->hasChildren());
    }

    /**
     * Test toArray exposes is_root true for the Root folder
     *
     * @return void
     */
    public function test_to_array_marks_root_with_is_root_true(): void
    {
        $root  = FolderModel::root(3);
        $array = $root->toArray();

        $this->assertTrue($array['is_root']);
        $this->assertSame(0, $array['id']);
        $this->assertSame(FolderModel::NO_PARENT, $array['parent_id']);
        $this->assertSame('Root', $array['name']);
    }

    /**
     * Test fromTerm() never marks a folder as Root
     *
     * @return void
     */
    public function test_from_term_is_not_root(): void
    {
        $termData = wp_insert_term('Real', TaxonomyService::TAXONOMY_NAME);
        $termId   = (int) $termData['term_id'];
        $term     = get_term($termId, TaxonomyService::TAXONOMY_NAME);

        $model = FolderModel::fromTerm($term);

        $this->assertFalse($model->isRoot());
        $this->assertFalse($model->toArray()['is_root']);
    }

    /**
     * Test toArray does not include presenter-only or removed fields
     *
     * `total_media_count` and `total_size` ARE on the model now (they're
     * just term meta, like color/position). `children`, `direct_size`,
     * `has_children` are not — they're either gone or decorated by the
     * REST presenter on the way out.
     *
     * @return void
     */
    public function test_to_array_excludes_legacy_fields(): void
    {
        $model  = new FolderModel(1, 'Folder', 'folder', 0, 5, 5, 0, '', 0);
        $result = $model->toArray();

        $this->assertArrayNotHasKey('children', $result);
        $this->assertArrayNotHasKey('direct_size', $result);
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
        $this->assertSame('foldsnap_folder_size', FolderModel::META_SIZE);
        $this->assertSame('foldsnap_folder_count', FolderModel::META_COUNT);
    }

    /**
     * Test fromTerm tolerates non-numeric size/count meta
     *
     * @return void
     */
    public function test_from_term_handles_non_numeric_totals(): void
    {
        $termData = wp_insert_term('Bad Totals', TaxonomyService::TAXONOMY_NAME);
        /** @var int $termId */
        $termId = $termData['term_id'];

        update_term_meta($termId, FolderModel::META_COUNT, 'oops');
        update_term_meta($termId, FolderModel::META_SIZE, 'oops');

        $term  = get_term($termId, TaxonomyService::TAXONOMY_NAME);
        $model = FolderModel::fromTerm($term);

        $this->assertSame(0, $model->getTotalMediaCount());
        $this->assertSame(0, $model->getTotalSize());
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

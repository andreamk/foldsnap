<?php

/**
 * Tests for TaxonomyService class
 *
 * @package FoldSnap\Tests\Unit\Services
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Unit\Services;

use FoldSnap\Services\TaxonomyService;
use WP_UnitTestCase;

class TaxonomyServiceTests extends WP_UnitTestCase
{
    /**
     * Test taxonomy constants are defined
     *
     * @return void
     */
    public function test_constants_are_defined(): void
    {
        $this->assertSame('foldsnap_folder', TaxonomyService::TAXONOMY_NAME);
        $this->assertSame('attachment', TaxonomyService::POST_TYPE);
    }

    /**
     * Test taxonomy is registered after register call
     *
     * @return void
     */
    public function test_taxonomy_is_registered(): void
    {
        TaxonomyService::register();

        $this->assertTrue(taxonomy_exists(TaxonomyService::TAXONOMY_NAME));
    }

    /**
     * Test taxonomy is hierarchical
     *
     * @return void
     */
    public function test_taxonomy_is_hierarchical(): void
    {
        TaxonomyService::register();

        $this->assertTrue(is_taxonomy_hierarchical(TaxonomyService::TAXONOMY_NAME));
    }

    /**
     * Test taxonomy is not public
     *
     * @return void
     */
    public function test_taxonomy_is_not_public(): void
    {
        TaxonomyService::register();

        $taxonomy = get_taxonomy(TaxonomyService::TAXONOMY_NAME);
        $this->assertNotFalse($taxonomy);
        $this->assertFalse($taxonomy->public);
    }

    /**
     * Test taxonomy is associated with attachment post type
     *
     * @return void
     */
    public function test_taxonomy_is_associated_with_attachment(): void
    {
        TaxonomyService::register();

        $taxonomies = get_object_taxonomies(TaxonomyService::POST_TYPE);
        $this->assertContains(TaxonomyService::TAXONOMY_NAME, $taxonomies);
    }

    /**
     * Test taxonomy has show_ui disabled
     *
     * @return void
     */
    public function test_taxonomy_has_show_ui_disabled(): void
    {
        TaxonomyService::register();

        $taxonomy = get_taxonomy(TaxonomyService::TAXONOMY_NAME);
        $this->assertNotFalse($taxonomy);
        $this->assertFalse($taxonomy->show_ui);
    }

    /**
     * Test taxonomy has show_in_rest disabled
     *
     * @return void
     */
    public function test_taxonomy_has_show_in_rest_disabled(): void
    {
        TaxonomyService::register();

        $taxonomy = get_taxonomy(TaxonomyService::TAXONOMY_NAME);
        $this->assertNotFalse($taxonomy);
        $this->assertFalse($taxonomy->show_in_rest);
    }

    /**
     * Test taxonomy has rewrite disabled
     *
     * @return void
     */
    public function test_taxonomy_has_rewrite_disabled(): void
    {
        TaxonomyService::register();

        $taxonomy = get_taxonomy(TaxonomyService::TAXONOMY_NAME);
        $this->assertNotFalse($taxonomy);
        $this->assertFalse($taxonomy->rewrite);
    }

    /**
     * Test taxonomy labels use foldsnap text domain
     *
     * @return void
     */
    public function test_taxonomy_labels_are_set(): void
    {
        TaxonomyService::register();

        $taxonomy = get_taxonomy(TaxonomyService::TAXONOMY_NAME);
        $this->assertNotFalse($taxonomy);
        $this->assertSame('Folders', $taxonomy->labels->name);
        $this->assertSame('Folder', $taxonomy->labels->singular_name);
        $this->assertSame('Add New Folder', $taxonomy->labels->add_new_item);
        $this->assertSame('Edit Folder', $taxonomy->labels->edit_item);
        $this->assertSame('Search Folders', $taxonomy->labels->search_items);
        $this->assertSame('No folders found', $taxonomy->labels->not_found);
    }

    /**
     * Test taxonomy uses generic term count callback for attachments
     *
     * Attachments have post_status='inherit', so the default callback
     * _update_post_term_count (which only counts 'publish') would always
     * return 0. The taxonomy must use _update_generic_term_count instead.
     *
     * @return void
     */
    public function test_taxonomy_uses_generic_term_count_callback(): void
    {
        TaxonomyService::register();

        $taxonomy = get_taxonomy(TaxonomyService::TAXONOMY_NAME);
        $this->assertNotFalse($taxonomy);

        global $wp_taxonomies;
        $args = $wp_taxonomies[TaxonomyService::TAXONOMY_NAME];

        $this->assertSame('_update_generic_term_count', $args->update_count_callback);
    }

    /**
     * Test register does not fail when called multiple times
     *
     * @return void
     */
    public function test_register_does_not_fail_when_called_twice(): void
    {
        TaxonomyService::register();
        TaxonomyService::register();

        $this->assertTrue(taxonomy_exists(TaxonomyService::TAXONOMY_NAME));
    }
}

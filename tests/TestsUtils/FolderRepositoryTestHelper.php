<?php

/**
 * Test-only helpers around FolderRepository
 *
 * Production code does not need to enumerate every folder in the system —
 * REST controllers and navigators use targeted/paginated reads instead.
 * Tests, however, occasionally need a flat dump for "are all folders gone?"
 * style assertions. That helper lives here, off the production surface.
 *
 * @package FoldSnap\Tests\TestsUtils
 */

declare(strict_types=1);

namespace FoldSnap\Tests\TestsUtils;

use FoldSnap\Models\FolderModel;
use FoldSnap\Services\TaxonomyService;
use WP_Term;

final class FolderRepositoryTestHelper
{
    /**
     * Retrieve all folders as a flat list of FolderModel instances
     *
     * @return FolderModel[]
     */
    public static function getAll(): array
    {
        $terms = get_terms(
            [
                'taxonomy'   => TaxonomyService::TAXONOMY_NAME,
                'hide_empty' => false,
            ]
        );

        if (! is_array($terms)) {
            return [];
        }

        $models = [];
        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $models[] = FolderModel::fromTerm($term);
            }
        }

        return $models;
    }
}

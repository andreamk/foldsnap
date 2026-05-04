<?php

/**
 * Folder name sanitization and uniqueness resolution.
 *
 * Stateless helper extracted from FolderRepository so the rules can be
 * exercised without touching the taxonomy in unit tests.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Services;

use InvalidArgumentException;

class FolderNameSanitizer
{
    /** @var int Maximum allowed folder name length (characters) */
    private const MAX_FOLDER_LENGTH = 200;

    /**
     * Sanitize a folder name
     *
     * Applies sanitize_text_field, removes dangerous leading characters
     * (Excel injection prevention), strips control characters, trims
     * whitespace, and enforces the max-length cap.
     *
     * @param string $name Raw folder name
     *
     * @return string Sanitized name
     *
     * @throws InvalidArgumentException If name is empty after sanitization.
     */
    public function sanitize(string $name): string
    {
        $name = sanitize_text_field($name);
        $name = ltrim($name, '=+@|');
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name);

        if (mb_strlen($name) > self::MAX_FOLDER_LENGTH) {
            $name = mb_substr($name, 0, self::MAX_FOLDER_LENGTH);
        }

        if ('' === $name) {
            throw new InvalidArgumentException(
                esc_html__('Folder name cannot be empty.', 'foldsnap')
            );
        }

        return $name;
    }

    /**
     * Ensure a folder name is unique among siblings
     *
     * Queries only for the exact name and "name (N)" variants via LIKE,
     * then finds the highest existing suffix and returns the next number.
     * For example, if "Photos" and "Photos (3)" exist, returns "Photos (4)".
     *
     * @param string $name      Sanitized folder name
     * @param int    $parentId  Parent term ID (0 for root)
     * @param int    $excludeId Term ID to exclude from check (for updates)
     *
     * @return string Unique name
     */
    public function ensureUnique(string $name, int $parentId, int $excludeId = 0): string
    {
        $matches = Database::getSiblingNameMatches(
            TaxonomyService::TAXONOMY_NAME,
            $name,
            $parentId,
            $excludeId
        );

        if (empty($matches)) {
            return $name;
        }

        $nameLower     = mb_strtolower($name);
        $exactConflict = false;

        foreach ($matches as $match) {
            if (mb_strtolower($match) === $nameLower) {
                $exactConflict = true;
                break;
            }
        }

        if (! $exactConflict) {
            return $name;
        }

        $maxSuffix = 1;
        $pattern   = '/^' . preg_quote($name, '/') . ' \((\d+)\)$/i';

        foreach ($matches as $match) {
            if (1 === preg_match($pattern, $match, $m)) {
                $maxSuffix = max($maxSuffix, (int) $m[1]);
            }
        }

        return sprintf('%s (%d)', $name, $maxSuffix + 1);
    }
}

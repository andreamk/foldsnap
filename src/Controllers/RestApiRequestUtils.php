<?php

/**
 * Shared request-parsing helpers for REST controllers
 *
 * Concentrates the boilerplate around extracting typed parameters from a
 * WP_REST_Request, parsing list-shaped query parameters (parent_ids[],
 * media_ids[]), and converting domain exceptions into WP_Error responses.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Controllers;

use Exception;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

trait RestApiRequestUtils
{
    /**
     * Execute a REST callback with standardized exception handling
     *
     * @param callable(): WP_REST_Response $callback Business logic returning a response
     *
     * @return WP_REST_Response|WP_Error
     */
    private function handleRestRequest(callable $callback)
    {
        try {
            return $callback();
        } catch (InvalidArgumentException $e) {
            return new WP_Error('invalid_argument', $e->getMessage(), ['status' => 400]);
        } catch (Exception $e) {
            return new WP_Error('server_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Parse parent_ids[] from request, accepting both array and CSV formats
     *
     * @param WP_REST_Request $request REST request object
     *
     * @return int[]
     */
    private function parseParentIds(WP_REST_Request $request): array
    {
        $raw = $request->get_param('parent_ids');

        if (is_string($raw) && '' !== $raw) {
            $raw = array_map('trim', explode(',', $raw));
        }

        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            $ids[] = absint(is_scalar($value) ? (string) $value : '');
        }

        return array_values(array_unique($ids));
    }

    /**
     * Parse media_ids from request body
     *
     * Filters non-positive IDs (0 / negative) since they cannot reference
     * a real attachment.
     *
     * @param WP_REST_Request $request REST request object
     *
     * @return int[]
     */
    private function parseMediaIds(WP_REST_Request $request): array
    {
        $rawIds = $request->get_param('media_ids');

        if (! is_array($rawIds)) {
            return [];
        }

        $intIds = [];
        foreach ($rawIds as $rawId) {
            $intIds[] = absint(is_scalar($rawId) ? (string) $rawId : '');
        }

        return array_values(array_filter($intIds, static fn (int $id): bool => $id > 0));
    }

    /**
     * Get an optional integer parameter, or null if absent.
     *
     * @param WP_REST_Request $request   REST request object
     * @param string          $paramName Parameter name
     *
     * @return int|null
     */
    private function getOptionalIntParam(WP_REST_Request $request, string $paramName): ?int
    {
        $value = $request->get_param($paramName);

        if (null === $value) {
            return null;
        }

        return absint(is_scalar($value) ? (string) $value : '');
    }

    /**
     * Get a string parameter, safely handling the mixed return type
     *
     * @param WP_REST_Request $request   REST request object
     * @param string          $paramName Parameter name
     *
     * @return string Parameter value, or '' if absent / non-scalar
     */
    private function getStringParam(WP_REST_Request $request, string $paramName): string
    {
        $value = $request->get_param($paramName);

        if (null === $value || ! is_scalar($value)) {
            return '';
        }

        return (string) $value;
    }
}

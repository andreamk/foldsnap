<?php

/**
 * Shared request-parsing helpers for REST controllers.
 *
 * Holds the bits that can't be expressed declaratively in `register_rest_route`:
 * typed parsing of `media_ids[]` and a try/catch wrapper that converts domain
 * exceptions into WP_Error responses.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Controllers;

use Exception;
use FoldSnap\Utils\Log;
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
            Log::exception($e, 'REST handler exception');

            return new WP_Error(
                'server_error',
                __('An unexpected error occurred.', 'foldsnap'),
                ['status' => 500]
            );
        }
    }

    /**
     * Parse media_ids[] from the request, dropping non-positive IDs.
     *
     * The route declares `'type' => 'array'` so `$request['media_ids']` is
     * already an array; this helper only normalises each element to a positive
     * int (0 / negative IDs cannot reference a real attachment).
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
}

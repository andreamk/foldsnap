<?php

/**
 * REST controller for per-user UI preferences.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Controllers;

use FoldSnap\Services\UserPreferencesService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class UserPreferencesRestController
{
    private const REST_NAMESPACE = 'foldsnap/v1';

    private UserPreferencesService $service;

    /**
     * @param UserPreferencesService $service Preferences storage service.
     */
    public function __construct(UserPreferencesService $service)
    {
        $this->service = $service;
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Register the GET/PUT preferences routes.
     *
     * @return void
     */
    public function registerRoutes(): void
    {
        register_rest_route(
            self::REST_NAMESPACE,
            '/preferences',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [
                    $this,
                    'getPreferences',
                ],
                'permission_callback' => [
                    $this,
                    'checkPermission',
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/preferences/(?P<key>[a-zA-Z0-9_]+)',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [
                    $this,
                    'setPreference',
                ],
                'permission_callback' => [
                    $this,
                    'checkPermission',
                ],
                'args'                => [
                    'key' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                    // `value` is intentionally untyped at the route level: its
                    // shape depends on the key, so the service is the only
                    // place that can validate it.
                ],
            ]
        );
    }

    /**
     * Permission callback shared by every preferences endpoint.
     *
     * @return bool
     */
    public function checkPermission(): bool
    {
        return current_user_can('upload_files');
    }

    /**
     * GET /foldsnap/v1/preferences
     *
     * @param WP_REST_Request $request REST request object.
     *
     * @return WP_REST_Response
     */
    public function getPreferences(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $userId = get_current_user_id();

        return new WP_REST_Response(
            ['preferences' => $this->service->getAll($userId)],
            200
        );
    }

    /**
     * PUT /foldsnap/v1/preferences/{key}
     *
     * @param WP_REST_Request $request REST request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function setPreference(WP_REST_Request $request)
    {
        /** @var string */
        $key   = $request['key'];
        $value = $request->get_param('value');

        if (! $this->service->isKnownKey($key)) {
            return new WP_Error(
                'foldsnap_unknown_preference',
                __('Unknown preference key.', 'foldsnap'),
                ['status' => 400]
            );
        }

        $userId = get_current_user_id();
        $ok     = $this->service->set($userId, $key, $value);

        if (! $ok) {
            return new WP_Error(
                'foldsnap_invalid_preference_value',
                __('Preference value is not valid for the declared type.', 'foldsnap'),
                ['status' => 400]
            );
        }

        return new WP_REST_Response(
            [
                'key'   => $key,
                'value' => $this->service->get($userId, $key),
            ],
            200
        );
    }
}

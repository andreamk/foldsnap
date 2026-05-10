<?php

/**
 * Tests for UserPreferencesRestController
 *
 * @package FoldSnap\Tests\Feature\Controllers
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Feature\Controllers;

use FoldSnap\Controllers\UserPreferencesRestController;
use FoldSnap\Services\UserPreferencesService;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

class UserPreferencesRestControllerTests extends WP_UnitTestCase
{
    private UserPreferencesService $service;

    /**
     * Set up test environment
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->service = new UserPreferencesService();
        // Construct the controller before rest_api_init so its registerRoutes
        // listener fires when the action is dispatched below.
        new UserPreferencesRestController($this->service);

        $userId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);

        /** @var \WP_REST_Server $wp_rest_server */
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init');
    }

    /**
     * Test unauthorized user gets 401 on GET /preferences
     *
     * @return void
     */
    public function test_unauthorized_user_gets_401_on_get(): void
    {
        wp_set_current_user(0);

        $response = $this->dispatchRequest(new WP_REST_Request('GET', '/foldsnap/v1/preferences'));

        $this->assertSame(401, $response->get_status());
    }

    /**
     * Test subscriber (no upload_files capability) gets 403 on PUT
     *
     * @return void
     */
    public function test_subscriber_gets_403_on_put(): void
    {
        $subscriberId = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriberId);

        $request = new WP_REST_Request('PUT', '/foldsnap/v1/preferences/allMedia');
        $request->set_param('value', true);
        $response = $this->dispatchRequest($request);

        $this->assertSame(403, $response->get_status());
    }

    /**
     * Test GET returns declared defaults when the user has nothing stored
     *
     * @return void
     */
    public function test_get_returns_defaults_when_user_has_no_stored_prefs(): void
    {
        $response = $this->dispatchRequest(new WP_REST_Request('GET', '/foldsnap/v1/preferences'));
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertArrayHasKey('preferences', $data);
        $this->assertSame([], $data['preferences']['expandedFolders']);
        $this->assertFalse($data['preferences']['allMedia']);
    }

    /**
     * Test GET reads back the values written by a previous PUT
     *
     * @return void
     */
    public function test_get_returns_stored_values_after_put(): void
    {
        $put = new WP_REST_Request('PUT', '/foldsnap/v1/preferences/expandedFolders');
        $put->set_param('value', [4, 5, 6]);
        $this->dispatchRequest($put);

        $response = $this->dispatchRequest(new WP_REST_Request('GET', '/foldsnap/v1/preferences'));
        $data     = $response->get_data();

        $this->assertSame([4, 5, 6], $data['preferences']['expandedFolders']);
    }

    /**
     * Test PUT response carries the type-coerced value back
     *
     * @return void
     */
    public function test_put_returns_coerced_value_in_response(): void
    {
        $request = new WP_REST_Request('PUT', '/foldsnap/v1/preferences/expandedFolders');
        $request->set_param('value', [1, 'abc', 5, -2]);
        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('expandedFolders', $data['key']);
        $this->assertSame([1, 5], $data['value']);
    }

    /**
     * Test PUT then GET sees the persisted value
     *
     * @return void
     */
    public function test_put_then_get_roundtrip(): void
    {
        $put = new WP_REST_Request('PUT', '/foldsnap/v1/preferences/allMedia');
        $put->set_param('value', true);
        $this->dispatchRequest($put);

        $response = $this->dispatchRequest(new WP_REST_Request('GET', '/foldsnap/v1/preferences'));
        $data     = $response->get_data();

        $this->assertTrue($data['preferences']['allMedia']);
    }

    /**
     * Test PUT on an unknown key returns 400 with the dedicated error code
     *
     * @return void
     */
    public function test_put_unknown_key_returns_400_with_specific_code(): void
    {
        $request = new WP_REST_Request('PUT', '/foldsnap/v1/preferences/unknownKey');
        $request->set_param('value', 'whatever');
        $response = $this->dispatchRequest($request);

        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('foldsnap_unknown_preference', $data['code']);
    }

    /**
     * Test PUT with a non-coercible bool value returns 400 with the dedicated error code
     *
     * @return void
     */
    public function test_put_invalid_bool_value_returns_400_with_specific_code(): void
    {
        $request = new WP_REST_Request('PUT', '/foldsnap/v1/preferences/allMedia');
        $request->set_param('value', 'definitelyNotABool');
        $response = $this->dispatchRequest($request);

        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('foldsnap_invalid_preference_value', $data['code']);
    }

    /**
     * Test PUT on int_array with a non-array value returns 400
     *
     * @return void
     */
    public function test_put_int_array_with_non_array_value_returns_400(): void
    {
        $request = new WP_REST_Request('PUT', '/foldsnap/v1/preferences/expandedFolders');
        $request->set_param('value', 'not an array');
        $response = $this->dispatchRequest($request);

        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('foldsnap_invalid_preference_value', $data['code']);
    }

    /**
     * Test that two users see independent preferences via the REST endpoint
     *
     * @return void
     */
    public function test_two_users_see_separate_preferences(): void
    {
        $put = new WP_REST_Request('PUT', '/foldsnap/v1/preferences/expandedFolders');
        $put->set_param('value', [42]);
        $this->dispatchRequest($put);

        $otherUserId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($otherUserId);

        $response = $this->dispatchRequest(new WP_REST_Request('GET', '/foldsnap/v1/preferences'));
        $data     = $response->get_data();

        $this->assertSame([], $data['preferences']['expandedFolders']);
    }

    /**
     * Dispatch a REST request via the global server
     *
     * @param WP_REST_Request $request REST request object
     *
     * @return WP_REST_Response
     */
    private function dispatchRequest(WP_REST_Request $request): WP_REST_Response
    {
        return rest_do_request($request);
    }
}

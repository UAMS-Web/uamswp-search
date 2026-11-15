<?php

/**
 * The limits the live-suggestion route sets on its search text, at each edge.
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use UAMS_Search\Tests\Support\IntegrationTestCase;
use WP_REST_Request;
use WP_REST_Server;

final class SuggestArgsTest extends IntegrationTestCase
{
    private mixed $old_server = null;

    public function set_up(): void
    {
        parent::set_up();
        $this->old_server = $GLOBALS['wp_rest_server'] ?? null;
        $this->settings(['enabled' => true, 'live' => true, 'types' => ['page' => 1.0], 'fields' => ['page' => []], 'taxonomies' => ['page' => []]]);
    }

    public function tear_down(): void
    {
        $GLOBALS['wp_rest_server'] = $this->old_server;
        parent::tear_down();
    }

    private function suggest_status(string $s): int
    {
        $GLOBALS['wp_rest_server'] = $server = new WP_REST_Server;
        do_action('rest_api_init', $server);
        $request = new WP_REST_Request('GET', '/uams-search/v1/suggest');
        $request->set_param('s', $s);

        return $server->dispatch($request)->get_status();
    }

    public function test_the_search_text_must_be_between_two_and_two_hundred_characters(): void
    {
        $this->assertSame(400, $this->suggest_status('a'), 'One character is too short');
        $this->assertSame(200, $this->suggest_status('ab'), 'Two characters are enough');
        $this->assertSame(200, $this->suggest_status(str_repeat('a', 200)), 'Two hundred characters are allowed');
        $this->assertSame(400, $this->suggest_status(str_repeat('a', 201)), 'Two hundred and one are too long');
    }
}

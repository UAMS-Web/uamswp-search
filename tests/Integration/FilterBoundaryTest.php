<?php

/**
 * Filters that return the wrong type, on the paths that need a running WordPress.
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use UAMS_Search\Integration;
use UAMS_Search\Tests\Support\IntegrationTestCase;

final class FilterBoundaryTest extends IntegrationTestCase
{
    public function test_a_template_filter_returning_another_type_keeps_the_plugin_template(): void
    {
        $this->settings(['enabled' => true]);
        $this->go_to('/?s=anything');
        $GLOBALS['wp_query']->set('uams_search_results', ['results' => [], 'total' => 0, 'pages' => 0, 'fallback' => false, 'suggestion' => null]);
        $filter = static fn (): array => ['not', 'a', 'path'];
        add_filter('uams_search_template', $filter);
        try {
            $template = Integration::template('/theme/search.php');
        } finally {
            remove_filter('uams_search_template', $filter);
        }

        $this->assertStringEndsWith('/templates/search.php', str_replace('\\', '/', $template));
    }
}

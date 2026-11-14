<?php

/**
 * The search page: the main query, template, excerpt, form, assets, suggestions endpoint and results markup.
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use UAMS_Search\Config;
use UAMS_Search\DB;
use UAMS_Search\Indexer;
use UAMS_Search\Integration;
use UAMS_Search\Result;
use UAMS_Search\Tests\Support\IntegrationTestCase;
use WP_Query;
use WP_REST_Request;
use WP_REST_Server;

final class SearchPageTest extends IntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $globals = [];

    private int $user = 0;

    private string $word = '';

    /** The scope this site searches by default: `site` on a single site, `install` on the main site of a multisite. */
    private string $scope = 'site';

    public function set_up(): void
    {
        parent::set_up();
        $this->globals = ['wp_query' => $GLOBALS['wp_query'], 'wp_the_query' => $GLOBALS['wp_the_query'], 'get' => $_GET, 'rest' => $GLOBALS['wp_rest_server'] ?? null];
        $this->user = get_current_user_id();
        wp_set_current_user(0);
        $this->configure([]);
        $this->scope = Config::scope();
        $this->word = 'searchpage'.strtolower(wp_generate_password(8, false, false));
        Integration::$excerpts = [];
        $_GET = [];
    }

    public function tear_down(): void
    {
        $GLOBALS['wp_query'] = $this->globals['wp_query'];
        $GLOBALS['wp_the_query'] = $this->globals['wp_the_query'];
        $_GET = $this->globals['get'];
        $GLOBALS['wp_rest_server'] = $this->globals['rest'];
        wp_set_current_user($this->user);
        Integration::$excerpts = [];
        $this->reset_assets();
        remove_all_filters('template');
        remove_all_filters('stylesheet');
        remove_all_filters('uams_search_template');
        remove_all_filters('uams_search_google_cx');
        $this->restore_index_table();
        parent::tear_down();
    }

    private function reset_assets(): void
    {
        wp_dequeue_style('uams-search');
        wp_dequeue_script('uams-search');
        wp_deregister_style('uams-search');
        wp_deregister_script('uams-search');
    }

    /** @param array<string, mixed> $changes Settings applied over a configuration where search is on. */
    private function configure(array $changes): void
    {
        $this->settings(array_replace(['enabled' => true, 'live' => true, 'types' => ['page' => 1.0], 'fields' => ['page' => []], 'taxonomies' => ['page' => []]], $changes));
    }

    private function indexed(string $title): int
    {
        $id = $this->make_page(['post_title' => $title]);
        Indexer::one($this->site, $id);

        return $id;
    }

    /** @param array<string, mixed> $get The query string. */
    private function search(string $s, array $get = []): WP_Query
    {
        $_GET = $get;
        $query = new WP_Query;
        $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = $query;
        $query->query(['s' => $s]);

        return $query;
    }

    private function render(WP_Query $query): string
    {
        $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = $query;
        ob_start();
        Integration::render();

        return (string) ob_get_clean();
    }

    private function rename_index_table(): void
    {
        global $wpdb;
        $wpdb->suppress_errors(true);
        $wpdb->query('RENAME TABLE `'.DB::table().'` TO `'.DB::table().'_away`');
    }

    private function restore_index_table(): void
    {
        global $wpdb;
        if ($wpdb->get_var("SHOW TABLES LIKE '".DB::table()."_away'") !== null) {
            $wpdb->query('RENAME TABLE `'.DB::table().'_away` TO `'.DB::table().'`');
        }

        $wpdb->suppress_errors(false);
    }

    /** Runs a request against the registered routes. */
    private function request(array $params): \WP_REST_Response
    {
        $GLOBALS['wp_rest_server'] = $server = new WP_REST_Server;
        do_action('rest_api_init', $server);
        $request = new WP_REST_Request('GET', '/uams-search/v1/suggest');
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $server->dispatch($request);
    }

    public function test_a_signed_in_user_can_ask_for_the_wordpress_engine(): void
    {
        $this->assertTrue(Integration::active());
        wp_set_current_user(1);
        $_GET = ['engine' => 'wordpress'];
        $this->assertFalse(Integration::active(), 'engine=wordpress turns the plugin off for a signed-in user');
        $_GET = ['engine' => 'uams'];
        $this->assertTrue(Integration::preview());
        $this->configure(['enabled' => false]);
        $this->assertTrue(Integration::active(), 'A preview answers while visitor search is off');
        $_GET = [];
        $this->assertFalse(Integration::active(), 'Without engine=uams the off switch holds');
        wp_set_current_user(0);
        $_GET = ['engine' => 'uams'];
        $this->assertFalse(Integration::preview(), 'A visitor cannot preview');
    }

    public function test_the_main_search_answers_from_the_index_and_keeps_the_excerpt(): void
    {
        $id = $this->indexed('Sentinel '.$this->word);
        $query = $this->search($this->word);

        $this->assertSame([$id], wp_list_pluck($query->posts, 'ID'));
        $this->assertSame($this->site, $query->posts[0]->blog_id);
        $this->assertSame(1, $query->found_posts);
        $this->assertFalse($query->get('cache_results'));
        $this->assertFalse($query->get('update_post_meta_cache'));
        $this->assertFalse($query->get('update_post_term_cache'));
        $this->assertIsArray($query->get('uams_search_results'));
        $this->assertSame($query->get('uams_search_results')['results'][0]->excerpt, Integration::$excerpts[$this->site.':'.$id]);
        $this->assertFalse(Integration::allow_other_engine(true, $query), 'Other engines stand aside for an answered query');
        $this->assertTrue(Integration::allow_other_engine(true, new WP_Query), 'and keep every other query');
        $this->assertTrue(Integration::allow_other_engine(true, 'not a query'));
    }

    public function test_all_websites_is_answered_by_google_and_searches_nothing(): void
    {
        $this->indexed('Sentinel '.$this->word);
        $query = $this->search($this->word, ['scope' => 'all']);

        $this->assertSame([], $query->posts);
        $this->assertSame(0, $query->found_posts);
        $this->assertSame(0, $query->max_num_pages);
        $this->assertTrue($query->get('uams_search_results')['google']);
    }

    public function test_other_queries_are_left_to_wordpress(): void
    {
        $this->indexed('Sentinel '.$this->word);
        $this->assertSame(['x'], Integration::posts(['x'], new WP_Query), 'Posts another filter chose are kept');

        $this->configure(['enabled' => false]);
        $query = $this->search($this->word);
        $this->assertNull($query->get('uams_search_results') ?: null, 'An inactive plugin leaves the search alone');
    }

    public function test_a_failed_search_falls_back_to_wordpress_and_logs_why(): void
    {
        $this->indexed('Sentinel '.$this->word);
        $log = tempnam(sys_get_temp_dir(), 'uamslog');
        $previous = ini_set('error_log', $log);
        $this->rename_index_table();
        try {
            $query = $this->search($this->word);
        } finally {
            $this->restore_index_table();
            ini_set('error_log', (string) $previous);
        }

        $this->assertFalse((bool) $query->get('uams_search_results'), 'A failed search leaves no results behind');
        $this->assertStringContainsString('UAMS Search: search failed; using WordPress.', (string) file_get_contents($log));
        unlink($log);
    }

    public function test_the_template_is_the_plugins_unless_a_theme_or_filter_says_otherwise(): void
    {
        $this->indexed('Sentinel '.$this->word);
        $this->search($this->word);

        $this->assertStringEndsWith('/templates/search.php', Integration::template('theme.php'));
        add_filter('uams_search_template', static fn (): string => '/custom/search.php');
        $this->assertSame('/custom/search.php', Integration::template('theme.php'));
        remove_all_filters('uams_search_template');

        add_filter('template', static fn (): string => 'genesis');
        $this->assertStringEndsWith('/templates/search-genesis.php', Integration::template('theme.php'));
        remove_all_filters('template');

        add_filter('stylesheet', static fn (): string => 'uams-2020');
        $this->assertStringEndsWith('/templates/search-genesis.php', Integration::template('theme.php'));
        remove_all_filters('stylesheet');

        $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query;
        $this->assertSame('theme.php', Integration::template('theme.php'), 'Another page keeps the theme template');

        $this->search($this->word);
        $this->configure(['enabled' => false]);
        $this->assertSame('theme.php', Integration::template('theme.php'), 'An inactive plugin keeps the theme template');
    }

    public function test_the_excerpt_is_the_plugins_for_a_result_and_the_themes_for_anything_else(): void
    {
        $id = $this->indexed('Sentinel '.$this->word);
        $query = $this->search($this->word);
        $post = $query->posts[0];
        $saved = Integration::$excerpts[$this->site.':'.$id];

        $this->assertSame($saved, Integration::excerpt('theme excerpt', $post));
        $plain = get_post($id);
        $this->assertSame($saved, Integration::excerpt('theme excerpt', $plain), 'A post without blog_id is read on the current site');
        $this->assertSame('theme excerpt', Integration::excerpt('theme excerpt', null));
        $this->assertSame('theme excerpt', Integration::excerpt('theme excerpt', get_post($this->make_page())), 'A post that was not a result keeps its excerpt');

        $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query;
        $this->assertSame('theme excerpt', Integration::excerpt('theme excerpt', $post), 'Outside a search the excerpt is left alone');

        $this->search($this->word);
        $this->configure(['enabled' => false]);
        $this->assertSame('theme excerpt', Integration::excerpt('theme excerpt', $post), 'An inactive plugin leaves the excerpt alone');
    }

    public function test_the_form_carries_the_scope_the_preview_and_the_type(): void
    {
        $_GET = ['scope' => $this->scope, 'type' => 'page'];
        $form = Integration::form('<form>theme</form>');
        $this->assertStringContainsString('class="uams-search-form"', $form);
        $this->assertStringContainsString('Search This Website', $form);
        $this->assertStringContainsString('name="scope" value="'.$this->scope.'"', $form);
        $this->assertStringContainsString('<input type="hidden" name="type" value="page">', $form);
        $this->assertStringContainsString('data-uams-live="true"', $form);
        $this->assertStringNotContainsString('name="engine"', $form, 'A visitor has no preview field');
        $this->assertStringEndsWith('</form>', $form);

        $_GET = ['type' => 'nosuchtype'];
        $this->assertStringNotContainsString('name="type"', Integration::form(''), 'An unknown type is not carried');

        $this->configure(['live' => false]);
        $this->assertStringContainsString('data-uams-live="false"', Integration::form(''));

        wp_set_current_user(1);
        $_GET = ['engine' => 'uams'];
        $this->assertStringContainsString('<input type="hidden" name="engine" value="uams">', Integration::form(''));

        $this->configure(['enabled' => false]);
        $_GET = [];
        $this->assertSame('<form>theme</form>', Integration::form('<form>theme</form>'), 'An inactive plugin keeps the theme form');
    }

    public function test_the_assets_load_only_while_the_plugin_is_active(): void
    {
        Integration::assets();
        $this->assertTrue(wp_style_is('uams-search', 'enqueued'));
        $this->assertTrue(wp_script_is('uams-search', 'enqueued'));
        $data = (string) wp_scripts()->get_data('uams-search', 'data');
        $this->assertStringContainsString('UAMSSearch', $data);
        $this->assertStringContainsString('uams-search/v1/suggest', $data);
        $this->assertStringContainsString('"preview":""', $data);
        $this->assertStringContainsString('"nonce":""', $data, 'A visitor gets no nonce');

        $this->reset_assets();
        wp_set_current_user(1);
        $_GET = ['engine' => 'uams'];
        Integration::assets();
        $data = (string) wp_scripts()->get_data('uams-search', 'data');
        $this->assertStringContainsString('"preview":"1"', $data);
        $this->assertDoesNotMatchRegularExpression('/"nonce":""/', $data, 'A signed-in user gets a nonce');

        $this->reset_assets();
        $this->configure(['live' => false]);
        Integration::assets();
        $this->assertTrue(wp_style_is('uams-search', 'enqueued'), 'The stylesheet loads without live search');
        $this->assertFalse(wp_script_is('uams-search', 'enqueued'), 'The live search script does not');

        $this->reset_assets();
        $this->configure(['enabled' => false]);
        $_GET = [];
        Integration::assets();
        $this->assertFalse(wp_style_is('uams-search', 'enqueued'), 'An inactive plugin loads nothing');
    }

    public function test_the_suggestions_endpoint_answers_up_to_six_matches(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->indexed('Sentinel '.$this->word.' '.$i);
        }

        $response = $this->request(['s' => $this->word]);
        $this->assertSame(200, $response->get_status());
        $this->assertSame('no-store', $response->get_headers()['Cache-Control']);
        $data = $response->get_data();
        $this->assertCount(6, $data['items'], 'Six at most');
        $this->assertSame(7, $data['total']);
        $this->assertSame(['id', 'site', 'title', 'url'], array_keys($data['items'][0]));
        $this->assertSame($this->site, $data['items'][0]['site']);
        $this->assertStringStartsWith('Sentinel '.$this->word, $data['items'][0]['title']);
        $this->assertStringStartsWith('http://example.org/', $data['items'][0]['url']);

        $this->assertSame(400, $this->request(['s' => $this->word, 'scope' => 'nowhere'])->get_status(), 'An unknown scope is refused');
        $this->assertSame(200, $this->request(['s' => $this->word, 'scope' => $this->scope, 'type' => 'page'])->get_status());
        $this->assertSame(0, $this->request(['s' => $this->word, 'type' => 'post'])->get_data()['total'], 'The type narrows the matches');
    }

    public function test_the_suggestions_endpoint_checks_who_may_ask(): void
    {
        $this->indexed('Sentinel '.$this->word);
        $this->configure(['live' => false]);
        $this->assertSame(401, $this->request(['s' => $this->word])->get_status(), 'Live suggestions off');

        $this->configure(['enabled' => false]);
        $this->assertSame(401, $this->request(['s' => $this->word])->get_status(), 'Search off for visitors');
        $this->assertSame(401, $this->request(['s' => $this->word, 'engine' => 'uams'])->get_status(), 'A visitor cannot preview');

        wp_set_current_user(1);
        $this->assertSame(200, $this->request(['s' => $this->word, 'engine' => 'uams'])->get_status(), 'A signed-in user can preview');
        $this->assertSame(403, $this->request(['s' => $this->word])->get_status(), 'Without engine=uams the off switch holds for them too');
    }

    public function test_the_suggestions_endpoint_reports_a_database_failure_as_unavailable(): void
    {
        $this->indexed('Sentinel '.$this->word);
        $log = tempnam(sys_get_temp_dir(), 'uamslog');
        $previous = ini_set('error_log', $log);
        $this->rename_index_table();
        try {
            $response = $this->request(['s' => $this->word]);
        } finally {
            $this->restore_index_table();
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame(503, $response->get_status());
        $this->assertSame('uams_search_unavailable', $response->get_data()['code']);
        $this->assertSame('Search suggestions are unavailable.', $response->get_data()['message']);
        $this->assertStringContainsString('UAMS Search: REST ', (string) file_get_contents($log));
        unlink($log);
    }

    public function test_the_results_page_prints_the_heading_filters_results_and_count(): void
    {
        $this->indexed('Sentinel '.$this->word);
        $html = $this->render($this->search($this->word, ['scope' => $this->scope]));

        $this->assertStringContainsString('<h1>Search Results for: '.$this->word.'</h1>', $html);
        $this->assertStringContainsString('<h2>1 result</h2>', $html);
        $this->assertStringContainsString('aria-label="Search scope"', $html);
        $this->assertStringContainsString('aria-label="Result type"', $html);
        $this->assertStringContainsString('<a href="'.esc_url(add_query_arg(['s' => $this->word, 'scope' => $this->scope], home_url('/'))).'" aria-current="true">All results</a>', $html);
        $this->assertStringContainsString('<article class="uams-search-result">', $html);
        $this->assertStringContainsString('Sentinel '.$this->word, $html);
        $this->assertStringContainsString('UAMS.edu and UAMSHealth.com', $html, 'The Google scope carries its note');
        $this->assertStringNotContainsString('No results found', $html);
        $this->assertStringNotContainsString('Not every word matched', $html);
        $this->assertStringEndsWith('</section>', $html);
    }

    public function test_a_search_with_no_matches_says_so_and_is_recorded_once(): void
    {
        global $wpdb;
        $miss = 'uamsfixture'.strtolower(wp_generate_password(6, false, false));
        $html = $this->render($this->search($miss, ['scope' => $this->scope]));

        $this->assertStringContainsString('<h2>0 results</h2>', $html);
        $this->assertStringContainsString('<p>No results found. Try fewer words or check the spelling.</p>', $html);
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.DB::table('misses').'` WHERE site_id=%d AND search_text=%s', $this->site, $miss)));

        $later = $this->search($miss, ['scope' => $this->scope]);
        $later->set('paged', 2);
        $this->render($later);
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare('SELECT SUM(count) FROM `'.DB::table('misses').'` WHERE site_id=%d AND search_text=%s', $this->site, $miss)), 'A later page is not counted again');
    }

    public function test_a_partial_match_is_labeled_as_one(): void
    {
        $this->indexed('Sentinel '.$this->word);
        $html = $this->render($this->search($this->word.' uamsnomatchzzz', ['scope' => $this->scope]));

        $this->assertStringContainsString('<p>Not every word matched. These results match some of your words.</p>', $html);
        $this->assertStringContainsString('Sentinel '.$this->word, $html);
    }

    public function test_a_misspelling_offers_a_correction_link(): void
    {
        $this->indexed('Cardiovascularology '.$this->word);
        $html = $this->render($this->search('cardiovascularolgy', ['scope' => $this->scope]));

        $this->assertMatchesRegularExpression('#<p>Did you mean <a href="[^"]*s=cardiovascularology[^"]*">cardiovascularology</a>\?</p>#', $html);
    }

    public function test_the_all_websites_page_prints_the_google_block_and_no_types(): void
    {
        $query = $this->search($this->word, ['scope' => 'all']);
        $html = $this->render($query);

        $this->assertStringContainsString('class="uams-search-google"', $html);
        $this->assertStringContainsString('cx=014806496997774146681%3Apyl1jhwi-bo', $html);
        $this->assertStringNotContainsString('aria-label="Result type"', $html);
        $this->assertStringNotContainsString('result</h2>', $html);

        add_filter('uams_search_google_cx', static fn (): string => 'custom-engine');
        $this->assertStringContainsString('cx=custom-engine', $this->render($query));
    }

    public function test_a_page_that_was_not_answered_prints_nothing(): void
    {
        $this->configure(['enabled' => false]);
        $this->assertSame('', $this->render($this->search($this->word)));
    }

    public function test_the_page_address_keeps_the_search_the_type_and_the_preview(): void
    {
        wp_set_current_user(1);
        $_GET = ['scope' => $this->scope, 'type' => 'page', 'engine' => 'uams'];
        $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query(['s' => 'findme']);
        $url = Integration::url(['paged' => 2]);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $args);
        $this->assertSame(['s' => 'findme', 'scope' => $this->scope, 'type' => 'page', 'engine' => 'uams', 'paged' => '2'], $args);

        parse_str((string) parse_url(Integration::url(['type' => false, 'engine' => false]), PHP_URL_QUERY), $without);
        $this->assertSame(['s' => 'findme', 'scope' => $this->scope], $without, 'A false value removes an argument');
    }

    public function test_the_query_string_values_are_cleaned_and_scalar_only(): void
    {
        $_GET = ['a' => "  <b>x</b>\n", 'b' => ['nested']];
        $this->assertSame('x', Integration::param('a'));
        $this->assertSame('fallback', Integration::param('b', 'fallback'), 'An array falls back');
        $this->assertSame('', Integration::param('missing'));
        $this->assertSame('dflt', Integration::param('missing', 'dflt'));
        $this->assertSame(Config::scope('site'), Config::scope(Integration::param('scope')));
    }

    /** Parses a query that is the main search, without running it. */
    private function unrun(array $vars, bool $main = true): WP_Query
    {
        $query = new WP_Query;
        $query->parse_query($vars);
        if ($main) {
            $GLOBALS['wp_the_query'] = $query;
        }

        return $query;
    }

    private function without_index_version(): void
    {
        update_site_option('uams_search_version', 'older');
    }

    private function with_index_version(): void
    {
        update_site_option('uams_search_version', UAMS_SEARCH_SCHEMA_VERSION);
    }

    public function test_the_plugin_is_off_until_the_index_exists(): void
    {
        $this->assertTrue(Integration::active());
        $this->without_index_version();
        try {
            $this->assertFalse(Integration::active());
            $this->assertFalse($this->request(['s' => $this->word])->get_status() === 200, 'The suggestions endpoint is closed too');
        } finally {
            $this->with_index_version();
        }
    }

    public function test_a_visitor_cannot_turn_the_plugin_off_with_the_engine_parameter(): void
    {
        $_GET = ['engine' => 'wordpress'];
        $this->assertTrue(Integration::active(), 'engine=wordpress only means something to a signed-in user');
    }

    public function test_only_the_main_search_query_is_answered(): void
    {
        $id = $this->indexed('Sentinel '.$this->word);
        $_GET = [];

        $this->assertSame(['x'], Integration::posts(['x'], $this->unrun(['s' => $this->word])), 'Posts already chosen are kept');

        $other = $this->unrun(['s' => $this->word], false);
        $GLOBALS['wp_the_query'] = new WP_Query;
        $this->assertNull(Integration::posts(null, $other), 'A secondary query is left alone');
        $this->assertSame('', $other->get('uams_search_results'));

        $this->assertNull(Integration::posts(null, $this->unrun([])), 'A query that is not a search is left alone');
        $this->assertNull(Integration::posts(null, $this->unrun(['s' => $this->word, 'fields' => 'ids'])), 'A query for IDs only is left alone');

        set_current_screen('dashboard');
        try {
            $this->assertNull(Integration::posts(null, $this->unrun(['s' => $this->word])), 'An admin search is left alone');
        } finally {
            set_current_screen('front');
        }

        $answered = Integration::posts(null, $this->unrun(['s' => $this->word]));
        $this->assertSame([$id], wp_list_pluck($answered, 'ID'), 'The same query is answered once nothing disqualifies it');
    }

    public function test_the_type_and_page_in_the_address_narrow_the_answer(): void
    {
        $this->indexed('Sentinel '.$this->word);

        $this->assertSame([], $this->search($this->word, ['type' => 'post'])->posts, 'Another content type finds nothing');
        $this->assertCount(1, $this->search($this->word, ['type' => 'page'])->posts);
        $query = $this->search($this->word);
        $this->assertSame(Config::page_size(), $query->get('posts_per_page'), 'The page size comes from the settings');
        $this->assertSame(1, $query->max_num_pages);
    }

    public function test_a_failed_search_hands_the_query_back_to_wordpress(): void
    {
        $id = $this->indexed('Sentinel '.$this->word);
        $log = tempnam(sys_get_temp_dir(), 'uamslog');
        $previous = ini_set('error_log', $log);
        $this->rename_index_table();
        try {
            $query = $this->search($this->word);
        } finally {
            $this->restore_index_table();
            ini_set('error_log', (string) $previous);
            unlink($log);
        }

        $this->assertContains($id, wp_list_pluck($query->posts, 'ID'), 'WordPress ran its own search');
    }

    public function test_the_template_needs_a_search_the_plugin_answered(): void
    {
        $this->indexed('Sentinel '.$this->word);

        $plain = new WP_Query;
        $plain->set('uams_search_results', ['results' => []]);
        $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = $plain;
        $this->assertSame('theme.php', Integration::template('theme.php'), 'Results on a query that is not a search do not count');

        $this->configure(['enabled' => false]);
        $query = $this->search($this->word);
        $this->configure(['enabled' => true]);
        $GLOBALS['wp_query'] = $query;
        $this->assertTrue($query->is_search());
        $this->assertSame('theme.php', Integration::template('theme.php'), 'A search the plugin did not answer keeps the theme template');
    }

    public function test_the_form_has_the_site_address_and_a_length_limit(): void
    {
        $form = Integration::form('');

        $this->assertStringContainsString('action="'.esc_url(home_url('/')).'"', $form);
        $this->assertStringContainsString('maxlength="200"', $form);
        $this->assertMatchesRegularExpression('/<label for="(uams-search-\d+)">Search This Website<\/label>.*<input type="search" name="s" id="\1"/', $form, 'The label and input share an id');
    }

    public function test_the_live_script_loads_in_the_footer(): void
    {
        Integration::assets();

        $this->assertSame(1, wp_scripts()->get_data('uams-search', 'group'));
    }

    public function test_a_result_that_is_a_pdf_says_so_in_the_suggestions(): void
    {
        $this->configure(['types' => ['page' => 1.0, 'attachment' => 1.0], 'pdf' => true]);
        $upload = wp_upload_bits('uamsfixture-text.pdf', null, (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/text.pdf'));
        $pdf = $this->track(wp_insert_attachment(['post_mime_type' => 'application/pdf', 'post_status' => 'inherit', 'post_title' => 'Sentinel '.$this->word], $upload['file'], $this->make_page()));
        Indexer::one($this->site, $pdf);

        $items = $this->request(['s' => $this->word, 'type' => 'attachment'])->get_data()['items'];
        $this->assertCount(1, $items);
        $this->assertMatchesRegularExpression('/^Sentinel '.$this->word.' \(PDF, [\d.]+ [KM]?B\)$/', $items[0]['title']);
    }

    public function test_each_result_prints_its_label_title_size_and_excerpt(): void
    {
        $query = $this->search($this->word);
        $query->set('uams_search_results', [
            'results' => [
                new Result(1, $this->site, 1.0, 'plain <mark>marked</mark> <b>bold</b><script>x()</script>', '', 'http://example.org/?p=1', 'page', 0),
                new Result(2, $this->site, 1.0, 'brief', 'A file', 'http://example.org/?p=2', 'attachment', 2048),
                new Result(3, $this->site, 1.0, 'no label', 'Unlabeled', 'http://example.org/?p=3', 'uamsnolabel', 0),
            ],
            'total' => 3, 'pages' => 1, 'fallback' => false, 'suggestion' => null,
        ]);
        $html = $this->render($query);

        $this->assertStringContainsString('<p class="uams-search-label">Page</p><h3><a href="http://example.org/?p=1">Untitled</a></h3>', $html, 'A page with no title is shown as Untitled');
        $this->assertStringContainsString('<p>plain <mark>marked</mark> boldx()</p>', $html, 'Only mark survives in the excerpt');
        $this->assertStringContainsString('A file <span>(2 KB)</span></a>', $html, 'A PDF shows its size');
        $this->assertStringContainsString('<p class="uams-search-label">PDF</p>', $html);
        $this->assertStringContainsString('<article class="uams-search-result"><h3><a href="http://example.org/?p=3">Unlabeled</a>', $html, 'A type with no label has no label line');
        $this->assertSame(1, substr_count($html, '<span>('), 'Only the PDF has a size');
    }

    public function test_the_page_links_appear_only_past_one_page(): void
    {
        $query = $this->search($this->word);
        $query->set('uams_search_results', ['results' => [], 'total' => 45, 'pages' => 3, 'fallback' => false, 'suggestion' => null]);

        $html = $this->render($query);

        $this->assertStringContainsString('<nav aria-label="Search result pages">', $html);
        $this->assertStringContainsString('page-numbers', $html);
        $this->assertStringContainsString('<h2>45 results</h2>', $html);

        $query->set('uams_search_results', ['results' => [], 'total' => 5, 'pages' => 1, 'fallback' => false, 'suggestion' => null]);
        $this->assertStringNotContainsString('Search result pages', $this->render($query));
    }

    public function test_the_filters_mark_the_current_scope_and_type_and_offer_only_usable_types(): void
    {
        $this->configure(['types' => ['page' => 1.0, 'attachment' => 1.0, 'nosuchtype' => 1.0], 'pdf' => false]);
        add_filter('uams_search_type_labels', static fn (array $labels): array => $labels + ['page' => 'Custom pages']);
        $query = $this->search($this->word, ['scope' => $this->scope, 'type' => 'page']);
        $html = $this->render($query);

        $this->assertMatchesRegularExpression('#<a href="[^"]*scope='.$this->scope.'[^"]*" aria-current="true">This site</a>#', $html, 'The current scope is marked');
        $this->assertDoesNotMatchRegularExpression('#scope=all[^>]*aria-current#', $html, 'Another scope is not');
        $this->assertMatchesRegularExpression('#type=page[^>]*aria-current="true">Custom pages</a>#', $html, 'The current type is marked, with the filtered label');
        $this->assertStringContainsString('>All results</a>', $html);
        $this->assertStringNotContainsString('aria-current="true">All results', $html, 'All results is not current while a type is');
        $this->assertStringNotContainsString('>PDFs</a>', $html, 'PDFs are not offered while PDF search is off');
        $this->assertStringNotContainsString('nosuchtype', $html, 'A type that does not exist is not offered');

        remove_all_filters('uams_search_type_labels');
        $this->configure(['types' => ['page' => 1.0, 'attachment' => 1.0], 'pdf' => true]);
        $html = $this->render($this->search($this->word, ['scope' => $this->scope]));
        $this->assertStringContainsString('>PDFs</a>', $html, 'PDFs are offered once PDF search is on');
        $this->assertStringContainsString('<a href="'.esc_url(add_query_arg(['s' => $this->word, 'scope' => $this->scope], home_url('/'))).'" aria-current="true">All results</a>', $html);
    }

    public function test_the_install_scope_answers_with_posts_from_other_sites(): void
    {
        global $wpdb;
        if (! is_multisite() || ! is_main_site() || is_subdomain_install()) {
            $this->markTestSkipped('Needs the main site of a subfolder multisite.');
        }

        $blog = (int) self::factory()->blog->create(['public' => 1]);
        try {
            switch_to_blog($blog);
            $page = wp_insert_post(['post_title' => 'Sentinel '.$this->word, 'post_status' => 'publish', 'post_type' => 'page']);
            restore_current_blog();
            Indexer::one($blog, $page);

            $query = $this->search($this->word, ['scope' => 'install']);
            $this->assertSame([$page], wp_list_pluck($query->posts, 'ID'));
            $this->assertSame($blog, $query->posts[0]->blog_id);
            $this->assertSame($this->site, get_current_blog_id(), 'The query switches back to the site it started on');
            $saved = Integration::$excerpts[$blog.':'.$page];
            $this->assertSame($saved, Integration::excerpt('theme excerpt', $query->posts[0]), "The excerpt is found by the result's own site");

            $items = $this->request(['s' => $this->word, 'scope' => 'install'])->get_data()['items'];
            $this->assertSame([$blog], array_column($items, 'site'), 'The suggestions follow the requested scope');

            $_GET = ['scope' => 'install'];
            $this->assertStringContainsString('name="scope" value="install"', Integration::form(''));
            $this->assertStringContainsString('scope=install', Integration::url());
            $this->assertStringContainsString('scope=install', Integration::url(['paged' => 2]));
        } finally {
            $wpdb->delete(DB::table(), ['site_id' => $blog]);
            wpmu_delete_blog($blog, true);
        }
    }
}

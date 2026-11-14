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
use UAMS_Search\Text;
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

    /** The priority and accepted argument count a callback was registered with, or null when it is not registered. */
    private function registration(string $hook, callable|array $callback): ?array
    {
        global $wp_filter;
        foreach ($wp_filter[$hook]->callbacks ?? [] as $priority => $callbacks) {
            foreach ($callbacks as $registered) {
                if ($registered['function'] === $callback) {
                    return [$priority, $registered['accepted_args']];
                }
            }
        }

        return null;
    }

    public function test_each_hook_keeps_its_priority_and_the_arguments_it_accepts(): void
    {
        $this->assertSame([1, 2], $this->registration('posts_pre_query', [Integration::class, 'posts']));
        $this->assertSame([PHP_INT_MAX, 2], $this->registration('relevanssi_search_ok', [Integration::class, 'allow_other_engine']));
        $this->assertSame([PHP_INT_MAX, 2], $this->registration('asp_query_is_search', [Integration::class, 'allow_other_engine']));
        $this->assertSame([99, 1], $this->registration('template_include', [Integration::class, 'template']));
        $this->assertSame([99, 2], $this->registration('get_the_excerpt', [Integration::class, 'excerpt']));
        $this->assertSame([99, 1], $this->registration('get_search_form', [Integration::class, 'form']));
        $this->assertSame([10, 1], $this->registration('wp_enqueue_scripts', [Integration::class, 'assets']));
        $this->assertSame([10, 1], $this->registration('rest_api_init', [Integration::class, 'rest']));
    }

    public function test_the_shortcode_returns_the_form_instead_of_printing_it(): void
    {
        ob_start();
        $html = do_shortcode('[uams_search_form]');
        $printed = (string) ob_get_clean();

        $this->assertSame('', $printed, 'The form is returned, never echoed');
        $this->assertStringStartsWith('<form role="search"', $html);
        $this->assertStringEndsWith('</form>', $html);
        $this->assertSame(1, substr_count($html, 'class="uams-search-form"'));
    }

    public function test_all_websites_leaves_the_query_with_exactly_the_google_marker(): void
    {
        $query = $this->search($this->word, ['scope' => 'all']);

        $this->assertSame(['results' => [], 'total' => 0, 'pages' => 0, 'fallback' => false, 'suggestion' => null, 'google' => true], $query->get('uams_search_results'));
    }

    public function test_a_query_whose_fields_only_loosely_equal_an_empty_value_is_left_alone(): void
    {
        $this->indexed('Sentinel '.$this->word);

        $this->assertNull(Integration::posts(null, $this->unrun(['s' => $this->word, 'fields' => false])), 'false is not the empty string');
        $this->assertNull(Integration::posts(null, $this->unrun(['s' => $this->word, 'fields' => 0])), '0 is not the empty string');
        $this->assertCount(1, Integration::posts(null, $this->unrun(['s' => $this->word, 'fields' => 'all'])), 'all is answered');
    }

    public function test_the_search_passes_the_page_the_site_the_type_and_the_page_size_to_the_query(): void
    {
        $this->configure(['types' => ['page' => 1.0, 'post' => 1.0], 'fields' => ['page' => [], 'post' => []], 'taxonomies' => ['page' => [], 'post' => []], 'results_per_page' => 10]);
        for ($i = 0; $i < 12; $i++) {
            $this->indexed('Sentinel '.$this->word.' '.$i);
        }

        $first = $this->search($this->word, ['scope' => $this->scope]);
        $this->assertCount(10, $first->posts);
        $this->assertSame(10, $first->get('posts_per_page'));
        $this->assertSame(12, $first->found_posts);
        $this->assertSame(2, $first->max_num_pages);

        $_GET = ['scope' => $this->scope];
        $second = new WP_Query;
        $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = $second;
        $second->query(['s' => $this->word, 'paged' => 2]);
        $this->assertCount(2, $second->posts, 'The second page holds the rest');

        $zero = new WP_Query;
        $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = $zero;
        $zero->query(['s' => $this->word, 'paged' => 0]);
        $this->assertCount(10, $zero->posts, 'A page below one reads as the first');

        $this->assertSame([], $this->search($this->word, ['scope' => $this->scope, 'type' => 'post'])->posts, 'The type narrows the search');
        $this->assertSame(10, $this->search($this->word, ['scope' => $this->scope, 'type' => 'page'])->get('posts_per_page'));
    }

    public function test_a_failed_search_logs_the_reason_after_the_notice(): void
    {
        $this->indexed('Sentinel '.$this->word);
        $log = tempnam(sys_get_temp_dir(), 'uamslog');
        $previous = ini_set('error_log', $log);
        $this->rename_index_table();
        try {
            $this->search($this->word);
            $this->request(['s' => $this->word]);
        } finally {
            $this->restore_index_table();
            ini_set('error_log', (string) $previous);
        }

        $lines = (string) file_get_contents($log);
        unlink($log);
        $this->assertMatchesRegularExpression('/UAMS Search: search failed; using WordPress\. \S/', $lines, 'The throwable message follows the notice');
        $this->assertMatchesRegularExpression('/UAMS Search: REST \S/', $lines, 'The REST message follows its notice');
    }

    public function test_the_templates_are_the_files_in_the_plugin_folder(): void
    {
        $this->indexed('Sentinel '.$this->word);
        $this->search($this->word);

        $default = Integration::template('theme.php');
        $this->assertFileExists($default);
        $this->assertSame('search.php', basename($default));
        $this->assertSame(dirname(__DIR__, 2).'/templates', realpath(dirname($default)));

        add_filter('template', static fn (): string => 'genesis');
        $genesis = Integration::template('theme.php');
        remove_all_filters('template');
        $this->assertFileExists($genesis);
        $this->assertSame('search-genesis.php', basename($genesis));
        $this->assertSame(dirname(__DIR__, 2).'/templates', realpath(dirname($genesis)));
    }

    public function test_the_excerpt_is_read_by_the_posts_own_site_and_id(): void
    {
        $id = $this->indexed('Sentinel '.$this->word);
        $this->search($this->word);
        Integration::$excerpts = [$this->site.':'.$id => 'saved here', '999:'.$id => 'saved elsewhere'];
        $post = get_post($id);

        $this->assertSame('saved here', Integration::excerpt('theme', $post), 'A post with no blog_id is read on the current site');
        $post->blog_id = 999;
        $this->assertSame('saved elsewhere', Integration::excerpt('theme', $post), 'A post with a blog_id is read on that site');
        $post->blog_id = 998;
        $this->assertSame('theme', Integration::excerpt('theme', $post));
    }

    public function test_the_form_markup_is_exact_and_escapes_the_search_text(): void
    {
        $_GET = ['scope' => $this->scope];
        $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query(['s' => 'a "b" & c']);
        $normalize = static fn (string $html): string => (string) preg_replace('/uams-search-\d+/', 'ID', $html);
        $open = '<form role="search" method="get" action="'.esc_url(home_url('/')).'" class="uams-search-form"><label for="ID">Search This Website</label><div class="uams-search-input"><input type="search" name="s" id="ID" value="a &quot;b&quot; &amp; c" maxlength="200" autocomplete="off" data-uams-live="true"><button type="submit">Search</button></div><input type="hidden" name="scope" value="'.$this->scope.'">';

        $this->assertSame($open.'</form>', $normalize(Integration::form('')));

        wp_set_current_user(1);
        $_GET = ['scope' => $this->scope, 'engine' => 'uams', 'type' => 'page'];
        $this->assertSame($open.'<input type="hidden" name="engine" value="uams"><input type="hidden" name="type" value="page"></form>', $normalize(Integration::form('')));
    }

    public function test_the_page_heading_and_form_print_the_search_text_once_escaped(): void
    {
        $query = $this->search('a & b', ['scope' => $this->scope]);
        $html = $this->render($query);

        $this->assertStringContainsString('<h1>Search Results for: a &amp; b</h1>', $html);
        $this->assertStringContainsString('value="a &amp; b"', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringContainsString('<form role="search"', $html, 'The form is printed under the heading');
        $this->assertSame(1, substr_count($html, '<form role="search"'));
    }

    public function test_a_miss_is_recorded_as_typed_and_a_first_page_counts(): void
    {
        global $wpdb;
        $miss = 'uamsfixture'.strtolower(wp_generate_password(6, false, false)).' & <b>more</b>';
        $query = $this->search($miss, ['scope' => $this->scope]);
        $query->set('paged', 1);
        $this->render($query);

        $stored = $wpdb->get_col($wpdb->prepare('SELECT search_text FROM `'.DB::table('misses').'` WHERE site_id=%d AND search_hash=%s', $this->site, hash('sha256', mb_strtolower(Text::plain($miss)))));
        $this->assertSame([Text::plain($miss)], $stored, 'The text is recorded as typed, not escaped twice');
    }

    public function test_the_results_page_prints_no_form_of_its_own_when_the_plugin_is_off(): void
    {
        $query = $this->search($this->word, ['scope' => $this->scope]);
        $this->configure(['enabled' => false]);
        $html = $this->render($query);

        $this->assertStringContainsString('</h1><nav aria-label="Search scope">', $html, 'The form call returns what it was given, which is nothing');
    }

    public function test_the_suggestion_link_keeps_the_scope_and_names_the_correction(): void
    {
        $this->indexed('Cardiovascularology '.$this->word);
        $html = $this->render($this->search('cardiovascularolgy', ['scope' => $this->scope]));

        $expected = esc_url(add_query_arg(['s' => 'cardiovascularology', 'scope' => $this->scope], home_url('/')));
        $this->assertStringContainsString('<p>Did you mean <a href="'.$expected.'">cardiovascularology</a>?</p>', $html);
    }

    public function test_the_page_address_encodes_the_search_text_once(): void
    {
        $_GET = ['scope' => $this->scope];
        $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query(['s' => 'a&b']);

        $this->assertSame(add_query_arg(['s' => 'a&b', 'scope' => $this->scope], home_url('/')), Integration::url());
        $this->assertStringNotContainsString('amp;', Integration::url(), 'The text is not escaped before it is added');
    }

    public function test_the_filters_print_exact_links_for_each_scope_and_type(): void
    {
        $this->configure(['types' => ['page' => 1.0], 'pdf' => false]);
        $_GET = ['scope' => $this->scope, 'type' => 'page'];
        $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query(['s' => 'findme']);
        $query = $this->search('findme', ['scope' => $this->scope, 'type' => 'page']);
        $html = $this->render($query);

        $scope = $this->scope;
        $link = static fn (array $args): string => esc_url(add_query_arg(array_merge(['s' => 'findme', 'scope' => $scope, 'type' => 'page'], $args), home_url('/')));
        $this->assertStringContainsString('<a href="'.$link(['scope' => 'all']).'">All UAMS websites <span class="uams-search-note">UAMS.edu and UAMSHealth.com</span></a>', $html);
        $this->assertStringContainsString('<li><a href="'.esc_url(add_query_arg(['s' => 'findme', 'scope' => $this->scope], home_url('/'))).'">All results</a></li>', $html, 'All results drops the type');
        $this->assertStringContainsString('<a href="'.$link([]).'" aria-current="true">', $html);
    }

    public function test_the_google_block_is_exact_and_puts_the_engine_id_first(): void
    {
        $html = $this->render($this->search($this->word, ['scope' => 'all']));

        $this->assertStringContainsString('<section class="uams-search-google" aria-label="All UAMS websites"><h2>All UAMS Websites</h2><script async src="https://cse.google.com/cse.js?cx=014806496997774146681%3Apyl1jhwi-bo"></script><div class="gcse-searchresults-only" data-queryParameterName="s"></div></section></section>', $html);
    }

    public function test_the_result_pages_link_to_each_page_and_mark_the_current_one(): void
    {
        $query = $this->search($this->word, ['scope' => $this->scope]);
        $query->set('paged', 2);
        $query->query_vars['paged'] = 2;
        $query->set('uams_search_results', ['results' => [], 'total' => 20, 'pages' => 2, 'fallback' => false, 'suggestion' => null]);
        $html = $this->render($query);

        $this->assertStringContainsString('<nav aria-label="Search result pages"><ul class="page-numbers">', $html, 'Two pages are enough for page links');
        $this->assertStringContainsString('<span aria-current="page" class="page-numbers current">2</span>', $html);
        $this->assertMatchesRegularExpression('#<a class="page-numbers" href="[^"]*paged=1[^"]*">1</a>#', $html);
        $this->assertStringContainsString('scope='.$this->scope, (string) preg_replace('/.*<nav aria-label="Search result pages">/s', '', $html));
        $this->assertStringEndsWith("</ul>\n</nav></section>", $html);
    }

    public function test_the_suggestions_endpoint_checks_its_arguments(): void
    {
        $this->indexed('Sentinel '.$this->word);

        $this->assertSame(400, $this->request([])->get_status(), 'The search text is required');
        $this->assertSame(200, $this->request(['s' => 'a'])->get_status(), 'The sanitizer is set, so the declared length bounds are not enforced');
        $this->assertSame(200, $this->request(['s' => str_repeat('x', 201)])->get_status(), 'and the search itself caps the text');
        $this->assertSame(400, $this->request(['s' => $this->word, 'engine' => 'wordpress'])->get_status(), 'Only uams is a known engine');
        $this->assertSame(1, $this->request(['s' => $this->word, 'type' => 'PAGE'])->get_data()['total'], 'The type is cleaned to a key');
        $this->assertSame(1, $this->request(['s' => '  '.$this->word.'  '])->get_data()['total'], 'The text is trimmed');
        foreach (['site', 'install', 'all'] as $scope) {
            if ($scope === 'install' && ! is_multisite()) {
                continue;
            }

            $this->assertSame(200, $this->request(['s' => $this->word, 'scope' => $scope])->get_status(), "$scope is a known scope");
        }
    }

    public function test_the_suggestions_name_each_result_exactly(): void
    {
        $id = $this->indexed('Sentinel '.$this->word);
        $items = $this->request(['s' => $this->word])->get_data()['items'];

        $this->assertSame([['id' => $id, 'site' => $this->site, 'title' => 'Sentinel '.$this->word, 'url' => esc_url_raw((string) get_permalink($id))]], $items);
    }

    public function test_cards_and_results_are_grouped_and_every_group_is_closed(): void
    {
        foreach (['provider', 'location'] as $type) {
            if (! post_type_exists($type)) {
                register_post_type($type, ['public' => true]);
            }
        }

        $provider = $this->make_page(['post_type' => 'provider', 'post_title' => 'Doctor '.$this->word]);
        $second = $this->make_page(['post_type' => 'provider', 'post_title' => 'Second '.$this->word]);
        $location = $this->make_page(['post_type' => 'location', 'post_title' => 'Clinic '.$this->word]);
        $card = fn (int $id, string $type): Result => new Result($id, $this->site, 1.0, 'x', 'Card', 'http://example.org/?p='.$id, $type, 0);
        $plain = new Result(1, $this->site, 1.0, 'plain', 'Plain', 'http://example.org/?p=1', 'page', 0);
        $gone = new Result(0, $this->site, 1.0, 'x', 'Gone', 'http://example.org/?p=0', 'provider', 0);
        $run = function (array $results): string {
            $query = $this->search($this->word);
            $query->set('uams_search_results', ['results' => $results, 'total' => count($results), 'pages' => 1, 'fallback' => false, 'suggestion' => null]);

            return $this->render($query);
        };

        $html = $run([$card($provider, 'provider'), $card($second, 'provider'), $plain]);
        $this->assertSame(1, substr_count($html, '<div class="uams-search-provider-cards card-list card-list-doctors">'), 'Neighbors share one group');
        $this->assertMatchesRegularExpression('#</div><article class="uams-search-result"><p class="uams-search-label">Page</p><h3><a href="http://example.org/\?p=1">Plain</a></h3>#', $html, 'The group closes before an ordinary result');

        $html = $run([$card($provider, 'provider'), $card($location, 'location'), $plain]);
        $this->assertSame(1, substr_count($html, '<div class="uams-search-location-cards card-list">'));
        $this->assertStringContainsString('</div><div class="uams-search-location-cards card-list">', $html, 'The provider group closes before the location group opens');
        $this->assertMatchesRegularExpression('#</div><article class="uams-search-result">#', $html);

        $html = $run([$plain, $card($provider, 'provider')]);
        $this->assertStringEndsWith('</article></div></section>', $html, 'A group still open at the end is closed once');

        $html = $run([$gone, $plain]);
        $this->assertStringNotContainsString('uams-search-provider-cards', $html, 'A card with no data opens no group');
        $this->assertStringNotContainsString('</div><article', $html);
    }

    public function test_each_type_link_names_its_type_and_carries_the_search_and_scope(): void
    {
        $this->configure(['types' => ['page' => 1.0, 'post' => 1.0], 'pdf' => false]);
        $html = $this->render($this->search('findme', ['scope' => $this->scope]));

        foreach (['page' => 'Pages', 'post' => 'News'] as $type => $label) {
            $href = esc_url(add_query_arg(['s' => 'findme', 'scope' => $this->scope, 'type' => $type], home_url('/')));
            $this->assertStringContainsString('<li><a href="'.$href.'">'.$label.'</a></li>', $html, "$type is linked without a current marker");
        }

        $html = $this->render($this->search('findme', ['scope' => $this->scope, 'type' => 'post']));
        $href = esc_url(add_query_arg(['s' => 'findme', 'scope' => $this->scope, 'type' => 'post'], home_url('/')));
        $this->assertStringContainsString('<li><a href="'.$href.'" aria-current="true">News</a></li>', $html);
        $this->assertStringNotContainsString('paged=', $html, 'No filter link carries a page');
    }

    public function test_the_suggestions_follow_the_type_that_was_asked_for(): void
    {
        $this->configure(['types' => ['page' => 1.0, 'post' => 1.0], 'fields' => ['page' => [], 'post' => []], 'taxonomies' => ['page' => [], 'post' => []]]);
        $this->indexed('Sentinel '.$this->word);

        $this->assertSame(1, $this->request(['s' => $this->word, 'type' => 'page'])->get_data()['total']);
        $this->assertSame(0, $this->request(['s' => $this->word, 'type' => 'post'])->get_data()['total'], 'The type narrows the suggestions');
        $this->assertSame(0, $this->request(['s' => $this->word, 'type' => 'POST'])->get_data()['total'], 'The type is cleaned to a key first');
        $this->assertSame(1, $this->request(['s' => $this->word])->get_data()['total'], 'No type searches every type');
    }

    public function test_a_search_with_no_page_number_at_all_counts_as_the_first_page(): void
    {
        global $wpdb;
        $miss = 'uamsfixture'.strtolower(wp_generate_password(6, false, false));
        $query = $this->search($miss, ['scope' => $this->scope]);
        unset($query->query_vars['paged']);
        $this->render($query);

        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.DB::table('misses').'` WHERE site_id=%d AND search_text=%s', $this->site, $miss)));
    }

    public function test_the_page_links_run_from_the_first_page_to_the_last(): void
    {
        $query = $this->search($this->word, ['scope' => $this->scope]);
        $query->set('uams_search_results', ['results' => [], 'total' => 45, 'pages' => 3, 'fallback' => false, 'suggestion' => null]);
        unset($query->query_vars['paged']);

        $html = $this->render($query);
        $this->assertStringContainsString('<span aria-current="page" class="page-numbers current">1</span>', $html, 'No page number means page one');
        $this->assertStringContainsString('href="'.str_replace('&#038;', '&amp;', esc_url(add_query_arg(['s' => $this->word, 'scope' => $this->scope, 'paged' => 2], home_url('/')))).'">2</a>', $html);
        $this->assertStringContainsString('paged=3', $html);
        $this->assertStringNotContainsString('999999999', $html);
        $this->assertStringNotContainsString('paged=4', $html);
        $this->assertStringContainsString('class="next page-numbers"', $html);
        $this->assertStringNotContainsString('class="prev page-numbers"', $html, 'The first page has nothing before it');

        $query->set('paged', 3);
        $query->query_vars['paged'] = 3;
        $html = $this->render($query);
        $this->assertStringContainsString('<span aria-current="page" class="page-numbers current">3</span>', $html);
        $this->assertStringContainsString('class="prev page-numbers"', $html);
        $this->assertStringNotContainsString('class="next page-numbers"', $html, 'The last page has nothing after it');
    }

    public function test_a_second_site_searches_itself_unless_it_asks_for_the_whole_install(): void
    {
        global $wpdb;
        if (! is_multisite() || ! is_main_site() || is_subdomain_install()) {
            $this->markTestSkipped('Needs the main site of a subfolder multisite.');
        }

        $this->indexed('Sentinel '.$this->word);
        $blog = (int) self::factory()->blog->create(['public' => 1]);
        try {
            switch_to_blog($blog);
            $page = wp_insert_post(['post_title' => 'Sentinel '.$this->word, 'post_status' => 'publish', 'post_type' => 'page']);
            restore_current_blog();
            Indexer::one($blog, $page);

            switch_to_blog($blog);
            try {
                $own = $this->search($this->word);
                $this->assertSame([$blog], wp_list_pluck($own->posts, 'blog_id'), 'A second site searches itself by default');
                $whole = $this->search($this->word, ['scope' => 'install']);
                $sites = wp_list_pluck($whole->posts, 'blog_id');
                sort($sites);
                $this->assertSame([$this->site, $blog], $sites, 'It can ask for the whole install');
                $this->assertSame($blog, get_current_blog_id(), 'and stays on its own site');

                $this->assertSame([$blog], array_column($this->request(['s' => $this->word])->get_data()['items'], 'site'));
                $items = array_column($this->request(['s' => $this->word, 'scope' => 'install'])->get_data()['items'], 'site');
                sort($items);
                $this->assertSame([$this->site, $blog], $items);
            } finally {
                restore_current_blog();
            }
        } finally {
            $wpdb->delete(DB::table(), ['site_id' => $blog]);
            wpmu_delete_blog($blog, true);
        }
    }
}

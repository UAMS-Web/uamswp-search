<?php

namespace {

    defined('UAMS_SEARCH_FILE') || define('UAMS_SEARCH_FILE', __DIR__.'/../uams-search.php');
    defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
    defined('OBJECT') || define('OBJECT', 'OBJECT');

    // Stand-ins for the WordPress functions the screens call.
    if (! function_exists('esc_attr')) {
        function esc_attr(mixed $s): string
        {
            return htmlspecialchars((string) $s);
        }
    }

    if (! function_exists('esc_textarea')) {
        function esc_textarea(mixed $s): mixed
        {
            return htmlspecialchars((string) $s);
        }
    }

    if (! function_exists('esc_html')) {
        function esc_html(mixed $s): string
        {
            return htmlspecialchars((string) $s);
        }
    }

    if (! function_exists('esc_url')) {
        function esc_url(mixed $url): string
        {
            return $url;
        }
    }

    if (! function_exists('admin_url')) {
        function admin_url(mixed $path = ''): string
        {
            return 'https://example.test/wp-admin/'.$path;
        }
    }

    if (! function_exists('network_admin_url')) {
        function network_admin_url(mixed $path = ''): string
        {
            return 'https://example.test/wp-admin/network/'.$path;
        }
    }

    if (! function_exists('current_user_can')) {
        function current_user_can(mixed $capability, mixed ...$_args): bool
        {
            return in_array($capability, $GLOBALS['uams_test_caps'] ?? [], true);
        }
    }

    if (! function_exists('wp_nonce_field')) {
        function wp_nonce_field(mixed $action = -1, mixed $name = '_wpnonce', mixed $_referer = true, mixed $display = true): string
        {
            $field = '<input type="hidden" name="'.$name.'" value="nonce-for-'.$action.'">';
            if ($display) {
                echo $field;
            }

            return $field;
        }
    }

    if (! function_exists('submit_button')) {
        function submit_button(mixed $text = null, mixed $_type = 'primary', mixed $_name = 'submit', mixed $_wrap = true, mixed $_other_attributes = null): void
        {
            echo '<input type="submit" value="'.htmlspecialchars((string) $text).'">';
        }
    }

    if (! function_exists('checked')) {
        function checked(mixed $checked, mixed $current = true, mixed $display = true): string
        {
            // The same comparison, output and return as WordPress's helper
            $result = (string) $checked === (string) $current ? " checked='checked'" : '';
            if ($display) {
                echo $result;
            }

            return $result;
        }
    }

    if (! function_exists('get_post_types')) {
        function get_post_types(mixed $_args = []): array
        {
            return ['post' => 'post'];
        }
    }

    if (! function_exists('get_post_type_object')) {
        function get_post_type_object(mixed $type): mixed
        {
            return $GLOBALS['uams_test_types'][$type] ?? null;
        }
    }

    if (! function_exists('get_bloginfo')) {
        function get_bloginfo(mixed $_show = ''): mixed
        {
            return 'Main Site';
        }
    }

    if (! function_exists('wp_enqueue_script')) {
        function wp_enqueue_script(mixed $handle, mixed $src = '', mixed $deps = [], mixed $ver = false, mixed $args = []): void
        {
            $GLOBALS['uams_test_enqueued'][] = $handle;
            $GLOBALS['uams_test_enqueue_args'][] = ['script', $handle, $src, $deps, $ver, $args];
        }
    }

    if (! function_exists('wp_enqueue_style')) {
        function wp_enqueue_style(mixed $handle, mixed $src = '', mixed $deps = [], mixed $ver = false, mixed $media = 'all'): void
        {
            $GLOBALS['uams_test_enqueued'][] = $handle;
            $GLOBALS['uams_test_enqueue_args'][] = ['style', $handle, $src, $deps, $ver, $media];
        }
    }

    if (! function_exists('wp_localize_script')) {
        function wp_localize_script(mixed $handle, mixed $object_name, mixed $l10n): bool
        {
            $GLOBALS['uams_test_localized'][] = [$handle, $object_name, $l10n];

            return true;
        }
    }

    if (! function_exists('plugins_url')) {
        function plugins_url(mixed $path = '', mixed $_plugin = ''): string
        {
            return 'https://example.test/'.$path;
        }
    }

    if (! function_exists('maybe_unserialize')) {
        function maybe_unserialize(mixed $value): mixed
        {
            return $value;
        }
    }

    if (! function_exists('wp_nonce_url')) {
        function wp_nonce_url(mixed $url, mixed $action = -1): string
        {
            return $url.'&_wpnonce=nonce-for-'.$action;
        }
    }

    if (! function_exists('wp_create_nonce')) {
        function wp_create_nonce(mixed $_action = ''): string
        {
            return 'nonce';
        }
    }
}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;
    use UAMS_Search\Admin;
    use UAMS_Search\DB;
    use UAMS_Search\Rebuild;
    use UAMS_Search\SiteNames;
    use UAMS_Search\Time;

    /** Answers the table probe and returns no rows, so the screens render their empty states. */
    class ScreensFixtureDB
    {
        public string $base_prefix = 'wp_';

        public string $options = 'wp_options';

        public string $last_error = '';

        /** @var list<string> Every query the screen ran. */
        public array $queries = [];

        public function prepare(mixed $sql): mixed
        {
            return $sql;
        }

        public function esc_like(mixed $s): mixed
        {
            return $s;
        }

        public function get_var(mixed $sql): mixed
        {
            $this->queries[] = (string) $sql;

            return str_starts_with($sql, 'SHOW TABLES') ? 'wp_uams_search_index' : 0;
        }

        public function get_results(mixed $sql = ''): mixed
        {
            $this->queries[] = (string) $sql;

            return [];
        }
    }

    final class ScreensTest extends TestCase
    {
        protected function setUp(): void
        {
            // The screens render the same on a single-site install, where the capability is `manage_options`.
            $GLOBALS['uams_test_multisite'] = false;
            $GLOBALS['uams_test_caps'] = ['manage_options'];
            $GLOBALS['uams_test_options'] = ['uams_search_version' => UAMS_SEARCH_SCHEMA_VERSION];
            $GLOBALS['uams_test_types'] = ['post' => (object) ['labels' => (object) ['name' => 'Posts']]];
            $GLOBALS['uams_test_enqueued'] = [];
            $GLOBALS['uams_test_enqueue_args'] = [];
            $GLOBALS['uams_test_localized'] = [];
            $GLOBALS['wpdb'] = new ScreensFixtureDB;
            $this->tablesReady(true);
        }

        protected function tearDown(): void
        {
            $GLOBALS['uams_test_multisite'] = false;
            $GLOBALS['uams_test_options'] = [];
            $_GET = [];
            $this->tablesReady(null);
        }

        private function tablesReady(?bool $ready): void
        {
            (new ReflectionProperty(DB::class, 'table_ready'))->setValue(null, $ready);
        }

        private function render(string $method): string
        {
            ob_start();
            try {
                Admin::$method();
            } finally {
                $html = (string) ob_get_clean();
            }

            return $html;
        }

        public function test_each_screen_has_its_own_heading(): void
        {
            self::assertStringStartsWith('<div class="wrap"><h1>UAMSWP Search Index</h1><p>Build and compare the index before enabling it.', $this->render('page'));
            self::assertStringContainsString('<h1>UAMSWP Search Reports</h1>', $this->render('reports_page'));
            self::assertStringContainsString('<h1>UAMSWP Search Settings</h1>', $this->render('settings_page'));
        }

        public function test_every_section_appears_on_exactly_one_screen(): void
        {
            $screens = ['page' => $this->render('page'), 'reports_page' => $this->render('reports_page'), 'settings_page' => $this->render('settings_page')];
            $sections = [
                'id="uams-search-rebuild"' => 'page',
                'id="status"' => 'page',
                'id="failed-items"' => 'reports_page',
                'id="no-results"' => 'reports_page',
                'name="action" value="uams_search_save"' => 'settings_page',
            ];
            foreach ($sections as $needle => $owner) {
                foreach ($screens as $screen => $html) {
                    self::assertSame($screen === $owner, str_contains($html, $needle), $needle.' on '.$screen);
                }
            }
        }

        public function test_the_settings_screen_holds_the_whole_settings_form(): void
        {
            $html = $this->render('settings_page');
            preg_match_all('/<form [^>]*>.*?<\/form>/s', $html, $forms);
            [$settings, $refresh] = $forms[0] + [null, null];

            foreach (['settings[enabled]', 'settings[sites][]', 'settings[weights]', 'settings[types][post][include]', 'settings[stopwords]', 'settings[synonyms]', 'settings[pdf_max_mb]', 'settings[pdf_timeout]'] as $field) {
                self::assertStringContainsString('name="'.$field, (string) $settings, $field);
            }

            self::assertCount(2, $forms[0], 'The settings form, and the Refresh Choices form its button submits.');
            self::assertStringContainsString('value="uams_search_save"', (string) $settings, 'The settings are one form.');
            self::assertStringContainsString('<form id="uams-search-refresh-choices"', (string) $refresh);
            self::assertStringContainsString('value="uams_search_refresh_choices"', (string) $refresh);
            self::assertStringNotContainsString('name="settings[', (string) $refresh, 'Refreshing the choices saves no settings.');
        }

        public function test_a_screen_without_index_tables_offers_setup_instead(): void
        {
            $GLOBALS['uams_test_options'] = [];

            foreach (['page', 'reports_page', 'settings_page'] as $method) {
                $html = $this->render($method);
                self::assertStringContainsString('value="uams_search_setup"', $html, $method);
                self::assertStringNotContainsString('uams_search_save', $html, $method);
            }
        }

        public function test_only_the_index_screen_loads_the_rebuild_assets(): void
        {
            foreach (['uams-search-reports', 'uams-search-settings'] as $page) {
                $_GET = ['page' => $page];
                Admin::rebuild_assets();
            }

            self::assertSame([], $GLOBALS['uams_test_enqueued']);

            $_GET = ['page' => 'uams-search'];
            Admin::rebuild_assets();
            self::assertContains('uams-search-rebuild', $GLOBALS['uams_test_enqueued']);
        }

        public function test_the_rebuild_assets_are_loaded_with_exact_arguments(): void
        {
            $_GET = ['page' => 'uams-search'];
            Admin::rebuild_assets();
            $version = UAMS_SEARCH_VERSION;

            self::assertSame([
                ['script', 'uams-search-rebuild', 'https://example.test/assets/admin-rebuild.js', [], $version, true],
                ['style', 'uams-search-rebuild', 'https://example.test/assets/admin-rebuild.css', [], $version, 'all'],
            ], $GLOBALS['uams_test_enqueue_args']);
            self::assertSame([['uams-search-rebuild', 'UAMSRebuild', [
                'url' => 'https://example.test/wp-admin/admin-ajax.php',
                'nonce' => 'nonce',
                'job' => Rebuild::state(),
            ]]], $GLOBALS['uams_test_localized']);
        }

        public function test_the_rebuild_assets_need_the_capability_even_on_the_index_screen(): void
        {
            $GLOBALS['uams_test_caps'] = [];
            $_GET = ['page' => 'uams-search'];
            Admin::rebuild_assets();
            Admin::settings_assets();

            self::assertSame([], $GLOBALS['uams_test_enqueued']);
        }

        public function test_the_settings_script_is_loaded_with_exact_arguments(): void
        {
            $_GET = ['page' => 'uams-search-settings'];
            Admin::settings_assets();

            self::assertSame([['script', 'uams-search-settings', 'https://example.test/assets/admin-settings.js', [], UAMS_SEARCH_VERSION, true]], $GLOBALS['uams_test_enqueue_args']);
            self::assertSame([], $GLOBALS['uams_test_localized']);
        }

        public function test_the_site_page_marks_the_saved_scope_and_the_google_switch(): void
        {
            $html = $this->render('site_page');
            self::assertStringContainsString('<h1>Search Settings</h1>', $html);
            self::assertStringContainsString('<input type="hidden" name="action" value="uams_search_site">', $html);
            self::assertStringContainsString('<p><label><input type="radio" name="scope" value="site"  checked=\'checked\'> This site</label></p>', $html);
            self::assertStringContainsString('<p><label><input type="radio" name="scope" value="all" > All UAMS websites</label></p>', $html);
            self::assertStringContainsString('<input type="checkbox" name="google" value="1"  checked=\'checked\'> Show the Existing All of UAMS Google Search', $html, 'The Google results are on until the site turns them off.');
            self::assertStringContainsString('action="https://example.test/wp-admin/admin-post.php"', $html);
            self::assertStringContainsString('<input type="hidden" name="_wpnonce" value="nonce-for-uams_search_site">', $html);
            self::assertSame(2, substr_count($html, "checked='checked'"), 'One scope and the Google switch.');
            self::assertStringContainsString('<input type="submit" value="Save search settings"></form></div>', $html);

            $GLOBALS['uams_test_options']['uams_search_scope'] = 'all';
            $GLOBALS['uams_test_options']['uams_search_google'] = false;
            $html = $this->render('site_page');
            self::assertStringContainsString('value="site" > This site', $html);
            self::assertStringContainsString('value="all"  checked=\'checked\'> All UAMS websites', $html);
            self::assertStringContainsString('name="google" value="1" > Show', $html);

            foreach (['bogus', 5, ['all']] as $saved) {
                $GLOBALS['uams_test_options']['uams_search_scope'] = $saved;
                self::assertStringContainsString('value="site"  checked=\'checked\'> This site', $this->render('site_page'), var_export($saved, true));
            }
        }

        public function test_the_status_and_reports_tables_print_each_row_with_its_site_name(): void
        {
            $GLOBALS['uams_test_options']['blogname'] = 'Main Site';
            SiteNames::reset();
            $GLOBALS['wpdb'] = new class extends ScreensFixtureDB
            {
                public ?int $count = 7;

                public function get_var(mixed $sql): mixed
                {
                    $this->queries[] = (string) $sql;

                    return str_starts_with($sql, 'SHOW TABLES') ? 'wp_uams_search_index' : $this->count;
                }

                public function get_results(mixed $sql = ''): mixed
                {
                    $this->queries[] = (string) $sql;
                    if (str_contains($sql, 'GROUP BY site_id,post_type,status')) {
                        return [['site_id' => '1', 'post_type' => 'post', 'status' => 'ready', 'total' => '12']];
                    }

                    if (str_contains($sql, "status IN ('error','scanned')")) {
                        return [['site_id' => '1', 'object_id' => '42', 'status' => 'error', 'error' => 'Timed out']];
                    }

                    return [['site_id' => '1', 'search_text' => 'cardio', 'total' => '3']];
                }
            };
            $status = $this->render('page');
            self::assertStringContainsString('<p>Queue length: 7. Last queue run: Never.</p>', $status);
            self::assertStringContainsString('<th scope="col">Site Name</th><th scope="col">Site ID</th><th scope="col">Post type</th><th scope="col">Status</th><th scope="col">Count</th>', $status);
            self::assertStringContainsString('<tr><td>Main Site</td><td>1</td><td>post</td><td>ready</td><td>12</td></tr>', $status);

            self::assertContains('SELECT COUNT(*) FROM `wp_uams_search_queue`', $GLOBALS['wpdb']->queries);
            self::assertContains('SELECT site_id,post_type,status,COUNT(*) AS total FROM `wp_uams_search_index` GROUP BY site_id,post_type,status ORDER BY site_id,post_type', $GLOBALS['wpdb']->queries);
            $GLOBALS['wpdb']->count = null;
            self::assertStringContainsString('<p>Queue length: 0. Last queue run: Never.</p>', $this->render('page'), 'A count the database did not give reads as zero.');
            $GLOBALS['wpdb']->count = 7;

            $reports = $this->render('reports_page');
            self::assertStringContainsString('<h1>UAMSWP Search Reports</h1><section id="failed-items">', $reports);
            self::assertContains("SELECT site_id,object_id,status,error FROM `wp_uams_search_index` WHERE status IN ('error','scanned') ORDER BY indexed_at DESC LIMIT 100", $GLOBALS['wpdb']->queries);
            self::assertContains('SELECT site_id,search_text,SUM(count) AS total FROM `wp_uams_search_misses` WHERE day>=UTC_DATE()-INTERVAL 30 DAY GROUP BY site_id,search_hash,search_text ORDER BY total DESC LIMIT 100', $GLOBALS['wpdb']->queries);
            self::assertStringContainsString('<tr><td>Main Site</td><td>1</td><td>42</td><td>error</td><td>Timed out</td><td><a href="', $reports);
            self::assertStringContainsString('admin-post.php?action=uams_search_retry&site=1&object=42&_wpnonce=nonce-for-uams_search_retry', $reports);
            self::assertStringContainsString('>Retry item 42 on site 1</a></td></tr>', $reports);
            self::assertStringContainsString('<th scope="col">Site Name</th><th scope="col">Site ID</th><th scope="col">Search</th><th scope="col">Count</th>', $reports);
            self::assertStringContainsString('<tr><td>Main Site</td><td>1</td><td>cardio</td><td>3</td></tr>', $reports);
        }

        public function test_the_settings_screen_prints_the_saved_values_and_their_limits(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = [
                'enabled' => true, 'live' => false, 'logging' => true, 'pdf' => false, 'pdf_parent' => true,
                'results_per_page' => 33, 'pdf_max_mb' => 12, 'pdf_timeout' => 45,
                'sites' => [1],
                'types' => ['post' => 2.5, 'page' => 1.0],
                'fields' => ['post' => ['a_name_of_twenty_six_ch' => 2.0, 'physical_name_thirty_chars_xx' => 1.5]],
                'taxonomies' => ['post' => ['category' => 3.0]],
                'stopwords' => ['of', 'the'],
                'synonyms' => ['heart' => ['cardiac', 'cardiology']],
            ];
            $GLOBALS['uams_test_types'] = ['post' => (object) ['labels' => (object) ['name' => 'Posts']], 'page' => (object) ['labels' => (object) ['name' => 'Pages']]];
            $html = $this->render('settings_page');

            $labels = ['enabled' => 'Use UAMSWP Search for Visitors', 'live' => 'Show Live Search Suggestions', 'logging' => 'Keep Search Text With No Results for up to 90 Days', 'pdf' => 'Index PDFs', 'pdf_parent' => 'Link PDFs to Their Parent Page When Available'];
            foreach (['enabled' => true, 'live' => false, 'logging' => true, 'pdf' => false, 'pdf_parent' => true] as $key => $on) {
                self::assertStringContainsString('<p><label><input type="checkbox" name="settings['.$key.']" value="1" '.($on ? " checked='checked'" : '').'> '.$labels[$key].'</label></p>', $html, $key);
            }

            self::assertSame(6, substr_count($html, "checked='checked'"), 'Three switches, one site and two types.');
            self::assertStringContainsString('<form method="post" action="https://example.test/wp-admin/admin-post.php"><input type="hidden" name="action" value="uams_search_save">', $html);
            self::assertStringContainsString('<form id="uams-search-refresh-choices" method="post" action="https://example.test/wp-admin/admin-post.php"><input type="hidden" name="action" value="uams_search_refresh_choices">', $html);
            self::assertStringContainsString('<p><label><input type="checkbox" name="settings[sites][]" value="1"  checked=\'checked\'> Main Site</label></p>', $html);
            self::assertStringContainsString('<h3>ACF Fields</h3><table class="uams-search-rows" data-prefix="settings[types][post][fields]" data-label="Posts ACF Fields" data-size="31"><thead><tr><th scope="col">Name</th><th scope="col">Weight</th><th scope="col"><span class="screen-reader-text">Remove</span></th></tr></thead><tbody>', $html);
            self::assertStringContainsString('<tr><td><input size="31" aria-label="Posts ACF Fields name 1" name="settings[types][post][fields][0][name]" value="a_name_of_twenty_six_ch"></td><td><input type="number" min="0" max="100" step="0.1" aria-label="Posts ACF Fields weight 1" name="settings[types][post][fields][0][weight]" value="2"></td><td><button type="button" class="button uams-remove-row" aria-label="Remove Posts ACF Fields row 1" hidden>Remove</button></td></tr>', $html);
            self::assertStringContainsString('<tr><td><input size="31" aria-label="Posts ACF Fields name 3" name="settings[types][post][fields][2][name]" value=""></td><td><input type="number" min="0" max="100" step="0.1" aria-label="Posts ACF Fields weight 3" name="settings[types][post][fields][2][weight]" value="1"></td><td><button type="button" class="button uams-remove-row" aria-label="Remove Posts ACF Fields row 3" hidden>Remove</button></td></tr>', $html);
            self::assertSame(3, substr_count($html, 'aria-label="Posts ACF Fields name '), 'Rows are numbered from 1.');
            self::assertSame(3, substr_count($html, 'aria-label="Posts ACF Fields weight '));
            self::assertSame(3, substr_count($html, 'aria-label="Remove Posts ACF Fields row '));
            self::assertStringNotContainsString('name 0"', $html);
            self::assertStringNotContainsString('name -1"', $html);

            self::assertStringContainsString('name="settings[results_per_page]" min="1" max="100" step="1" value="33">', $html);
            self::assertStringContainsString('name="settings[sites][]" value="1"  checked=\'checked\'> Main Site', $html);
            self::assertStringContainsString('id="uams-settings-pdf-max-mb" type="number" min="0" max="100" step="0.1" style="width:6em" name="settings[pdf_max_mb]" value="12"', $html);
            self::assertStringContainsString('id="uams-settings-pdf-timeout" type="number" min="0" max="120" step="0.1" style="width:6em" name="settings[pdf_timeout]" value="45"', $html);
            self::assertStringContainsString('name="settings[types][post][include]" value="1"  checked=\'checked\'> Include This Content Type', $html);
            self::assertStringContainsString('name="settings[types][post][boost]" value="2.5"', $html);
            self::assertStringContainsString('name="settings[types][page][boost]" value="1"', $html);
            self::assertStringContainsString('<textarea id="uams-stopwords" name="settings[stopwords]" rows="3" cols="70">of the</textarea>', $html);
            self::assertStringContainsString('name="settings[synonyms]" rows="5" cols="70">heart = cardiac, cardiology</textarea>', $html);
            // A name of 23 characters gets the minimum width, and one of 29 gets its length plus 2
            self::assertStringContainsString('data-prefix="settings[types][post][fields]" data-label="Posts ACF Fields" data-size="31"', $html);
            self::assertStringContainsString('data-prefix="settings[types][post][taxonomies]" data-label="Posts Taxonomies" data-size="28"', $html);
            // The blank row after the saved ones carries a weight of 1
            self::assertStringContainsString('name="settings[types][post][fields][2][weight]" value="1"', $html);
            self::assertStringContainsString('name="settings[types][post][taxonomies][1][weight]" value="1"', $html);
            self::assertStringContainsString('name="settings[types][post][fields][0][weight]" value="2"', $html);
            self::assertStringContainsString('aria-label="Posts ACF Fields name 1"', $html);
            self::assertStringContainsString('aria-label="Posts ACF Fields weight 2"', $html);
            self::assertStringContainsString('aria-label="Remove Posts ACF Fields row 3"', $html);
            self::assertStringContainsString('data-size="31"><thead>', $html, 'A table with no suggestions has no list attribute.');
            self::assertStringContainsString('<input type="hidden" name="_wpnonce" value="nonce-for-uams_search_save">', $html);
            self::assertStringContainsString('<input type="submit" value="Save network settings"></form>', $html);
            self::assertStringContainsString('<input type="hidden" name="uams_search_choices_nonce" value="nonce-for-uams_search_refresh_choices"></form>', $html);
        }

        public function test_a_type_that_is_not_included_prints_unchecked_with_the_default_boost(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['types' => ['page' => 1.0, 'zebra' => 1.0]];
            $GLOBALS['uams_test_types'] = ['post' => (object) ['labels' => (object) ['name' => 'Posts']], 'page' => (object) ['labels' => (object) ['name' => 'Pages']]];
            $html = $this->render('settings_page');

            self::assertStringContainsString('name="settings[types][post][include]" value="1" > Include This Content Type', $html);
            self::assertStringContainsString('name="settings[types][post][boost]" value="1"', $html);
            self::assertStringContainsString('<strong>Zebra (zebra)</strong>', $html, 'A type WordPress has no label for is named from its handle.');
        }

        public function test_the_rebuild_controls_check_the_no_index_box_only_when_the_setting_says_so_or_only_no_index_sites_exist(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['preview_noindex' => true];
            $html = $this->render('page');
            self::assertStringContainsString('<input id="uams-rebuild-noindex" type="checkbox"  checked=\'checked\'>', $html);
            self::assertSame(1, substr_count($html, "checked='checked'"));

            $GLOBALS['uams_test_options']['uams_search_settings'] = ['preview_noindex' => false];
            self::assertStringContainsString('<input id="uams-rebuild-noindex" type="checkbox" > Include', $this->render('page'));
        }

        public function test_the_rebuild_controls_offer_no_index_sites_when_only_they_exist(): void
        {
            $GLOBALS['uams_test_multisite'] = true;
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['preview_noindex' => false];
            try {
                foreach ([[['public' => [], 'all' => [3]], true], [['public' => [2], 'all' => [2, 3]], false], [['public' => [], 'all' => []], false]] as [$sites, $checked]) {
                    $GLOBALS['uams_test_site_ids'] = $sites;
                    $html = $this->render('rebuild_controls');
                    self::assertSame($checked, str_contains($html, 'type="checkbox"  checked=\'checked\'>'), json_encode($sites, JSON_THROW_ON_ERROR));
                }
            } finally {
                unset($GLOBALS['uams_test_site_ids']);
                $GLOBALS['uams_test_multisite'] = false;
            }
        }

        public function test_each_site_on_a_network_is_listed_by_name_and_number(): void
        {
            $GLOBALS['uams_test_multisite'] = true;
            $GLOBALS['uams_test_caps'] = ['manage_network_options'];
            $GLOBALS['uams_test_blog_names'] = [1 => 'Main Site', 5 => 'Cardiology'];
            $GLOBALS['uams_test_site_ids'] = ['public' => [1, 5], 'all' => [1, 5]];
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['sites' => [5]];
            SiteNames::reset();
            try {
                $html = $this->render('settings_page');
            } finally {
                unset($GLOBALS['uams_test_blog_names'], $GLOBALS['uams_test_site_ids']);
                $GLOBALS['uams_test_multisite'] = false;
                $GLOBALS['uams_test_caps'] = ['manage_options'];
                SiteNames::reset();
            }

            self::assertStringContainsString('name="settings[sites][]" value="1" > Main Site (#1)', $html);
            self::assertStringContainsString('name="settings[sites][]" value="5"  checked=\'checked\'> Cardiology (#5)', $html);
        }

        public function test_the_setup_form_replaces_a_screen_whose_tables_are_missing(): void
        {
            $GLOBALS['uams_test_options'] = [];
            $this->tablesReady(false);
            $html = $this->render('reports_page');

            self::assertSame('<div class="wrap"><h1>UAMSWP Search Reports</h1><form method="post" action="https://example.test/wp-admin/admin-post.php"><input type="hidden" name="action" value="uams_search_setup"><input type="hidden" name="_wpnonce" value="nonce-for-uams_search_setup"><input type="submit" value="Create index tables"></form></div>', $html);
        }

        public function test_headings_and_field_labels_follow_ap_style(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['weights' => ['title' => 1, 'pdf' => 1, 'long_name' => 1]];
            $all = $this->render('page').$this->render('reports_page').$this->render('settings_page');

            foreach (['Index Status', 'Rebuild Index', 'Failed Items and PDFs Without Text', 'Searches With No Results, Last 30 Days', 'Sub-Sites in Whole-Website Search', 'Source Weights', 'Content To Index', 'Include This Content Type', 'Type Boost', 'ACF Fields', 'Extra Stop Words, Separated by Spaces', 'Synonyms, One Rule per Line, Such as heart = cardiac, cardiology', 'PDF Time Limit, Seconds', 'Keep Search Text With No Results for up to 90 Days', 'Use UAMSWP Search for Visitors', 'Include No-Index Sites for Signed-In Previews', '>Title</label>', '>PDF</label>', '>Long Name</label>'] as $text) {
                self::assertStringContainsString($text, $all, $text);
            }

            foreach (['Index status', 'Content to index', 'Source weights', 'Type boost', '>Pdf</label>'] as $old) {
                self::assertStringNotContainsString($old, $all, $old);
            }
        }

        public function test_number_fields_sit_in_label_and_box_columns_tied_by_id(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['weights' => ['title' => 1, 'pdf' => 2]];
            $html = $this->render('settings_page');

            preg_match_all('/<tr><th scope="row"><label for="([^"]+)">([^<]+)<\/label><\/th><td><input id="([^"]+)" type="number"[^>]* style="width:6em" name="([^"]+)" value="([^"]*)"><\/td><\/tr>/', $html, $m, PREG_SET_ORDER);
            $fields = array_column($m, 4);
            foreach ($m as $row) {
                self::assertSame($row[1], $row[3], 'The label points at its own box.');
            }

            self::assertContains('settings[weights][title]', $fields);
            self::assertContains('settings[weights][pdf]', $fields);
            self::assertContains('settings[pdf_max_mb]', $fields);
            self::assertContains('settings[pdf_timeout]', $fields);
            self::assertSame(count($fields), count(array_unique(array_column($m, 3))), 'Every id is unique.');
            self::assertSame(substr_count($html, 'type="number" min="0" max="100" step="0.1" style'), substr_count($html, 'style="width:6em"') - 1, 'Only the PDF time limit has another maximum.');
            self::assertStringNotContainsString('<p><label>Title <input', $html);
        }

        public function test_field_tables_print_saved_rows_and_one_blank_row_with_wide_name_boxes(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['types' => ['post' => 1.0], 'taxonomies' => [], 'fields' => ['post' => ['physician_short_clinical_bio_for_the_profile_page' => 2.0, 'b' => 1.0]]];
            $html = $this->render('settings_page');

            preg_match('/data-prefix="settings\[types\]\[post\]\[fields\]"[^>]*data-size="(\d+)"/', $html, $fields);
            preg_match('/data-prefix="settings\[types\]\[post\]\[taxonomies\]"[^>]*data-size="(\d+)"/', $html, $taxonomies);
            self::assertGreaterThanOrEqual(strlen('physician_short_clinical_bio_for_the_profile_page') + 2, (int) ($fields[1] ?? 0), 'Wide enough for the longest saved name.');
            self::assertSame('28', $taxonomies[1] ?? '', 'At least as wide as physician_short_clinical_bio.');
            self::assertSame(6, substr_count($html, 'name="settings[types][post][fields]['), 'Two saved rows and one blank row, each with a name and a weight.');
            self::assertStringNotContainsString('name="settings[types][post][fields][3]', $html);
            self::assertStringContainsString('name="settings[types][post][taxonomies][0][name]"', $html);
            self::assertStringNotContainsString('name="settings[types][post][taxonomies][1]', $html, 'A table with no saved rows starts with one blank row.');
            self::assertStringContainsString('aria-label="Add Row to Posts ACF Fields" hidden>Add Row</button>', $html);
            self::assertStringContainsString('aria-label="Remove Posts ACF Fields row 1" hidden>Remove</button>', $html);
        }

        public function test_name_boxes_suggest_the_cached_names_without_asking_any_site(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['types' => ['post' => 1.0], 'fields' => ['post' => ['subtitle' => 1.0]], 'taxonomies' => []];
            $GLOBALS['uams_test_options']['uams_search_name_choices'] = ['refreshed' => '2026-11-12 18:00:00', 'fields' => ['post' => ['subtitle', 'teaser']], 'taxonomies' => ['post' => ['category']]];
            $html = $this->render('settings_page');

            self::assertStringContainsString('<datalist id="uams-search-names-fields-post"><option value="subtitle"></option><option value="teaser"></option></datalist>', $html);
            self::assertStringContainsString('<datalist id="uams-search-names-taxonomies-post"><option value="category"></option></datalist>', $html);
            self::assertSame(2, substr_count($html, 'list="uams-search-names-fields-post" aria-label="Posts ACF Fields name'), 'The saved row and the blank row both suggest.');
            self::assertStringContainsString('data-list="uams-search-names-fields-post"', $html, 'Rows added with Add Row read the list from the table.');
            self::assertMatchesRegularExpression('/Add Row<\/button><\/p><datalist id="uams-search-names-fields-post">/', $html, 'The Add Row paragraph stays right after its table.');
            self::assertStringContainsString('<p id="uams-search-choices-status">The Name boxes suggest the field and taxonomy names in use on each content type across every site, last refreshed '.Time::local('2026-11-12 18:00:00').'. Any other name can still be typed. Refreshing asks every site and leaves this page, so save your changes first. <button type="submit" class="button" form="uams-search-refresh-choices" aria-describedby="uams-search-choices-status">Refresh Choices</button></p>', $html);
            self::assertStringContainsString('<tr><td><input size="28" list="uams-search-names-fields-post" aria-label="Posts ACF Fields name 1" name="settings[types][post][fields][0][name]" value="subtitle"></td>', $html);
            self::assertStringContainsString('data-size="28" data-list="uams-search-names-fields-post"><thead>', $html);
            self::assertStringContainsString('form="uams-search-refresh-choices"', $html);
            $lookups = array_filter($GLOBALS['wpdb']->queries, static fn (string $sql): bool => str_contains($sql, 'postmeta') || str_contains($sql, 'term_relationships'));
            self::assertSame([], $lookups, 'The screen reads the cache only.');
            // Control: the recorder sees a screen that does query, the Index screen's status table
            $this->render('page');
            self::assertNotSame([], $GLOBALS['wpdb']->queries, 'The stand-in records the queries screens run.');
        }

        public function test_a_suggestion_with_a_label_prints_it_and_the_rest_print_the_name_alone(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['types' => ['post' => 1.0], 'fields' => ['post' => []], 'taxonomies' => []];
            $GLOBALS['uams_test_options']['uams_search_name_choices'] = [
                'refreshed' => '2026-11-12 18:00:00',
                'fields' => ['post' => ['subtitle', 'teaser']],
                'taxonomies' => ['post' => ['category', 'other']],
                'labels' => ['fields' => ['subtitle' => 'Sub "Title" <b>'], 'taxonomies' => ['category' => 'Categories']],
            ];
            $html = $this->render('settings_page');

            self::assertStringContainsString('<datalist id="uams-search-names-fields-post"><option value="subtitle" label="Sub &quot;Title&quot; &lt;b&gt;"></option><option value="teaser"></option></datalist>', $html, 'The label is escaped, and a name with no label stays bare.');
            self::assertStringContainsString('<datalist id="uams-search-names-taxonomies-post"><option value="category" label="Categories"></option><option value="other"></option></datalist>', $html);
            // A name's label belongs to the group it was saved under
            $GLOBALS['uams_test_options']['uams_search_name_choices']['labels'] = ['fields' => [], 'taxonomies' => ['subtitle' => 'Wrong group']];
            self::assertStringNotContainsString('Wrong group', $this->render('settings_page'));
        }

        public function test_name_boxes_are_plain_text_boxes_until_the_choices_are_refreshed(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['types' => ['post' => 1.0], 'fields' => ['post' => ['subtitle' => 1.0]], 'taxonomies' => []];
            $html = $this->render('settings_page');

            self::assertStringNotContainsString('<datalist', $html);
            self::assertStringNotContainsString(' list="', $html);
            self::assertStringNotContainsString('data-list=', $html);
            self::assertStringContainsString('name="settings[types][post][fields][0][name]" value="subtitle"', $html, 'The saved name is still shown and can be edited.');
            self::assertStringContainsString('no suggestions yet', $html);
            self::assertStringContainsString('>Refresh Choices</button>', $html);
        }

        public function test_only_the_settings_screen_loads_the_row_script(): void
        {
            foreach (['uams-search', 'uams-search-reports'] as $page) {
                $_GET = ['page' => $page];
                Admin::settings_assets();
            }

            self::assertSame([], $GLOBALS['uams_test_enqueued']);

            $_GET = ['page' => 'uams-search-settings'];
            Admin::settings_assets();
            self::assertSame(['uams-search-settings'], $GLOBALS['uams_test_enqueued']);
        }
    }
}

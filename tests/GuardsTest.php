<?php

namespace {

    // Stand-ins for the WordPress functions the settings save and the result filters call.
    if (! function_exists('wp_die')) {
        function wp_die(mixed $message = ''): never
        {
            throw new RuntimeException('wp_die: '.$message);
        }
    }

    if (! function_exists('check_admin_referer')) {
        function check_admin_referer(mixed $action = -1, mixed $query_arg = '_wpnonce'): int|false
        {
            $GLOBALS['uams_test_nonce_checks'][] = func_get_args();

            return true;
        }
    }

    if (! function_exists('wp_unslash')) {
        /**
         * @phpstan-template T
         * @phpstan-param T $s
         * @phpstan-return T
         */
        function wp_unslash(mixed $s): mixed
        {
            return $s;
        }
    }

    if (! function_exists('sanitize_key')) {
        function sanitize_key(mixed $key): string
        {
            return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $key));
        }
    }

    if (! function_exists('update_site_option')) {
        function update_site_option(mixed $name, mixed $value): bool
        {
            $GLOBALS['uams_test_options'][$name] = $value;

            return true;
        }
    }

    if (! function_exists('wp_safe_redirect')) {
        function wp_safe_redirect(mixed $url): bool
        {
            $GLOBALS['uams_test_redirect'] = $url;

            throw new RuntimeException('redirect');
        }
    }

    if (! function_exists('current_user_can')) {
        function current_user_can(mixed $capability, mixed ...$_args): bool
        {
            return in_array($capability, $GLOBALS['uams_test_caps'] ?? [], true);
        }
    }

    if (! function_exists('admin_url')) {
        function admin_url(mixed $path = ''): string
        {
            return 'https://example.test/wp-admin/'.$path;
        }
    }

    if (! function_exists('get_search_query')) {
        function get_search_query(mixed $_escaped = true): string
        {
            return '';
        }
    }

    if (! function_exists('add_query_arg')) {
        function add_query_arg(mixed $args, mixed $url): string
        {
            return $url.'?'.http_build_query($args);
        }
    }

    if (! function_exists('home_url')) {
        function home_url(mixed $path = ''): string
        {
            return 'https://example.test'.$path;
        }
    }

    if (! function_exists('esc_url')) {
        function esc_url(mixed $url): string
        {
            return $url;
        }
    }

    if (! function_exists('esc_html')) {
        function esc_html(mixed $s): string
        {
            return htmlspecialchars((string) $s);
        }
    }

    if (! function_exists('get_post_type_object')) {
        function get_post_type_object(mixed $type): mixed
        {
            return $GLOBALS['uams_test_types'][$type] ?? null;
        }
    }
}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use RuntimeException;
    use UAMS_Search\Admin;
    use UAMS_Search\Integration;

    final class GuardsTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['uams_test_multisite'] = false;
            $GLOBALS['uams_test_caps'] = ['manage_options'];
            $GLOBALS['uams_test_options'] = [];
            $GLOBALS['uams_test_types'] = [];
            $_POST = [];
            $_GET = [];
        }

        protected function tearDown(): void
        {
            $_POST = [];
            $_GET = [];
            $GLOBALS['uams_test_options'] = [];
        }

        public function test_saving_with_a_post_type_stores_the_settings(): void
        {
            $_POST['settings'] = ['types' => ['post' => ['include' => '1', 'boost' => '2']], 'stopwords' => '', 'synonyms' => ''];

            try {
                Admin::save();
                self::fail('The save must end in a redirect.');
            } catch (RuntimeException $runtimeException) {
                self::assertSame('redirect', $runtimeException->getMessage());
            }

            self::assertSame(['post' => 2.0], $GLOBALS['uams_test_options']['uams_search_settings']['types']);
            self::assertSame('https://example.test/wp-admin/options-general.php?page=uams-search-settings', $GLOBALS['uams_test_redirect'], 'The save returns to the settings screen.');
        }

        public function test_saving_without_the_stopwords_field_stores_the_rest_and_an_empty_list(): void
        {
            $_POST['settings'] = ['types' => ['post' => ['include' => '1', 'boost' => '2']], 'synonyms' => ''];

            try {
                Admin::save();
            } catch (RuntimeException) {
            }

            $saved = $GLOBALS['uams_test_options']['uams_search_settings'];
            self::assertSame(['post' => 2.0], $saved['types']);
            self::assertSame([], $saved['stopwords']);
        }

        public function test_saving_with_the_stopwords_field_stores_its_words(): void
        {
            $_POST['settings'] = ['types' => ['post' => ['include' => '1']], 'stopwords' => 'alpha beta', 'synonyms' => ''];

            try {
                Admin::save();
            } catch (RuntimeException) {
            }

            self::assertSame(['alpha', 'beta'], $GLOBALS['uams_test_options']['uams_search_settings']['stopwords']);
        }

        public function test_saving_without_a_post_type_is_refused_and_stores_nothing(): void
        {
            $_POST['settings'] = ['types' => ['post' => ['boost' => '2']]];

            try {
                Admin::save();
                self::fail('The save must be refused.');
            } catch (RuntimeException $runtimeException) {
                self::assertSame('wp_die: Choose at least one post type.', $runtimeException->getMessage());
            }

            self::assertArrayNotHasKey('uams_search_settings', $GLOBALS['uams_test_options']);
        }

        public function test_the_type_filter_skips_a_type_that_is_not_registered(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['types' => ['post' => 1.0, 'gone' => 1.0]];
            $GLOBALS['uams_test_types']['post'] = (object) ['labels' => (object) ['name' => 'Posts']];

            $method = new ReflectionMethod(Integration::class, 'filters');
            ob_start();
            $method->invoke(null);
            $html = ob_get_clean();

            self::assertStringContainsString('>News</a>', $html);
            self::assertStringNotContainsString('type=gone', $html);
        }

        public function test_rows_added_and_removed_in_the_browser_save_in_their_order(): void
        {
            // Rows 1 and 3 were removed after the page loaded; rows 7 and 8 were added; row 9 was left blank.
            $_POST['settings'] = [
                'types' => ['post' => [
                    'include' => '1',
                    'fields' => [
                        0 => ['name' => 'kept_first', 'weight' => '2'],
                        2 => ['name' => 'kept_second', 'weight' => '3'],
                        7 => ['name' => 'added_one', 'weight' => '1'],
                        8 => ['name' => 'added_two', 'weight' => '0.5'],
                        9 => ['name' => '', 'weight' => '1'],
                    ],
                    'taxonomies' => [],
                ]],
                'stopwords' => '',
                'synonyms' => '',
            ];

            try {
                Admin::save();
            } catch (RuntimeException) {
            }

            $saved = $GLOBALS['uams_test_options']['uams_search_settings'];
            self::assertSame(['kept_first' => 2.0, 'kept_second' => 3.0, 'added_one' => 1.0, 'added_two' => 0.5], $saved['fields']['post']);
            self::assertSame([], $saved['taxonomies']['post']);
            self::assertArrayNotHasKey('removed_row', $saved['fields']['post']);
        }

        /**
         * @param  array<string, mixed>  $settings
         * @return array<mixed>
         */
        private function save(array $settings): array
        {
            $_POST['settings'] = $settings + ['types' => ['post' => ['include' => '1']]];

            try {
                Admin::save();
                self::fail('The save must end in a redirect.');
            } catch (RuntimeException $runtimeException) {
                self::assertSame('redirect', $runtimeException->getMessage());
            }

            return $GLOBALS['uams_test_options']['uams_search_settings'];
        }

        public function test_synonym_rules_keep_one_word_keys_and_clean_alternates(): void
        {
            $long = implode(' ', array_fill(0, 40, 'abc'));
            $many = implode(', ', array_map(static fn (int $n): string => 'alt'.$n, range(1, 12)));
            $saved = $this->save(['synonyms' => "Heart = Cardiac, Cardiology, cardiac, , Heart Care\ntwo words = ignored\nno equals sign\nlong = $long\nmany = $many\nbare ="]);

            self::assertSame(['heart', 'long', 'many', 'bare'], array_keys($saved['synonyms']));
            self::assertSame(['cardiac', 'cardiology', 'heart care'], $saved['synonyms']['heart']);
            self::assertSame([substr(str_repeat('abc ', 40), 0, 100)], $saved['synonyms']['long']);
            self::assertSame(array_map(static fn (int $n): string => 'alt'.$n, range(1, 10)), $saved['synonyms']['many']);
            self::assertSame([], $saved['synonyms']['bare']);
            self::assertSame([], $this->save(['synonyms' => ['not', 'text']])['synonyms']);
        }

        public function test_switches_numbers_and_sites_are_stored_in_range(): void
        {
            $saved = $this->save([
                'enabled' => '', 'live' => '1', 'logging' => '1', 'pdf' => '1', 'pdf_parent' => '',
                'results_per_page' => '500', 'pdf_max_mb' => '500', 'pdf_timeout' => '500',
                'sites' => ['1', '7', 'x'], 'weights' => ['title' => '150', 'terms' => '-3', 'fields' => 'abc', 'content' => '2.5'],
            ]);

            self::assertFalse($saved['enabled']);
            self::assertTrue($saved['live']);
            self::assertTrue($saved['logging']);
            self::assertTrue($saved['pdf']);
            self::assertFalse($saved['pdf_parent']);
            self::assertSame(100, $saved['results_per_page']);
            self::assertEquals(100.0, $saved['pdf_max_mb']);
            self::assertSame(120, $saved['pdf_timeout']);
            self::assertSame([1], $saved['sites']);
            self::assertSame(['title' => 100.0, 'terms' => 0.0, 'fields' => 0.0, 'content' => 2.5, 'pdf' => 0.0], $saved['weights']);

            $saved = $this->save(['pdf_max_mb' => '0', 'pdf_timeout' => '0']);
            self::assertEquals(1.0, $saved['pdf_max_mb']);
            self::assertSame(1, $saved['pdf_timeout']);
        }

        public function test_search_cannot_be_enabled_before_the_index_exists(): void
        {
            $_POST['settings'] = ['enabled' => '1', 'types' => ['post' => ['include' => '1']]];

            try {
                Admin::save();
                self::fail('Enabling search without an index must be refused.');
            } catch (RuntimeException $runtimeException) {
                self::assertSame('wp_die: Create and build the index before enabling search.', $runtimeException->getMessage());
            }

            self::assertArrayNotHasKey('uams_search_settings', $GLOBALS['uams_test_options']);
        }

        public function test_saving_without_the_capability_is_refused_and_stores_nothing(): void
        {
            $GLOBALS['uams_test_caps'] = [];
            $_POST['settings'] = ['types' => ['post' => ['include' => '1']]];

            try {
                Admin::save();
                self::fail('A user who cannot manage settings must be refused.');
            } catch (RuntimeException $runtimeException) {
                self::assertSame('wp_die: You cannot manage search settings.', $runtimeException->getMessage());
            }

            self::assertArrayNotHasKey('uams_search_settings', $GLOBALS['uams_test_options']);
        }

        public function test_saving_without_a_synonyms_field_stores_no_rules_and_raises_no_warning(): void
        {
            $warnings = [];
            set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            });
            try {
                $saved = $this->save([]);
            } finally {
                restore_error_handler();
            }

            self::assertSame([], $warnings);
            self::assertSame([], $saved['synonyms']);
        }

        public function test_a_field_or_taxonomy_row_without_a_name_is_skipped_without_a_warning(): void
        {
            $warnings = [];
            set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            });
            try {
                $saved = $this->save(['types' => ['post' => ['include' => '1', 'fields' => [['weight' => '3'], ['name' => 'Subtitle', 'weight' => '3']], 'taxonomies' => [['weight' => '2']]]]]);
            } finally {
                restore_error_handler();
            }

            self::assertSame([], $warnings);
            self::assertEquals(['post' => ['subtitle' => 3]], $saved['fields']);
            self::assertSame(['post' => []], $saved['taxonomies']);
        }

        public function test_each_switch_is_saved_on_its_own_and_defaults_off(): void
        {
            $keys = ['live', 'logging', 'pdf', 'pdf_parent'];
            $off = $this->save(['live' => '', 'logging' => '', 'pdf' => '', 'pdf_parent' => '']);
            foreach ($keys as $key) {
                self::assertFalse($off[$key], $key);
            }

            foreach ($keys as $on) {
                $saved = $this->save([$on => '1']);
                foreach ($keys as $key) {
                    self::assertSame($key === $on, $saved[$key], $on.' / '.$key);
                }
            }

            // A value that is not empty switches it on, whatever it says
            self::assertTrue($this->save(['pdf' => 'on'])['pdf']);
            // A switch missing from the form is off, not left at its earlier value
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['live' => true, 'logging' => true, 'pdf' => true, 'pdf_parent' => true];
            $saved = $this->save([]);
            foreach ($keys as $key) {
                self::assertFalse($saved[$key], $key);
            }
        }

        public function test_the_page_size_and_pdf_limits_default_and_clamp_at_each_edge(): void
        {
            $saved = $this->save([]);
            self::assertSame(20, $saved['results_per_page']);
            self::assertEquals(20.0, $saved['pdf_max_mb']);
            self::assertSame(30, $saved['pdf_timeout']);

            foreach (['0' => 1, '-5' => 1, '1' => 1, '2' => 2, '99' => 99, '100' => 100, '101' => 100, 'abc' => 20] as $input => $expected) {
                self::assertSame($expected, $this->save(['results_per_page' => (string) $input])['results_per_page'], 'page size '.$input);
            }

            foreach (['0' => 1.0, '1' => 1.0, '1.5' => 1.5, '2' => 2.0, '99.5' => 99.5, '100' => 100.0, '100.5' => 100.0, '101' => 100.0] as $input => $expected) {
                self::assertSame($expected, (float) $this->save(['pdf_max_mb' => (string) $input])['pdf_max_mb'], 'size limit '.$input);
            }

            foreach (['0' => 1, '-4' => 1, '1' => 1, '2' => 2, '119' => 119, '120' => 120, '121' => 120, '45.9' => 45] as $input => $expected) {
                self::assertSame($expected, $this->save(['pdf_timeout' => (string) $input])['pdf_timeout'], 'timeout '.$input);
            }
        }

        public function test_chosen_sites_accept_a_single_value_and_repeat_nothing(): void
        {
            self::assertSame([1], $this->save(['sites' => '1'])['sites']);
            self::assertSame([1], $this->save(['sites' => ['1', '1', 1]])['sites']);
            self::assertSame([], $this->save(['sites' => ['2', '3']])['sites']);
            self::assertSame([], $this->save(['sites' => 'abc'])['sites']);
            self::assertSame([], $this->save([])['sites']);
        }

        public function test_weights_default_to_zero_and_keep_only_the_known_sources(): void
        {
            $saved = $this->save(['weights' => ['title' => '7', 'bogus' => '9']]);

            self::assertSame(['title' => 7.0, 'terms' => 0.0, 'fields' => 0.0, 'content' => 0.0, 'pdf' => 0.0], $saved['weights']);
            self::assertSame(['title' => 0.0, 'terms' => 0.0, 'fields' => 0.0, 'content' => 0.0, 'pdf' => 0.0], $this->save([])['weights']);
        }

        public function test_chosen_types_keep_their_boosts_and_ignore_what_is_not_a_chosen_type(): void
        {
            $saved = $this->save(['types' => [
                'skipped' => ['boost' => '3'],
                'text' => 'include',
                '!!!' => ['include' => '1'],
                'Post Type' => ['include' => '1', 'boost' => '2.5'],
                'page' => ['include' => '1'],
            ]]);

            self::assertSame(['posttype' => 2.5, 'page' => 1.0], $saved['types']);
            self::assertSame(['posttype', 'page'], array_keys($saved['fields']));
            self::assertSame(['posttype', 'page'], array_keys($saved['taxonomies']));
        }

        public function test_fields_and_taxonomies_are_read_from_their_own_rows(): void
        {
            $saved = $this->save(['types' => ['post' => ['include' => '1',
                'fields' => [['name' => 'Subtitle', 'weight' => '4'], 'text', ['name' => ['a'], 'weight' => '2'], ['name' => 'Nick Name']],
                'taxonomies' => [['name' => 'Category', 'weight' => '1.5'], ['name' => 'tag']],
            ]]]);

            self::assertSame(['subtitle' => 4.0, 'nickname' => 1.0], $saved['fields']['post']);
            self::assertSame(['category' => 1.5, 'tag' => 1.0], $saved['taxonomies']['post']);
        }

        public function test_stop_words_and_synonyms_accept_text_only(): void
        {
            self::assertSame([], $this->save(['stopwords' => ['a', 'b']])['stopwords']);
            self::assertSame(['a', 'b'], $this->save(['stopwords' => 'a b'])['stopwords']);
            self::assertSame(['heart' => ['cardiac']], $this->save(['synonyms' => "heart=cardiac\r\nbad\r\n"])['synonyms']);
            self::assertSame(['a' => ['b'], 'c' => ['d']], $this->save(['synonyms' => "a=b\nc=d"])['synonyms']);
            self::assertSame(['a' => ['b', 'c']], $this->save(['synonyms' => 'a=b,b,c'])['synonyms']);
        }

        public function test_the_save_keeps_the_settings_the_form_does_not_carry(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['related_provider_weight' => 0.5];

            self::assertSame(0.5, $this->save([])['related_provider_weight']);
        }
    }
}

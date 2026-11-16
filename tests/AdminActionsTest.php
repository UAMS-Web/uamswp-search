<?php

namespace {
    use UAMS_Search\Admin;
    use UAMS_Search\Cast;
    use UAMS_Search\Tests\Support\StandIn;

    // Stand-ins for the WordPress functions the admin actions call.
    if (! function_exists('add_action')) {
        function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): void
        {
            StandIn::add_hook($hook, $callback);
            StandIn::add_hook_args($hook, [$callback, $priority, $args]);
        }
    }

    if (! function_exists('add_filter')) {
        function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): void
        {
            StandIn::add_hook($hook, $callback);
            StandIn::add_hook_args($hook, [$callback, $priority, $args]);
        }
    }

    if (! function_exists('wp_die')) {
        function wp_die(mixed $message = ''): never
        {
            throw new RuntimeException('wp_die: '.Cast::string($message));
        }
    }

    if (! function_exists('check_admin_referer')) {
        function check_admin_referer(mixed $action = -1, mixed $query_arg = '_wpnonce'): int
        {
            StandIn::add_nonce_check(func_get_args());

            return 1;
        }
    }

    if (! function_exists('check_ajax_referer')) {
        function check_ajax_referer(mixed $action = '', mixed $field = ''): int
        {
            StandIn::add_nonce_check(func_get_args());

            return 1;
        }
    }

    if (! function_exists('wp_send_json_error')) {
        function wp_send_json_error(mixed $data = null, mixed $status = 200): never
        {
            throw new RuntimeException('json_error:'.Cast::string($status).':'.json_encode($data));
        }
    }

    if (! function_exists('wp_send_json_success')) {
        function wp_send_json_success(mixed $data = null): never
        {
            throw new RuntimeException('json_success:'.json_encode($data));
        }
    }

    if (! function_exists('current_user_can')) {
        function current_user_can(mixed $capability, mixed ...$_args): bool
        {
            return in_array($capability, StandIn::caps(), true);
        }
    }

    if (! function_exists('sanitize_key')) {
        function sanitize_key(mixed $key): string
        {
            return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', Cast::string($key)));
        }
    }

    if (! function_exists('sanitize_text_field')) {
        function sanitize_text_field(mixed $s): string
        {
            return trim(strip_tags(Cast::string($s)));
        }
    }

    if (! function_exists('wp_unslash')) {
        /**
         * @phpstan-template T
         *
         * @phpstan-param T $s
         *
         * @phpstan-return T
         */
        function wp_unslash(mixed $s): mixed
        {
            return $s;
        }
    }

    if (! function_exists('absint')) {
        function absint(mixed $value): int
        {
            return abs(Cast::int($value));
        }
    }

    if (! function_exists('maybe_unserialize')) {
        function maybe_unserialize(mixed $value): mixed
        {
            return is_string($value) ? (@unserialize($value) ?: $value) : $value;
        }
    }

    if (! function_exists('get_current_network_id')) {
        function get_current_network_id(): int
        {
            return 1;
        }
    }

    if (! function_exists('wp_safe_redirect')) {
        function wp_safe_redirect(mixed $url): bool
        {
            StandIn::set_redirect(Cast::string($url));

            throw new RuntimeException('redirect');
        }
    }

    if (! function_exists('admin_url')) {
        function admin_url(mixed $path = ''): string
        {
            return 'https://example.test/wp-admin/'.Cast::string($path);
        }
    }

    if (! function_exists('network_admin_url')) {
        function network_admin_url(mixed $path = ''): string
        {
            return 'https://example.test/wp-admin/network/'.Cast::string($path);
        }
    }

    if (! function_exists('add_query_arg')) {
        function add_query_arg(mixed $args, mixed $url): string
        {
            $base = Cast::string($url);

            return $base.(str_contains($base, '?') ? '&' : '?').http_build_query(is_array($args) ? $args : []);
        }
    }

    if (! function_exists('esc_url')) {
        function esc_url(mixed $url, mixed $_protocols = null): string
        {
            return Cast::string($url);
        }
    }

    if (! function_exists('esc_html')) {
        function esc_html(mixed $s): string
        {
            return htmlspecialchars(Cast::string($s));
        }
    }

    if (! function_exists('checked')) {
        function checked(mixed $checked, mixed $current = true, mixed $display = true): string
        {
            // The same comparison, output and return as WordPress's helper
            $result = Cast::string($checked) === Cast::string($current) ? " checked='checked'" : '';
            if ($display) {
                echo $result;
            }

            return $result;
        }
    }

    if (! function_exists('get_post_meta')) {
        function get_post_meta(mixed $id, mixed $key, mixed $_single = true): mixed
        {
            return StandIn::meta_value(Cast::int($id), Cast::string($key));
        }
    }

    if (! function_exists('update_option')) {
        function update_option(mixed $name, mixed $value): bool
        {
            StandIn::put_option(Cast::string($name), $value);

            return true;
        }
    }

    if (! function_exists('update_post_meta')) {
        function update_post_meta(mixed $id, mixed $key, mixed $value): bool
        {
            StandIn::put_meta(Cast::int($id), Cast::string($key), $value);

            return true;
        }
    }
}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;
    use RuntimeException;
    use UAMS_Search\Admin;
    use UAMS_Search\Config;
    use UAMS_Search\DB;
    use UAMS_Search\Tests\Support\Prepare;
    use UAMS_Search\Tests\Support\StandIn;
    use WP_Post;

    final class AdminActionsTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['uams_test_multisite'] = false;
            $GLOBALS['uams_test_options'] = [];
            $GLOBALS['uams_test_caps'] = ['manage_options'];
            $GLOBALS['uams_test_hooks'] = [];
            $GLOBALS['uams_test_meta'] = [];
            StandIn::set_redirect(null);
            $GLOBALS['uams_test_nonce_checks'] = [];
            $_POST = [];
            $_GET = [];
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $this->tableReady(null);
        }

        /**
         * A stored option that holds an array, checked to be one.
         *
         * @return array<mixed>
         */
        private static function stored(string $name): array
        {
            $value = StandIn::option($name);
            self::assertIsArray($value);

            return $value;
        }

        /**
         * The search-visibility field an attachment edit filter adds, checked to be an array.
         *
         * @return array<mixed>
         */
        private static function excludeField(mixed $fields): array
        {
            self::assertIsArray($fields);
            self::assertArrayHasKey('uams_search_exclude', $fields);
            self::assertIsArray($fields['uams_search_exclude']);

            return $fields['uams_search_exclude'];
        }

        /** The HTML of the search-visibility field, checked to be a string. */
        private static function excludeHtml(mixed $fields): string
        {
            $field = self::excludeField($fields);
            self::assertArrayHasKey('html', $field);
            self::assertIsString($field['html']);

            return $field['html'];
        }

        protected function tearDown(): void
        {
            $this->tableReady(null);
            unset($GLOBALS['wpdb'], $GLOBALS['uams_test_blog_calls'], $GLOBALS['uams_test_site_ids'], $GLOBALS['uams_test_sites_asked']);
            $GLOBALS['uams_test_multisite'] = false;
            $_POST = [];
            $_GET = [];
            unset($_SERVER['REQUEST_METHOD']);
        }

        private function tableReady(?bool $value): void
        {
            $property = new ReflectionProperty(DB::class, 'table_ready');
            $property->setValue(null, $value);
        }

        /** Make the index tables look present, with a saved rebuild job holding the given status (or none). */
        private function indexReady(?string $status): void
        {
            StandIn::put_option('uams_search_version', UAMS_SEARCH_SCHEMA_VERSION);
            $this->tableReady(true);
            StandIn::set_db(new IndexStatusDb($status));
        }

        private function printed(callable $call): string
        {
            ob_start();

            try {
                $call();
            } finally {
                $html = (string) ob_get_clean();
            }

            return $html;
        }

        private function thrown(callable $call): string
        {
            try {
                $call();
            } catch (RuntimeException $runtimeException) {
                return $runtimeException->getMessage();
            }

            self::fail('The call must end by throwing.');
        }

        public function test_the_rebuild_endpoint_refuses_users_without_the_capability(): void
        {
            $GLOBALS['uams_test_caps'] = [];
            self::assertSame('json_error:403:{"message":"You cannot rebuild this search index."}', $this->thrown(Admin::rebuild_ajax(...)));

            $GLOBALS['uams_test_multisite'] = true;
            $GLOBALS['uams_test_caps'] = ['manage_options'];
            self::assertStringStartsWith('json_error:403:', $this->thrown(Admin::rebuild_ajax(...)), 'A site administrator may not rebuild a network index.');

            $GLOBALS['uams_test_caps'] = ['manage_network_options'];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            self::assertSame('json_error:405:{"message":"Use a POST request."}', $this->thrown(Admin::rebuild_ajax(...)));
        }

        public function test_the_rebuild_endpoint_rejects_requests_that_are_not_post(): void
        {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            self::assertStringStartsWith('json_error:405:', $this->thrown(Admin::rebuild_ajax(...)));

            unset($_SERVER['REQUEST_METHOD']);
            self::assertStringStartsWith('json_error:405:', $this->thrown(Admin::rebuild_ajax(...)));
        }

        public function test_the_rebuild_endpoint_reports_an_unknown_action_as_a_bad_request(): void
        {
            $_POST['op'] = 'explode';
            self::assertSame('json_error:400:{"message":"Unknown rebuild action."}', $this->thrown(Admin::rebuild_ajax(...)));

            $_POST = [];
            self::assertStringStartsWith('json_error:400:', $this->thrown(Admin::rebuild_ajax(...)), 'A missing action is unknown too.');
        }

        public function test_the_rebuild_endpoint_answers_status_with_the_saved_job(): void
        {
            $this->indexReady('paused');
            $_POST['op'] = 'status';

            self::assertSame('json_success:{"job":{"status":"paused"},"busy":false}', $this->thrown(Admin::rebuild_ajax(...)));
        }

        public function test_the_rebuild_endpoint_reports_a_failure_inside_an_action_as_a_server_error(): void
        {
            $this->indexReady('paused');
            StandIn::db(IndexStatusDb::class)->last_error = 'disk full';
            $_POST['op'] = 'status';

            self::assertSame('json_error:500:{"message":"disk full"}', $this->thrown(Admin::rebuild_ajax(...)));
        }

        public function test_the_notice_is_silent_for_users_who_cannot_manage_the_index(): void
        {
            $GLOBALS['uams_test_caps'] = [];

            self::assertSame('', $this->printed(Admin::notice(...)));
        }

        public function test_the_notice_asks_for_the_tables_when_they_are_missing(): void
        {
            $html = $this->printed(Admin::notice(...));

            self::assertSame('<div class="notice notice-warning"><p>UAMSWP Search needs its index tables. <a href="https://example.test/wp-admin/options-general.php?page=uams-search">Create them on the Index screen</a>.</p></div>', $html);
        }

        public function test_the_notice_asks_for_a_rebuild_when_a_site_is_out_of_date(): void
        {
            $this->indexReady(null);

            $html = $this->printed(Admin::notice(...));

            self::assertStringContainsString('Some UAMSWP Search sites need a rebuild.', $html);
            self::assertStringNotContainsString('needs its index tables', $html);
            self::assertStringNotContainsString('in progress', $html);
        }

        public function test_the_notice_is_silent_when_the_index_is_current_and_idle(): void
        {
            $this->indexReady(null);
            $fingerprint = Config::fingerprint();
            foreach (Config::index_sites() as $site) {
                StandIn::put_option('uams_search_fingerprint_'.$site, $fingerprint);
            }

            self::assertSame('', $this->printed(Admin::notice(...)));
        }

        public function test_the_notice_reports_a_rebuild_in_progress_and_nothing_for_a_finished_one(): void
        {
            $this->indexReady('running');
            $html = $this->printed(Admin::notice(...));
            self::assertStringContainsString('<div class="notice notice-info"><p>A browser rebuild is in progress or paused. Saved edits remain queued until it finishes or is canceled. <a href="https://example.test/wp-admin/options-general.php?page=uams-search#uams-search-rebuild">Resume or cancel the rebuild</a>.</p></div>', $html);

            $this->indexReady('done');
            self::assertStringNotContainsString('in progress', $this->printed(Admin::notice(...)));
        }

        public function test_setup_refuses_users_without_the_capability(): void
        {
            $GLOBALS['uams_test_caps'] = [];

            self::assertSame('wp_die: You cannot manage search settings.', $this->thrown(Admin::setup(...)));
        }

        public function test_refreshing_the_name_choices_saves_them_and_returns_to_settings(): void
        {
            defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
            $GLOBALS['wpdb'] = new class
            {
                public string $last_error = '';

                public function get_blog_prefix(int $site): string
                {
                    return 'wp_';
                }

                public function esc_like(string $text): string
                {
                    return addcslashes($text, '_%\\');
                }

                public function prepare(string $query, mixed ...$args): string
                {
                    return Prepare::sql($query, $args);
                }

                /** @return array<mixed> */
                public function get_results(string $query, mixed $output = null): array
                {
                    return str_contains($query, 'postmeta') ? [['type' => 'post', 'name' => 'subtitle']] : [];
                }
            };

            self::assertSame('redirect', $this->thrown(Admin::refresh_choices(...)));
            self::assertSame('https://example.test/wp-admin/options-general.php?page=uams-search-settings', StandIn::redirect());
            self::assertSame(['post' => ['subtitle']], self::stored('uams_search_name_choices')['fields']);
        }

        public function test_refreshing_the_name_choices_without_the_capability_asks_no_site(): void
        {
            $GLOBALS['uams_test_caps'] = [];

            self::assertSame('wp_die: You cannot manage search settings.', $this->thrown(Admin::refresh_choices(...)));
            self::assertArrayNotHasKey('uams_search_name_choices', StandIn::options());
        }

        public function test_retry_refuses_an_unknown_site_or_item_before_queueing_anything(): void
        {
            foreach ([['999', '5'], ['1', '0']] as [$site, $object]) {
                $_GET = ['site' => $site, 'object' => $object];
                self::assertSame('wp_die: Invalid site or item.', $this->thrown(Admin::retry(...)));
            }

            $GLOBALS['uams_test_caps'] = [];
            $_GET = ['site' => '1', 'object' => '5'];
            self::assertSame('wp_die: You cannot manage search settings.', $this->thrown(Admin::retry(...)));
        }

        public function test_saving_site_settings_without_the_capability_is_refused_and_stores_nothing(): void
        {
            $GLOBALS['uams_test_caps'] = [];
            $_POST = ['scope' => 'site', 'google' => '1'];

            self::assertSame('wp_die: You cannot manage this site.', $this->thrown(Admin::save_site(...)));
            self::assertSame([], $GLOBALS['uams_test_options']);
        }

        public function test_the_pdf_checkbox_appears_only_on_pdf_attachments(): void
        {
            Admin::hooks();
            $edit = StandIn::hooks()['attachment_fields_to_edit'][0];

            $image = new WP_Post;
            $image->post_mime_type = 'image/png';
            self::assertSame(['kept' => 1], $edit(['kept' => 1], $image));

            $pdf = new WP_Post;
            $pdf->post_mime_type = 'application/pdf';

            $fields = $edit([], $pdf);
            self::assertSame('Search Visibility', self::excludeField($fields)['label']);
            self::assertStringContainsString('name="attachments[42][uams_search_present]"', self::excludeHtml($fields));
            self::assertStringNotContainsString('checked', self::excludeHtml($fields));

            StandIn::put_meta(42, '_uams_search_exclude', '1');
            self::assertStringContainsString('checked', self::excludeHtml($edit([], $pdf)));
        }

        public function test_the_pdf_checkbox_reads_one_value_and_prints_nothing_itself(): void
        {
            Admin::hooks();
            $edit = StandIn::hooks()['attachment_fields_to_edit'][0];
            $pdf = new WP_Post;
            $pdf->post_mime_type = 'application/pdf';
            $GLOBALS['uams_test_meta_record'] = true;
            $GLOBALS['uams_test_meta_reads'] = [];
            StandIn::put_meta(42, '_uams_search_exclude', '1');
            try {
                self::assertSame('', $this->printed(static function () use ($edit, $pdf): void {
                    $edit([], $pdf);
                }));
            } finally {
                unset($GLOBALS['uams_test_meta_record']);
            }

            self::assertSame([['_uams_search_exclude', true]], StandIn::meta_reads());
        }

        public function test_the_pdf_checkbox_saves_only_when_submitted_by_a_user_who_can_edit(): void
        {
            Admin::hooks();
            $save = StandIn::hooks()['attachment_fields_to_save'][0];
            $post = ['ID' => 42];

            // Not submitted: nothing changes.
            self::assertSame($post, $save($post, []));
            self::assertSame([], StandIn::meta());

            // Not submitted, even by a user who can edit: nothing changes.
            $GLOBALS['uams_test_caps'] = ['edit_post'];
            self::assertSame($post, $save($post, ['uams_search_exclude' => '1']));
            self::assertSame([], StandIn::meta());

            // Submitted without the capability: nothing changes.
            $GLOBALS['uams_test_caps'] = [];
            self::assertSame($post, $save($post, ['uams_search_present' => '1', 'uams_search_exclude' => '1']));
            self::assertSame([], StandIn::meta());

            // Submitted by a user who can edit the attachment.
            $GLOBALS['uams_test_caps'] = ['edit_post'];
            self::assertSame($post, $save($post, ['uams_search_present' => '1', 'uams_search_exclude' => '1']));
            self::assertSame(1, StandIn::meta_value(42, '_uams_search_exclude'));

            $save($post, ['uams_search_present' => '1']);
            self::assertSame(0, StandIn::meta_value(42, '_uams_search_exclude'));
        }

        /**
         * A recording stand-in for the database that lets the table installer run to the end.
         *
         * @return RecordingDb The stand-in; `queries` holds what ran.
         */
        private function recordingDb(string $tokenSize = '3'): RecordingDb
        {
            defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
            StandIn::put_option('uams_search_version', '0.0.0');
            $GLOBALS['wpdb'] = new RecordingDb($tokenSize);

            return $GLOBALS['wpdb'];
        }

        public function test_setup_creates_the_tables_and_returns_to_the_index_screen(): void
        {
            $db = $this->recordingDb();

            self::assertSame('redirect', $this->thrown(Admin::setup(...)));
            self::assertSame('https://example.test/wp-admin/options-general.php?page=uams-search', StandIn::redirect());
            self::assertNotEmpty(array_filter($db->queries, static fn (string $q): bool => str_contains($q, 'CREATE TABLE IF NOT EXISTS `wp_uams_search_index`')));
            self::assertSame([['uams_search_setup']], StandIn::nonce_checks());
        }

        public function test_setup_reports_the_reason_the_tables_could_not_be_created_and_does_not_redirect(): void
        {
            $db = $this->recordingDb('1');

            self::assertSame('wp_die: UAMS Search requires innodb_ft_min_token_size=3. Run wp uams-search doctor.', $this->thrown(Admin::setup(...)));
            self::assertNull(StandIn::redirect());
            self::assertSame([], array_filter($db->queries, static fn (string $q): bool => str_contains($q, 'CREATE TABLE')));
        }

        public function test_setup_refuses_a_user_who_cannot_manage_settings_before_touching_anything(): void
        {
            $GLOBALS['uams_test_caps'] = [];
            $db = $this->recordingDb();

            self::assertSame('wp_die: You cannot manage search settings.', $this->thrown(Admin::setup(...)));
            self::assertSame([], $db->queries);
            self::assertSame([], StandIn::nonce_checks());
        }

        public function test_refreshing_the_name_choices_reports_a_failed_lookup_and_keeps_the_old_choices(): void
        {
            defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
            StandIn::put_option('uams_search_name_choices', ['fields' => ['kept' => []]]);
            $GLOBALS['wpdb'] = new class
            {
                public string $last_error = 'lookup failed';

                public function get_blog_prefix(int $site): string
                {
                    return 'wp_';
                }

                public function esc_like(string $text): string
                {
                    return $text;
                }

                public function prepare(string $query, mixed ...$args): string
                {
                    return Prepare::sql($query, $args);
                }

                /** @return array<mixed> */
                public function get_results(string $query, mixed $output = null): array
                {
                    return [];
                }
            };

            self::assertSame('wp_die: Could not read the names in use on site 1: lookup failed', $this->thrown(Admin::refresh_choices(...)));
            self::assertSame(['fields' => ['kept' => []]], StandIn::option('uams_search_name_choices'));
            self::assertNull(StandIn::redirect());
            self::assertSame([['uams_search_refresh_choices', 'uams_search_choices_nonce']], StandIn::nonce_checks());
        }

        public function test_the_save_checks_its_nonce_and_a_missing_settings_form_asks_for_a_type(): void
        {
            // A submission whose settings field is text rather than a form, which is not read as one
            $_POST['settings'] = 'text';

            self::assertSame('wp_die: Choose at least one post type.', $this->thrown(Admin::save(...)));
            self::assertSame([['uams_search_save']], StandIn::nonce_checks());
        }

        public function test_stop_words_and_synonyms_given_as_numbers_are_read_as_text(): void
        {
            $_POST['settings'] = ['types' => ['post' => ['include' => '1']], 'stopwords' => 5, 'synonyms' => 7];

            self::assertSame('redirect', $this->thrown(Admin::save(...)));
            self::assertSame(['5'], self::stored('uams_search_settings')['stopwords']);
            self::assertSame([], self::stored('uams_search_settings')['synonyms']);
        }

        public function test_a_synonym_line_is_split_at_its_first_equals_sign_only(): void
        {
            $_POST['settings'] = ['types' => ['post' => ['include' => '1']], 'synonyms' => 'heart=a=b'];

            self::assertSame('redirect', $this->thrown(Admin::save(...)));
            self::assertSame(['heart' => ['a b']], self::stored('uams_search_settings')['synonyms']);
        }

        public function test_saving_the_site_settings_stores_the_scope_and_the_google_switch(): void
        {
            $_POST = ['scope' => 'all', 'google' => '1'];
            StandIn::put_option('uams_search_google', false);

            self::assertSame('redirect', $this->thrown(Admin::save_site(...)));
            self::assertSame('all', StandIn::option('uams_search_scope'), 'The scope list includes the Google scope even while the Google results are off.');
            self::assertTrue(StandIn::option('uams_search_google'));
            self::assertSame('https://example.test/wp-admin/options-general.php?page=uams-search-site', StandIn::redirect());
            self::assertSame([['uams_search_site']], StandIn::nonce_checks());

            $_POST = ['scope' => 'site'];
            self::assertSame('redirect', $this->thrown(Admin::save_site(...)));
            self::assertSame('site', StandIn::option('uams_search_scope'));
            self::assertFalse(StandIn::option('uams_search_google'), 'A box left unticked saves as off.');
        }

        public function test_an_unknown_or_unreadable_scope_falls_back_to_the_first_option(): void
        {
            foreach (['bogus', ['site'], '', null] as $scope) {
                $_POST = ['scope' => $scope, 'google' => '1'];
                StandIn::put_option('uams_search_scope', 'all');
                $this->thrown(Admin::save_site(...));
                self::assertSame('site', StandIn::option('uams_search_scope'), var_export($scope, true));
            }

            $_POST = ['google' => ''];
            $this->thrown(Admin::save_site(...));
            self::assertSame('site', StandIn::option('uams_search_scope'));
            self::assertFalse(StandIn::option('uams_search_google'));
        }

        public function test_retry_queues_the_item_on_its_own_site_and_returns_to_the_failed_items(): void
        {
            $db = $this->queueDb();
            $_GET = ['site' => '1', 'object' => '9'];

            self::assertSame('redirect', $this->thrown(Admin::retry(...)));
            self::assertSame('https://example.test/wp-admin/options-general.php?page=uams-search-reports#failed-items', StandIn::redirect());
            self::assertCount(1, $db->queries);
            self::assertStringContainsString("VALUES (1,9,'upsert',", $db->queries[0]);
            self::assertSame([], $GLOBALS['uams_test_blog_calls'], 'The current site needs no switch.');
            self::assertSame([['uams_search_retry']], StandIn::nonce_checks());
        }

        public function test_retry_switches_to_another_site_and_always_switches_back(): void
        {
            $GLOBALS['uams_test_multisite'] = true;
            $GLOBALS['uams_test_caps'] = ['manage_network_options'];
            $GLOBALS['uams_test_site_ids'] = [1, 5];
            $db = $this->queueDb();
            $_GET = ['site' => '5', 'object' => '9'];

            self::assertSame('redirect', $this->thrown(Admin::retry(...)));
            self::assertSame('https://example.test/wp-admin/network/admin.php?page=uams-search-reports#failed-items', StandIn::redirect());
            self::assertStringContainsString("VALUES (5,9,'upsert',", $db->queries[0]);
            self::assertSame([['switch', 5], ['restore']], $GLOBALS['uams_test_blog_calls']);

            // A failure while queueing still switches back
            $GLOBALS['uams_test_blog_calls'] = [];
            $db->fail = true;
            self::assertSame('queue write failed', $this->thrown(Admin::retry(...)));
            self::assertSame([['switch', 5], ['restore']], $GLOBALS['uams_test_blog_calls']);
        }

        public function test_retry_needs_both_a_site_and_an_item(): void
        {
            $this->queueDb();
            foreach ([['object' => '9'], ['site' => '1']] as $get) {
                $_GET = $get;
                self::assertSame('wp_die: Invalid site or item.', $this->thrown(Admin::retry(...)), json_encode($get, JSON_THROW_ON_ERROR));
            }

            self::assertNull(StandIn::redirect());
        }

        /** A recording stand-in for the database, for code that queues an item. */
        private function queueDb(): QueueRecordingDb
        {
            StandIn::put_option('uams_search_version', UAMS_SEARCH_SCHEMA_VERSION);
            $this->tableReady(true);
            $GLOBALS['uams_test_blog_calls'] = [];
            $GLOBALS['wpdb'] = new QueueRecordingDb;

            return $GLOBALS['wpdb'];
        }

        public function test_every_screen_refuses_a_user_who_cannot_manage_the_plugin_before_printing(): void
        {
            $GLOBALS['uams_test_caps'] = [];
            foreach (['page', 'reports_page', 'settings_page'] as $screen) {
                self::assertSame('', $this->printed(function () use ($screen): void {
                    self::assertSame('wp_die: You cannot manage search settings.', $this->thrown(Admin::$screen(...)));
                }), $screen);
            }

            self::assertSame('', $this->printed(function (): void {
                self::assertSame('wp_die: You cannot manage this site.', $this->thrown(Admin::site_page(...)));
            }));
        }

        public function test_the_rebuild_endpoint_checks_its_nonce(): void
        {
            $_POST['op'] = 'explode';
            $this->thrown(Admin::rebuild_ajax(...));

            self::assertSame([['uams_search_rebuild', 'nonce']], StandIn::nonce_checks());
        }

        public function test_the_rebuild_endpoint_reads_the_action_in_any_case_and_refuses_anything_that_is_not_text(): void
        {
            $this->indexReady('paused');
            foreach (['STATUS', ' status '] as $op) {
                $_POST['op'] = $op;
                self::assertStringStartsWith('json_success:', $this->thrown(Admin::rebuild_ajax(...)), $op);
            }

            $_POST['op'] = ['status'];
            self::assertSame('json_error:400:{"message":"Unknown rebuild action."}', $this->thrown(Admin::rebuild_ajax(...)));
        }

        public function test_saving_a_chosen_site_keeps_a_list_whatever_the_numbers_of_the_other_sites(): void
        {
            $GLOBALS['uams_test_multisite'] = true;
            $GLOBALS['uams_test_caps'] = ['manage_network_options'];
            $GLOBALS['uams_test_site_ids'] = ['public' => [1, 5, 9], 'all' => [1, 5, 9]];
            $settings = ['types' => ['post' => ['include' => '1']], 'sites' => ['9', '5', '77']];
            $_POST['settings'] = $settings;

            self::assertSame('redirect', $this->thrown(Admin::save(...)));
            self::assertSame([5, 9], self::stored('uams_search_settings')['sites']);

            $settings['sites'] = ['9'];
            $_POST['settings'] = $settings;
            $this->thrown(Admin::save(...));
            self::assertSame([9], self::stored('uams_search_settings')['sites'], 'The list starts at zero.');
        }

        public function test_a_types_or_rows_field_that_is_not_a_list_is_ignored_without_a_warning(): void
        {
            $warnings = [];
            set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            });
            try {
                $_POST['settings'] = ['types' => 'text'];
                self::assertSame('wp_die: Choose at least one post type.', $this->thrown(Admin::save(...)));
                $_POST['settings'] = ['types' => ['post' => ['include' => '1', 'fields' => 'text', 'taxonomies' => 'text']]];
                self::assertSame('redirect', $this->thrown(Admin::save(...)));
            } finally {
                restore_error_handler();
            }

            self::assertSame([], $warnings);
            self::assertSame(['post' => []], self::stored('uams_search_settings')['fields']);
        }

        public function test_the_hooks_register_every_handler(): void
        {
            Admin::hooks();

            foreach (['network_admin_menu', 'admin_init', 'admin_menu', 'admin_post_uams_search_save', 'admin_post_uams_search_site', 'admin_post_uams_search_retry', 'admin_post_uams_search_setup', 'wp_ajax_uams_search_rebuild', 'admin_enqueue_scripts', 'admin_notices', 'network_admin_notices'] as $hook) {
                self::assertArrayHasKey($hook, StandIn::hooks(), $hook);
            }

            self::assertCount(2, StandIn::hooks()['admin_enqueue_scripts']);
        }

        public function test_the_hooks_register_each_handler_once_with_its_priority_and_argument_count(): void
        {
            $GLOBALS['uams_test_hook_args'] = [];
            Admin::hooks();
            $registered = StandIn::hook_args();
            $expected = [
                'network_admin_menu' => [[[Admin::class, 'network_menu'], 10, 1]],
                'admin_init' => [[[Admin::class, 'redirect_legacy_network_page'], 10, 1]],
                'admin_menu' => [[[Admin::class, 'site_menus'], 99, 1]],
                'admin_post_uams_search_save' => [[[Admin::class, 'save'], 10, 1]],
                'admin_post_uams_search_site' => [[[Admin::class, 'save_site'], 10, 1]],
                'admin_post_uams_search_retry' => [[[Admin::class, 'retry'], 10, 1]],
                'admin_post_uams_search_setup' => [[[Admin::class, 'setup'], 10, 1]],
                'admin_post_uams_search_refresh_choices' => [[[Admin::class, 'refresh_choices'], 10, 1]],
                'wp_ajax_uams_search_rebuild' => [[[Admin::class, 'rebuild_ajax'], 10, 1]],
                'admin_enqueue_scripts' => [[[Admin::class, 'rebuild_assets'], 10, 1], [[Admin::class, 'settings_assets'], 10, 1]],
                'admin_notices' => [[[Admin::class, 'notice'], 10, 1]],
                'network_admin_notices' => [[[Admin::class, 'notice'], 10, 1]],
            ];
            foreach ($expected as $hook => $entries) {
                self::assertSame($entries, $registered[$hook], $hook);
            }

            foreach (['attachment_fields_to_edit', 'attachment_fields_to_save'] as $hook) {
                self::assertCount(1, $registered[$hook], $hook);
                self::assertSame([10, 2], [$registered[$hook][0][1], $registered[$hook][0][2]], $hook);
            }

            self::assertCount(\count($expected) + 2, $registered);
        }

        public function test_the_pdf_checkbox_html_and_label_are_exact(): void
        {
            Admin::hooks();
            $edit = StandIn::hooks()['attachment_fields_to_edit'][0];
            $pdf = new WP_Post;
            $pdf->post_mime_type = 'application/pdf';

            self::assertSame([
                'label' => 'Search Visibility',
                'input' => 'html',
                'html' => '<input type="hidden" name="attachments[42][uams_search_present]" value="1"><label><input type="checkbox" name="attachments[42][uams_search_exclude]" value="1" > Exclude From Search</label>',
            ], self::excludeField($edit([], $pdf)));

            foreach (['1', 1, true, 'yes'] as $stored) {
                StandIn::put_meta(42, '_uams_search_exclude', $stored);
                self::assertStringContainsString("checked='checked'>", self::excludeHtml($edit([], $pdf)), var_export($stored, true));
            }

            foreach (['0', 0, '', false] as $stored) {
                StandIn::put_meta(42, '_uams_search_exclude', $stored);
                self::assertStringNotContainsString('checked', self::excludeHtml($edit([], $pdf)), var_export($stored, true));
            }
        }
    }

    /** A database double that holds one rebuild status, or none, and a last error a test can set. */
    final class IndexStatusDb
    {
        public string $last_error = '';

        public string $options = 'wp_options';

        public string $sitemeta = 'wp_sitemeta';

        public function __construct(private readonly ?string $status) {}

        public function prepare(string $query, mixed ...$args): string
        {
            return Prepare::sql($query, $args);
        }

        public function get_var(): ?string
        {
            return $this->status === null ? null : serialize(['status' => $this->status]);
        }
    }

    final class QueueRecordingDb
    {
        public string $last_error = '';

        public string $base_prefix = 'wp_';

        public bool $fail = false;

        /** @var list<string> */
        public array $queries = [];

        public function prepare(string $query, mixed ...$args): string
        {
            return Prepare::sql($query, $args);
        }

        public function query(string $query): int
        {
            if ($this->fail) {
                throw new RuntimeException('queue write failed');
            }

            $this->queries[] = $query;

            return 1;
        }
    }

    final class RecordingDb
    {
        public string $base_prefix = 'wp_';

        public string $dbname = 'test';

        public string $last_error = '';

        /** @var list<string> */
        public array $queries = [];

        public function __construct(private readonly string $tokenSize) {}

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4';
        }

        public function prepare(string $query, mixed ...$args): string
        {
            return Prepare::sql($query, $args);
        }

        public function get_var(string $query): ?string
        {
            $this->queries[] = $query;

            return str_contains($query, 'innodb_ft_min_token_size') ? $this->tokenSize : (str_contains($query, 'stopword_table') ? null : '0');
        }

        /** @return array<mixed> */
        public function get_row(string $query): array
        {
            $this->queries[] = $query;

            return ['Collation' => 'utf8mb4_bin', 'Type' => 'longtext'];
        }

        public function query(string $query): int
        {
            $this->queries[] = $query;

            return 1;
        }
    }
}

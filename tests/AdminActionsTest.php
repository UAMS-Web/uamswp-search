<?php

namespace {

    use UAMS_Search\Admin;

    // Stand-ins for the WordPress functions the admin actions call.
    if (! function_exists('add_action')) {
        function add_action($hook, $callback, $priority = 10, $args = 1): void
        {
            $GLOBALS['uams_test_hooks'][$hook][] = $callback;
        }
    }

    if (! function_exists('add_filter')) {
        function add_filter($hook, $callback, $priority = 10, $args = 1): void
        {
            $GLOBALS['uams_test_hooks'][$hook][] = $callback;
        }
    }

    if (! function_exists('wp_die')) {
        function wp_die($message = ''): never
        {
            throw new RuntimeException('wp_die: '.$message);
        }
    }

    if (! function_exists('check_admin_referer')) {
        function check_admin_referer($action = -1, $query_arg = '_wpnonce')
        {
            return true;
        }
    }

    if (! function_exists('check_ajax_referer')) {
        function check_ajax_referer($action = '', $field = '')
        {
            return true;
        }
    }

    if (! function_exists('wp_send_json_error')) {
        function wp_send_json_error($data = null, $status = 200): never
        {
            throw new RuntimeException('json_error:'.$status.':'.json_encode($data));
        }
    }

    if (! function_exists('wp_send_json_success')) {
        function wp_send_json_success($data = null): never
        {
            throw new RuntimeException('json_success:'.json_encode($data));
        }
    }

    if (! function_exists('current_user_can')) {
        function current_user_can($capability, ...$args): bool
        {
            return in_array($capability, $GLOBALS['uams_test_caps'] ?? [], true);
        }
    }

    if (! function_exists('sanitize_key')) {
        function sanitize_key($key)
        {
            return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $key));
        }
    }

    if (! function_exists('sanitize_text_field')) {
        function sanitize_text_field($s)
        {
            return trim(strip_tags((string) $s));
        }
    }

    if (! function_exists('wp_unslash')) {
        function wp_unslash($s)
        {
            return $s;
        }
    }

    if (! function_exists('absint')) {
        function absint($value): int
        {
            return abs((int) $value);
        }
    }

    if (! function_exists('maybe_unserialize')) {
        function maybe_unserialize($value)
        {
            return is_string($value) ? (@unserialize($value) ?: $value) : $value;
        }
    }

    if (! function_exists('get_current_network_id')) {
        function get_current_network_id()
        {
            return 1;
        }
    }

    if (! function_exists('wp_safe_redirect')) {
        function wp_safe_redirect($url): never
        {
            $GLOBALS['uams_test_redirect'] = $url;

            throw new RuntimeException('redirect');
        }
    }

    if (! function_exists('admin_url')) {
        function admin_url($path = '')
        {
            return 'https://example.test/wp-admin/'.$path;
        }
    }

    if (! function_exists('network_admin_url')) {
        function network_admin_url($path = '')
        {
            return 'https://example.test/wp-admin/network/'.$path;
        }
    }

    if (! function_exists('add_query_arg')) {
        function add_query_arg($args, $url)
        {
            return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($args);
        }
    }

    if (! function_exists('esc_url')) {
        function esc_url($url)
        {
            return $url;
        }
    }

    if (! function_exists('esc_html')) {
        function esc_html($s)
        {
            return htmlspecialchars((string) $s);
        }
    }

    if (! function_exists('checked')) {
        function checked($checked, $current = true, $display = true)
        {
            return $checked == $current ? 'checked' : '';
        }
    }

    if (! function_exists('get_post_meta')) {
        function get_post_meta($id, $key, $single = true)
        {
            return $GLOBALS['uams_test_meta'][$id][$key] ?? '';
        }
    }

    if (! function_exists('update_post_meta')) {
        function update_post_meta($id, $key, $value)
        {
            $GLOBALS['uams_test_meta'][$id][$key] = $value;

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
            $GLOBALS['uams_test_redirect'] = null;
            $_POST = [];
            $_GET = [];
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $this->tableReady(null);
        }

        protected function tearDown(): void
        {
            $this->tableReady(null);
            unset($GLOBALS['wpdb']);
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
            $GLOBALS['uams_test_options']['uams_search_version'] = UAMS_SEARCH_SCHEMA_VERSION;
            $this->tableReady(true);
            $GLOBALS['wpdb'] = new class($status)
            {
                public string $last_error = '';

                public string $options = 'wp_options';

                public string $sitemeta = 'wp_sitemeta';

                public function __construct(private readonly ?string $status) {}

                public function prepare(string $query, ...$args): string
                {
                    return $query;
                }

                public function get_var(string $query): ?string
                {
                    return $this->status === null ? null : serialize(['status' => $this->status]);
                }
            };
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
            $GLOBALS['wpdb']->last_error = 'disk full';
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

            self::assertStringContainsString('UAMSWP Search needs its index tables.', $html);
            self::assertStringContainsString('https://example.test/wp-admin/', $html);
            self::assertStringNotContainsString('rebuild', $html);
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
                $GLOBALS['uams_test_options']['uams_search_fingerprint_'.$site] = $fingerprint;
            }

            self::assertSame('', $this->printed(Admin::notice(...)));
        }

        public function test_the_notice_reports_a_rebuild_in_progress_and_nothing_for_a_finished_one(): void
        {
            $this->indexReady('running');
            $html = $this->printed(Admin::notice(...));
            self::assertStringContainsString('A browser rebuild is in progress or paused.', $html);
            self::assertStringContainsString('#uams-search-rebuild', $html);

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

                public function prepare(string $query, ...$args): string
                {
                    return $query;
                }

                public function get_results(string $query, $output = null): array
                {
                    return str_contains($query, 'postmeta') ? [['type' => 'post', 'name' => 'subtitle']] : [];
                }
            };

            self::assertSame('redirect', $this->thrown(Admin::refresh_choices(...)));
            self::assertSame('https://example.test/wp-admin/options-general.php?page=uams-search-settings', $GLOBALS['uams_test_redirect']);
            self::assertSame(['post' => ['subtitle']], $GLOBALS['uams_test_options']['uams_search_name_choices']['fields']);
        }

        public function test_refreshing_the_name_choices_without_the_capability_asks_no_site(): void
        {
            $GLOBALS['uams_test_caps'] = [];

            self::assertSame('wp_die: You cannot manage search settings.', $this->thrown(Admin::refresh_choices(...)));
            self::assertArrayNotHasKey('uams_search_name_choices', $GLOBALS['uams_test_options']);
        }

        public function test_retry_refuses_an_unknown_site_or_item_before_queueing_anything(): void
        {
            $_GET = ['site' => '999', 'object' => '5'];
            self::assertSame('wp_die: Invalid site or item.', $this->thrown(Admin::retry(...)));

            $_GET = ['site' => '1', 'object' => '0'];
            self::assertSame('wp_die: Invalid site or item.', $this->thrown(Admin::retry(...)));

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
            $edit = $GLOBALS['uams_test_hooks']['attachment_fields_to_edit'][0];

            $image = new WP_Post;
            $image->post_mime_type = 'image/png';
            self::assertSame(['kept' => 1], $edit(['kept' => 1], $image));

            $pdf = new WP_Post;
            $pdf->post_mime_type = 'application/pdf';

            $fields = $edit([], $pdf);
            self::assertSame('Search Visibility', $fields['uams_search_exclude']['label']);
            self::assertStringContainsString('name="attachments[42][uams_search_present]"', $fields['uams_search_exclude']['html']);
            self::assertStringNotContainsString('checked', $fields['uams_search_exclude']['html']);

            $GLOBALS['uams_test_meta'][42]['_uams_search_exclude'] = '1';
            self::assertStringContainsString('checked', $edit([], $pdf)['uams_search_exclude']['html']);
        }

        public function test_the_pdf_checkbox_saves_only_when_submitted_by_a_user_who_can_edit(): void
        {
            Admin::hooks();
            $save = $GLOBALS['uams_test_hooks']['attachment_fields_to_save'][0];
            $post = ['ID' => 42];

            // Not submitted: nothing changes.
            self::assertSame($post, $save($post, []));
            self::assertSame([], $GLOBALS['uams_test_meta']);

            // Not submitted, even by a user who can edit: nothing changes.
            $GLOBALS['uams_test_caps'] = ['edit_post'];
            self::assertSame($post, $save($post, ['uams_search_exclude' => '1']));
            self::assertSame([], $GLOBALS['uams_test_meta']);

            // Submitted without the capability: nothing changes.
            $GLOBALS['uams_test_caps'] = [];
            self::assertSame($post, $save($post, ['uams_search_present' => '1', 'uams_search_exclude' => '1']));
            self::assertSame([], $GLOBALS['uams_test_meta']);

            // Submitted by a user who can edit the attachment.
            $GLOBALS['uams_test_caps'] = ['edit_post'];
            self::assertSame($post, $save($post, ['uams_search_present' => '1', 'uams_search_exclude' => '1']));
            self::assertSame(1, $GLOBALS['uams_test_meta'][42]['_uams_search_exclude']);

            $save($post, ['uams_search_present' => '1']);
            self::assertSame(0, $GLOBALS['uams_test_meta'][42]['_uams_search_exclude']);
        }

        public function test_the_hooks_register_every_handler(): void
        {
            Admin::hooks();

            foreach (['network_admin_menu', 'admin_init', 'admin_menu', 'admin_post_uams_search_save', 'admin_post_uams_search_site', 'admin_post_uams_search_retry', 'admin_post_uams_search_setup', 'wp_ajax_uams_search_rebuild', 'admin_enqueue_scripts', 'admin_notices', 'network_admin_notices'] as $hook) {
                self::assertArrayHasKey($hook, $GLOBALS['uams_test_hooks'], $hook);
            }

            self::assertCount(2, $GLOBALS['uams_test_hooks']['admin_enqueue_scripts']);
        }
    }
}

<?php

namespace {

    function wp_get_environment_type()
    {
        return $GLOBALS['uams_test_environment'] ?? 'production';
    }

    function get_sites($args)
    {
        $GLOBALS['uams_test_sites_args'][] = $args;
        if (isset($GLOBALS['uams_test_sites_return'])) {
            return $GLOBALS['uams_test_sites_return'];
        }

        $sites = [1 => ['public' => 1, 'archived' => 0, 'spam' => 0, 'deleted' => 0], 4 => ['public' => 0, 'archived' => 0, 'spam' => 0, 'deleted' => 0], 5 => ['public' => 0, 'archived' => 1, 'spam' => 0, 'deleted' => 0], 6 => ['public' => 0, 'archived' => 0, 'spam' => 1, 'deleted' => 0], 7 => ['public' => 0, 'archived' => 0, 'spam' => 0, 'deleted' => 1]];

        return array_keys(array_filter($sites, static function (array $site) use ($args): bool {
            foreach (['public', 'archived', 'spam', 'deleted'] as $key) {
                if (isset($args[$key]) && $site[$key] !== $args[$key]) {
                    return false;
                }
            }

            return true;
        }));
    }
}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\TestCase;
    use UAMS_Search\Config;
    use UAMS_Search\FieldText;
    use UAMS_Search\Related;

    final class ConfigTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['uams_test_multisite'] = true;
            $GLOBALS['uams_test_options'] = [];
            $GLOBALS['uams_test_filters'] = [];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['uams_test_multisite'], $GLOBALS['uams_test_environment'], $GLOBALS['uams_test_logged_in'], $GLOBALS['uams_test_sites_args'], $GLOBALS['uams_test_sites_return']);
        }

        public function test_production_never_includes_no_index_sites(): void
        {
            $GLOBALS['uams_test_environment'] = 'production';
            $GLOBALS['uams_test_logged_in'] = true;
            self::assertSame([1], Config::public_sites());
        }

        public function test_anonymous_local_visitors_still_exclude_no_index_sites(): void
        {
            $GLOBALS['uams_test_environment'] = 'local';
            $GLOBALS['uams_test_logged_in'] = false;
            self::assertSame([1], Config::public_sites());
        }

        public function test_signed_in_local_preview_includes_only_healthy_sites(): void
        {
            $GLOBALS['uams_test_environment'] = 'local';
            $GLOBALS['uams_test_logged_in'] = true;
            self::assertSame([1, 4], Config::public_sites());
        }

        public function test_staging_preview_includes_no_index_sites_without_showing_them_to_visitors(): void
        {
            $GLOBALS['uams_test_environment'] = 'staging';
            $GLOBALS['uams_test_logged_in'] = true;
            self::assertSame([1, 4], Config::public_sites());
            $GLOBALS['uams_test_logged_in'] = false;
            self::assertSame([1], Config::public_sites());
            self::assertSame([1, 4], Config::index_sites());
        }

        public function test_explicit_preview_setting_works_on_unlabeled_staging_servers(): void
        {
            $GLOBALS['uams_test_environment'] = 'production';
            $GLOBALS['uams_test_logged_in'] = true;
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['preview_noindex' => true];
            self::assertSame([1, 4], Config::public_sites());
            $GLOBALS['uams_test_logged_in'] = false;
            self::assertSame([1], Config::public_sites());
            self::assertSame([1, 4], Config::index_sites());
        }

        public function test_a_saved_setting_of_the_wrong_type_reads_as_its_default(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => ['x'], 'types' => 'page', 'pdf_max_mb' => 'big', 'stopwords' => null, 'sites' => 'all', 'live' => 0, 'extra' => 'kept'];
            $defaults = Config::defaults();

            $config = Config::get();

            self::assertTrue($config['enabled'] === false || $config['enabled'] === true);
            self::assertSame($defaults['types'], $config['types']);
            self::assertSame($defaults['pdf_max_mb'], $config['pdf_max_mb']);
            self::assertSame([], $config['stopwords']);
            self::assertNull($config['sites']);
            self::assertFalse($config['live']);
            self::assertSame('kept', $config['extra']);
        }

        public function test_the_typed_accessors_return_their_declared_types(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => '1', 'related_provider_weight' => '0.5', 'types' => ['page' => 2], 'stopwords' => ['a']];

            self::assertTrue(Config::flag('enabled'));
            self::assertFalse(Config::flag('unknown'));
            self::assertSame(0.5, Config::number('related_provider_weight'));
            self::assertSame(['page' => 2], Config::section('types'));
            self::assertSame([], Config::section('unknown'));
        }

        public function test_entry_and_chosen_sites_return_arrays_or_null_whatever_is_saved(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['a' => 1], 'post' => 'bad'], 'sites' => ['2', 3]];

            self::assertSame(['a' => 1], Config::entry('fields', 'page'));
            self::assertSame([], Config::entry('fields', 'post'));
            self::assertSame([], Config::entry('fields', 'missing'));
            self::assertSame([], Config::entry('unknown', 'page'));
            self::assertSame([2, 3], Config::chosen_sites());

            $GLOBALS['uams_test_options']['uams_search_settings'] = ['sites' => 'all'];
            self::assertNull(Config::chosen_sites());
        }

        public function test_an_option_of_the_wrong_type_reads_as_its_default(): void
        {
            $GLOBALS['uams_test_options'] = ['date_format' => ['x'], 'uams_search_google' => ['x'], 'time_format' => 'H:i'];

            self::assertSame('F j, Y', Config::option_string('date_format', 'F j, Y'));
            self::assertSame('H:i', Config::option_string('time_format', 'g:i a'));
            self::assertTrue(Config::option_flag('uams_search_google', true));
            self::assertFalse(Config::option_flag('missing', false));
        }

        public function test_sites_covers_the_current_site_or_the_chosen_public_sites(): void
        {
            $GLOBALS['uams_test_logged_in'] = true;
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['preview_noindex' => true];

            // Public sites are 1 and 4 here; with no choice saved every public site is searched.
            self::assertSame([1, 4], Config::sites('install', 1));
            self::assertSame([1], Config::sites('site', 1));
            self::assertSame([4], Config::sites('site', 4));
            self::assertSame([], Config::sites('site', 9));

            $GLOBALS['uams_test_options']['uams_search_settings'] = ['preview_noindex' => true, 'sites' => [4, 99]];
            self::assertSame([4], Config::sites('install', 1));
            self::assertSame([1], Config::sites('site', 1));

            $GLOBALS['uams_test_options']['uams_search_settings'] = ['preview_noindex' => true, 'sites' => []];
            self::assertSame([], Config::sites('install', 1));
        }

        public function test_the_fingerprint_changes_with_each_setting_that_changes_the_index(): void
        {
            $base = ['fields' => ['page' => ['a' => 1]], 'taxonomies' => ['page' => ['b' => 1]], 'stopwords' => ['x'], 'types' => ['page' => 1.0], 'pdf' => false, 'pdf_parent' => false, 'pdf_max_mb' => 20, 'pdf_timeout' => 30, 'preview_noindex' => false, 'related_provider_weight' => 0.25];
            $GLOBALS['uams_test_options']['uams_search_settings'] = $base;
            $fingerprint = Config::fingerprint();
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
            self::assertSame($fingerprint, Config::fingerprint());
            self::assertSame($fingerprint, hash('sha256', json_encode([['page' => ['a' => 1]], ['page' => ['b' => 1]], ['x'], ['page'], false, false, 20.0, 30.0, false, Related::VERSION, FieldText::VERSION, 0.25])));

            $changes = ['fields' => ['page' => ['a' => 2]], 'taxonomies' => ['page' => ['b' => 2]], 'stopwords' => ['y'], 'types' => ['post' => 1.0], 'pdf' => true, 'pdf_parent' => true, 'pdf_max_mb' => 21, 'pdf_timeout' => 31, 'preview_noindex' => true, 'related_provider_weight' => 0.5];
            foreach ($changes as $key => $value) {
                $GLOBALS['uams_test_options']['uams_search_settings'] = [$key => $value] + $base;
                self::assertNotSame($fingerprint, Config::fingerprint(), $key);
            }

            // Settings that do not change what the index holds leave it alone.
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['results_per_page' => 50, 'live' => false, 'recency' => 0.9] + $base;
            self::assertSame($fingerprint, Config::fingerprint());
        }

        public function test_a_rebuild_is_needed_until_every_indexed_site_has_the_current_fingerprint(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['preview_noindex' => true];
            $fingerprint = Config::fingerprint();
            self::assertTrue(Config::needs_rebuild());

            $GLOBALS['uams_test_options']['uams_search_fingerprint_1'] = $fingerprint;
            self::assertTrue(Config::needs_rebuild());

            $GLOBALS['uams_test_options']['uams_search_fingerprint_4'] = 'old';
            self::assertTrue(Config::needs_rebuild());

            $GLOBALS['uams_test_options']['uams_search_fingerprint_4'] = $fingerprint;
            self::assertFalse(Config::needs_rebuild());

            $GLOBALS['uams_test_options']['uams_search_settings']['pdf_max_mb'] = 99;
            self::assertTrue(Config::needs_rebuild());
        }

        /** The defaults every setting starts from, written out so a changed number, type or key is noticed. */
        private static function expected_defaults(bool $preview): array
        {
            return [
                'enabled' => false, 'live' => true, 'logging' => true, 'preview_noindex' => $preview,
                'results_per_page' => 20,
                'sites' => null, 'related_provider_weight' => 0.25,
                'weights' => ['title' => 5, 'terms' => 3, 'fields' => 2, 'content' => 1, 'pdf' => 1],
                'types' => ['provider' => 1.5, 'expertise' => 1.3, 'location' => 1.2, 'condition' => 1.0, 'treatment' => 1.0, 'page' => 1.0, 'post' => 0.8, 'attachment' => 0.8],
                'fields' => [
                    'provider' => ['physician_first_name' => 1, 'physician_middle_name' => 1, 'physician_last_name' => 1, 'physician_full_name' => 1, 'physician_medium_name' => 1, 'physician_clinical_bio' => 1, 'physician_short_clinical_bio' => 1, 'physician_clinical_focus' => 1, 'physician_research_interests' => 1, 'physician_additional_info' => 1],
                    'location' => ['location_about' => 1, 'location_short_desc' => 1, 'location_description' => 1, 'location_address_1' => 1, 'location_address_2' => 1, 'location_city' => 1, 'location_state' => 1, 'location_zip' => 1, 'location_abbreviation' => 1, 'location_web_name' => 1, 'location_parking' => 1, 'location_direction' => 1],
                    'expertise' => ['post_excerpt' => 1, 'expertise_alternate_names' => 1],
                ],
                'taxonomies' => [
                    'provider' => ['condition' => 1, 'treatment' => 1, 'service_line' => 1, 'degree' => 1, 'language' => 1, 'clinical_title' => 1, 'affiliation' => 1],
                    'location' => ['service_line' => 1, 'language' => 1, 'region' => 1, 'building' => 1, 'parking' => 1],
                    'expertise' => [], 'page' => [], 'post' => ['category' => 1, 'post_tag' => 1],
                ],
                'stopwords' => [], 'synonyms' => [],
                'pdf' => true, 'pdf_max_mb' => 20, 'pdf_timeout' => 30, 'pdf_parent' => false,
                'extract_warn_seconds' => 5, 'phrase_bonus' => 5, 'recency' => 0.15,
            ];
        }

        public function test_the_defaults_are_exactly_these(): void
        {
            $GLOBALS['uams_test_environment'] = 'production';

            self::assertSame(self::expected_defaults(false), Config::defaults());
        }

        public function test_only_the_three_preview_environments_turn_the_preview_on_by_default(): void
        {
            foreach (['local' => true, 'development' => true, 'staging' => true, 'production' => false] as $environment => $preview) {
                $GLOBALS['uams_test_environment'] = $environment;
                self::assertSame($preview, Config::defaults()['preview_noindex'], $environment);
            }
        }

        public function test_the_settings_read_back_as_the_defaults_when_nothing_is_saved(): void
        {
            $GLOBALS['uams_test_environment'] = 'production';

            self::assertSame(self::expected_defaults(false), Config::get());
        }

        public function test_numeric_text_saved_for_a_number_becomes_that_number_and_not_its_negative(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['results_per_page' => '5', 'recency' => '0.5', 'pdf_timeout' => 7.5];
            $config = Config::get();

            self::assertSame(5, $config['results_per_page']);
            self::assertSame(0.5, $config['recency']);
            self::assertSame(7.5, $config['pdf_timeout']);
        }

        public function test_an_empty_option_string_reads_as_the_default(): void
        {
            $GLOBALS['uams_test_options']['date_format'] = '';

            self::assertSame('F j, Y', Config::option_string('date_format', 'F j, Y'));
        }

        public function test_a_setting_that_is_not_scalar_or_numeric_still_reads_as_its_declared_type(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['custom_list' => [1], 'custom_text' => 'abc'];

            self::assertTrue(Config::flag('custom_list'));
            self::assertSame(0.0, Config::number('custom_text'));
            self::assertSame(0.0, Config::number('nothing'));
        }

        public function test_a_weight_is_limited_to_zero_to_one_hundred_and_odd_values_are_zero(): void
        {
            self::assertSame(0.0, Config::weight(-5));
            self::assertSame(0.0, Config::weight(0));
            self::assertSame(2.5, Config::weight('2.5'));
            self::assertSame(100.0, Config::weight(100));
            self::assertSame(100.0, Config::weight(250));
            self::assertSame(0.0, Config::weight(true));
            self::assertSame(0.0, Config::weight('abc'));
            self::assertSame(0.0, Config::weight(INF));
            self::assertSame(0.0, Config::weight(NAN));
            self::assertSame(0.0, Config::weight(['5']));
            self::assertSame(0.0, Config::weight(null));
        }

        public function test_the_page_size_is_limited_to_one_to_one_hundred_and_odd_values_are_twenty(): void
        {
            self::assertSame(1, Config::page_size(0));
            self::assertSame(1, Config::page_size(-4));
            self::assertSame(1, Config::page_size(1));
            self::assertSame(100, Config::page_size(100));
            self::assertSame(100, Config::page_size(500));
            self::assertSame(37, Config::page_size('37'));
            self::assertSame(50, Config::page_size(50.0));
            self::assertSame(20, Config::page_size(true));
            self::assertSame(20, Config::page_size('abc'));
            self::assertSame(20, Config::page_size(INF));
            self::assertSame(20, Config::page_size(NAN));
            self::assertSame(20, Config::page_size(['5']));
        }

        public function test_without_a_requested_size_the_saved_size_is_used_and_zero_means_twenty(): void
        {
            self::assertSame(20, Config::page_size());

            $GLOBALS['uams_test_options']['uams_search_settings'] = ['results_per_page' => 35];
            self::assertSame(35, Config::page_size());

            $GLOBALS['uams_test_options']['uams_search_settings'] = ['results_per_page' => 0];
            self::assertSame(20, Config::page_size());
        }

        public function test_the_chosen_sites_are_a_list_whatever_their_keys(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['sites' => ['a' => '3', 'b' => 8]];

            self::assertSame([3, 8], Config::chosen_sites());
        }

        public function test_the_site_query_asks_for_healthy_public_sites_only_unless_noindex_sites_are_included(): void
        {
            $GLOBALS['uams_test_sites_args'] = [];
            Config::site_ids(false);
            Config::site_ids(true);

            self::assertSame([
                ['fields' => 'ids', 'number' => 0, 'archived' => 0, 'spam' => 0, 'deleted' => 0, 'public' => 1],
                ['fields' => 'ids', 'number' => 0, 'archived' => 0, 'spam' => 0, 'deleted' => 0],
            ], $GLOBALS['uams_test_sites_args']);
        }

        public function test_site_ids_are_integers_even_when_the_network_returns_text(): void
        {
            $GLOBALS['uams_test_sites_return'] = ['2', '9'];

            self::assertSame([2, 9], Config::site_ids(true));
        }

        public function test_a_single_site_never_queries_the_network(): void
        {
            $GLOBALS['uams_test_multisite'] = false;
            $GLOBALS['uams_test_sites_args'] = [];

            self::assertSame([1], Config::site_ids(true));
            self::assertSame([1], Config::public_sites());
            self::assertSame([1], Config::index_sites());
            self::assertSame([], $GLOBALS['uams_test_sites_args']);
        }

        public function test_the_labels_are_exactly_these(): void
        {
            self::assertSame(['provider' => 'People', 'expertise' => 'Clinical Areas of Expertise', 'location' => 'Clinical Locations', 'post' => 'News', 'attachment' => 'PDFs'], Config::type_labels());
            self::assertSame(['provider' => 'Person', 'location' => 'Clinical Location', 'expertise' => 'Area of Expertise', 'condition' => 'Condition', 'treatment' => 'Treatment or Procedure', 'post' => 'News', 'page' => 'Page', 'attachment' => 'PDF'], Config::result_labels());
        }

        public function test_google_is_offered_unless_the_option_or_a_filter_says_otherwise(): void
        {
            self::assertTrue(Config::google_enabled());

            $GLOBALS['uams_test_options']['uams_search_google'] = '0';
            self::assertFalse(Config::google_enabled());

            $GLOBALS['uams_test_options']['uams_search_google'] = '1';
            $GLOBALS['uams_test_filters']['uams_search_google_enabled'] = [static fn (): array => ['yes']];
            self::assertTrue(Config::google_enabled());
        }

        public function test_the_scope_falls_back_to_the_saved_default_and_then_to_the_first_option(): void
        {
            // A single site offers `site` and `all`
            $GLOBALS['uams_test_multisite'] = false;
            self::assertSame('all', Config::scope('all'));
            self::assertSame('site', Config::scope('install'));
            self::assertSame('site', Config::scope(5));

            $GLOBALS['uams_test_options']['uams_search_scope'] = 'all';
            self::assertSame('all', Config::scope('bogus'));

            $GLOBALS['uams_test_options']['uams_search_scope'] = 'install';
            self::assertSame('site', Config::scope());
        }
    }
}

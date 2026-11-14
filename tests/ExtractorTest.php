<?php

namespace UAMS_Search {
    // Records whether each post meta read asks for one value, then reads through the plain stand-in. A test turns the record on with `uams_test_meta_record`.
    function get_post_meta($id, $key, $single = false)
    {
        if (! empty($GLOBALS['uams_test_meta_record'])) {
            $GLOBALS['uams_test_meta_reads'][] = [$key, $single];
        }

        return \get_post_meta($id, $key, $single);
    }
}

namespace {

    if (! class_exists('WP_Error')) {
        class WP_Error
        {
            public function __construct(private string $code = '', private string $message = '', private mixed $data = '') {}

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_data(): mixed
            {
                return $this->data;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }
        }
    }

    if (! function_exists('get_post_meta')) {
        function get_post_meta($id, $key, $single = true)
        {
            return $GLOBALS['uams_test_meta'][$id][$key] ?? '';
        }
    }

    if (! function_exists('is_post_status_viewable')) {
        function is_post_status_viewable($status): bool
        {
            return in_array($status, $GLOBALS['uams_test_viewable_statuses'] ?? ['publish'], true);
        }
    }

    if (! function_exists('is_post_type_viewable')) {
        function is_post_type_viewable($type): bool
        {
            return ! in_array($type, $GLOBALS['uams_test_hidden_types'] ?? [], true);
        }
    }

    if (! function_exists('parse_blocks')) {
        function parse_blocks($content)
        {
            return $GLOBALS['uams_test_blocks'] ?? [['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $content]];
        }
    }

    if (! function_exists('serialize_blocks')) {
        function serialize_blocks($blocks)
        {
            $out = '';
            foreach ($blocks as $block) {
                $out .= ($block['innerHTML'] ?? '').serialize_blocks($block['innerBlocks'] ?? []);
            }

            return $out;
        }
    }

    if (! function_exists('strip_shortcodes')) {
        function strip_shortcodes($text)
        {
            return preg_replace('/\[[a-z_]+[^\]]*\]/', '', $text);
        }
    }

    if (! function_exists('taxonomy_exists')) {
        function taxonomy_exists($taxonomy): bool
        {
            return array_key_exists($taxonomy, $GLOBALS['uams_test_terms'] ?? []);
        }
    }

    if (! function_exists('wp_get_object_terms')) {
        function wp_get_object_terms($id, $taxonomy, $args = [])
        {
            $GLOBALS['uams_test_term_args'][] = [$taxonomy, $args];

            return $GLOBALS['uams_test_terms'][$taxonomy] ?? [];
        }
    }

    if (! function_exists('is_wp_error')) {
        function is_wp_error($value): bool
        {
            return $value instanceof WP_Error;
        }
    }

    // The same ACF definition stand-in FieldTextTest declares; whichever file loads first defines it.
    if (! function_exists('acf_get_field')) {
        function acf_get_field($selector)
        {
            $GLOBALS['uams_test_acf_lookups'][] = $selector;

            return $GLOBALS['uams_test_acf_fields'][$selector] ?? false;
        }
    }

    if (! function_exists('get_permalink')) {
        function get_permalink($post)
        {
            return 'https://example.test/?p='.(is_object($post) ? $post->ID : $post);
        }
    }

    if (! function_exists('wp_get_attachment_url')) {
        function wp_get_attachment_url($id)
        {
            return 'https://example.test/files/'.$id.'.pdf';
        }
    }

    // The same stand-ins `PdfTest` declares; whichever file loads first defines them.
    if (! function_exists('get_attached_file')) {
        function get_attached_file($id)
        {
            return $GLOBALS['uams_test_attached'] ?? __DIR__.'/../fixtures/text.pdf';
        }
    }

    if (! function_exists('wp_get_upload_dir')) {
        function wp_get_upload_dir()
        {
            return ['basedir' => $GLOBALS['uams_test_uploads'] ?? __DIR__.'/../fixtures'];
        }
    }
}

namespace UAMS_Search\Tests {
    use ArrayObject;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;
    use stdClass;
    use UAMS_Search\Extractor;
    use UAMS_Search\Related;
    use WP_Error;
    use WP_Post;

    /**
     * Pins for what the indexer reads from a post (#5). WordPress functions are stood in; a PDF is read from the cache of an unchanged file, so the worker itself is covered by `PdfTest`.
     *
     * Accepted equivalents, annotated in `Extractor.php` with their reasons: the meta branch of the field read (a test cannot undefine `get_field()` once an earlier test has defined it), the key of a field that does not start with `field_`, and the string casts of integer keys.
     */
    final class ExtractorTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['uams_test_options'] = ['uams_search_settings' => []];
            $GLOBALS['uams_test_filters'] = [];
            $GLOBALS['uams_test_meta'] = [];
            $GLOBALS['uams_test_posts'] = [];
            $GLOBALS['uams_test_terms'] = [];
            $GLOBALS['uams_test_viewable_statuses'] = ['publish'];
            $GLOBALS['uams_test_hidden_types'] = [];
            $GLOBALS['uams_test_acf_fields'] = [];
            $GLOBALS['uams_test_meta_reads'] = [];
            $GLOBALS['uams_test_term_args'] = [];
            unset($GLOBALS['uams_test_blocks'], $GLOBALS['uams_test_meta_record'], $GLOBALS['uams_test_acf']);
        }

        protected function tearDown(): void
        {
            $GLOBALS['uams_test_acf_fields'] = [];
            $GLOBALS['uams_test_options'] = [];
            $GLOBALS['uams_test_filters'] = [];
            $GLOBALS['uams_test_meta'] = [];
            $GLOBALS['uams_test_posts'] = [];
            $GLOBALS['uams_test_terms'] = [];
            unset($GLOBALS['uams_test_blocks'], $GLOBALS['uams_test_meta_record'], $GLOBALS['uams_test_acf']);
        }

        /** @param array<string, mixed> $props */
        private function post(array $props = []): WP_Post
        {
            $post = new WP_Post;
            foreach ($props + ['ID' => 42, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Orthopaedics', 'post_content' => '<p>Knee care</p>', 'post_date_gmt' => '2026-01-02 03:04:05', 'post_modified_gmt' => '2026-02-03 04:05:06', 'post_mime_type' => '', 'post_parent' => 0] as $key => $value) {
                $post->$key = $value;
            }

            return $post;
        }

        // --- Related::fields() ------------------------------------------------

        public function test_related_providers_skip_other_post_types_and_keep_hidden_or_missing_providers_as_dependencies(): void
        {
            $page = $this->post(['ID' => 42, 'post_type' => 'location']);
            $GLOBALS['uams_test_meta'] = [42 => ['physician_locations' => [12, 13, 14, 15]], 14 => ['physician_hidden' => '1']];
            $GLOBALS['uams_test_posts'] = [
                12 => $this->post(['ID' => 12, 'post_type' => 'provider', 'post_title' => 'Dr. <b>Alpha</b>']),
                13 => $this->post(['ID' => 13, 'post_type' => 'page', 'post_title' => 'Not a provider']),
                14 => $this->post(['ID' => 14, 'post_type' => 'provider', 'post_title' => 'Hidden Doctor']),
                15 => null,
            ];

            self::assertSame([
                ['provider_id' => 12, 'text' => 'Dr. Alpha', 'weight' => 0.25, 'source' => 'fields', 'provider_title' => 'Dr. <b>Alpha</b>'],
                ['provider_id' => 14, 'text' => '', 'weight' => 0.25, 'source' => 'fields'],
                ['provider_id' => 15, 'text' => '', 'weight' => 0.25, 'source' => 'fields'],
            ], Related::fields($page));
        }

        public function test_a_page_type_without_a_provider_field_never_reads_a_field_value(): void
        {
            // Without the early return the missing field name reads as the empty key, which holds providers here
            $GLOBALS['uams_test_meta'] = [42 => ['' => [12]]];
            $GLOBALS['uams_test_posts'] = [12 => $this->post(['ID' => 12, 'post_type' => 'provider', 'post_title' => 'Alpha'])];

            self::assertSame([], Related::fields($this->post(['ID' => 42, 'post_type' => 'page'])));
        }

        // --- eligible() -------------------------------------------------------

        public function test_an_ordinary_published_page_is_eligible(): void
        {
            self::assertTrue(Extractor::eligible($this->post()));
        }

        public function test_a_post_type_that_is_not_configured_is_not_eligible(): void
        {
            self::assertFalse(Extractor::eligible($this->post(['post_type' => 'nav_menu_item'])));
        }

        public function test_a_password_protected_post_is_not_eligible(): void
        {
            self::assertFalse(Extractor::eligible($this->post(['post_password' => 'secret'])));
        }

        public function test_a_post_marked_excluded_is_not_eligible(): void
        {
            $GLOBALS['uams_test_meta'][42]['_uams_search_exclude'] = '1';

            self::assertFalse(Extractor::eligible($this->post()));
        }

        public function test_a_filter_can_exclude_a_post_and_receives_it(): void
        {
            $seen = [];
            $GLOBALS['uams_test_filters']['uams_search_include_post'][] = static function (bool $include, WP_Post $post) use (&$seen): bool {
                $seen[] = $post->ID;

                return false;
            };

            self::assertFalse(Extractor::eligible($this->post()));
            self::assertSame([42], $seen);
        }

        public function test_a_hidden_provider_is_not_eligible(): void
        {
            $GLOBALS['uams_test_meta'][42]['physician_hidden'] = '1';

            self::assertFalse(Extractor::eligible($this->post(['post_type' => 'provider'])));
        }

        public function test_a_provider_marked_not_searchable_is_not_eligible_but_other_values_are(): void
        {
            $GLOBALS['uams_test_meta'][42]['physician_searchable'] = '0';
            self::assertFalse(Extractor::eligible($this->post(['post_type' => 'provider'])));

            $GLOBALS['uams_test_meta'][42]['physician_searchable'] = '1';
            self::assertTrue(Extractor::eligible($this->post(['post_type' => 'provider'])));
        }

        public function test_provider_flags_do_not_affect_other_post_types(): void
        {
            $GLOBALS['uams_test_meta'][42] = ['physician_hidden' => '1', 'physician_searchable' => '0', 'location_hidden' => '1'];

            self::assertTrue(Extractor::eligible($this->post(['post_type' => 'page'])));
        }

        public function test_a_hidden_location_is_not_eligible(): void
        {
            $GLOBALS['uams_test_meta'][42]['location_hidden'] = '1';

            self::assertFalse(Extractor::eligible($this->post(['post_type' => 'location'])));
            self::assertTrue(Extractor::eligible($this->post(['post_type' => 'location', 'ID' => 43])));
        }

        public function test_a_post_whose_status_is_not_viewable_is_not_eligible(): void
        {
            self::assertFalse(Extractor::eligible($this->post(['post_status' => 'draft'])));
        }

        public function test_a_post_whose_type_is_not_viewable_is_not_eligible(): void
        {
            $GLOBALS['uams_test_hidden_types'] = ['page'];

            self::assertFalse(Extractor::eligible($this->post()));
        }

        /** @param array<string, mixed> $props */
        private function pdf(array $props = []): WP_Post
        {
            return $this->post($props + ['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'application/pdf']);
        }

        public function test_a_pdf_attachment_is_eligible(): void
        {
            self::assertTrue(Extractor::eligible($this->pdf()));
            self::assertTrue(Extractor::eligible($this->pdf(['post_status' => 'publish'])));
        }

        public function test_pdf_indexing_can_be_turned_off(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['pdf' => false];

            self::assertFalse(Extractor::eligible($this->pdf()));
        }

        public function test_an_attachment_that_is_not_a_pdf_is_not_eligible(): void
        {
            self::assertFalse(Extractor::eligible($this->pdf(['post_mime_type' => 'image/png'])));
        }

        public function test_an_attachment_with_an_unexpected_status_is_not_eligible(): void
        {
            self::assertFalse(Extractor::eligible($this->pdf(['post_status' => 'trash'])));
        }

        public function test_a_pdf_attached_to_a_viewable_parent_is_eligible(): void
        {
            $GLOBALS['uams_test_posts'][7] = $this->post(['ID' => 7]);

            self::assertTrue(Extractor::eligible($this->pdf(['post_parent' => 7])));
        }

        public function test_a_pdf_whose_parent_is_missing_is_not_eligible(): void
        {
            $GLOBALS['uams_test_posts'][7] = null;

            self::assertFalse(Extractor::eligible($this->pdf(['post_parent' => 7])));
        }

        public function test_a_pdf_whose_parent_is_not_viewable_is_not_eligible(): void
        {
            $GLOBALS['uams_test_posts'][7] = $this->post(['ID' => 7, 'post_status' => 'private']);

            self::assertFalse(Extractor::eligible($this->pdf(['post_parent' => 7])));
        }

        public function test_a_pdf_whose_parent_is_password_protected_is_not_eligible(): void
        {
            $GLOBALS['uams_test_posts'][7] = $this->post(['ID' => 7, 'post_password' => 'secret']);

            self::assertFalse(Extractor::eligible($this->pdf(['post_parent' => 7])));
        }

        // --- extract() --------------------------------------------------------

        public function test_extract_describes_a_published_page(): void
        {
            $row = Extractor::extract($this->post(['post_title' => 'Knee <b>Clinic</b>', 'post_content' => '<p>Hip &amp; knee care</p>']));

            self::assertSame(1, $row['site_id']);
            self::assertSame(42, $row['object_id']);
            self::assertSame('post', $row['object_type']);
            self::assertSame('page', $row['post_type']);
            self::assertSame('Knee Clinic', $row['title']);
            self::assertSame('Hip & knee care', $row['content']);
            self::assertSame('https://example.test/?p=42', $row['url']);
            self::assertSame('2026-01-02 03:04:05', $row['published_at']);
            self::assertSame('2026-02-03 04:05:06', $row['modified_at']);
            self::assertSame('ready', $row['status']);
            self::assertSame('', $row['error']);
            self::assertSame('', $row['pdf']);
            self::assertSame(0, $row['file_size']);
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['indexed_at']);
        }

        public function test_extract_ignores_a_field_list_filter_that_returns_a_string(): void
        {
            $GLOBALS['uams_test_filters']['uams_search_fields'] = [static fn (): string => 'not a list'];

            $row = Extractor::extract($this->post(['post_content' => '<p>Hip care</p>']));

            self::assertSame('Hip care', $row['content']);
        }

        public function test_extract_stores_an_unset_gmt_date_as_null(): void
        {
            $row = Extractor::extract($this->post(['post_date_gmt' => '0000-00-00 00:00:00', 'post_modified_gmt' => '0000-00-00 00:00:00']));

            self::assertNull($row['published_at']);
            self::assertNull($row['modified_at']);
        }

        public function test_extract_never_indexes_html_comments_or_shortcodes_in_the_body(): void
        {
            $row = Extractor::extract($this->post(['post_content' => '<!-- wp:acf {"data":{"secret":"hidden-json"}} --><p>Visible [gallery ids="1"] text</p><!-- /wp:acf -->']));

            self::assertSame('Visible text', $row['content']);
        }

        public function test_extract_reads_the_configured_fields_with_their_weights(): void
        {
            $GLOBALS['uams_test_meta'][42] = ['physician_first_name' => 'Charles', 'physician_last_name' => 'Barnes'];
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['provider' => ['physician_first_name' => 3, 'physician_last_name' => -2]]];
            $row = Extractor::extract($this->post(['post_type' => 'provider']));

            self::assertSame('Charles Barnes', $row['fields']);
            $weighted = json_decode($row['weighted_data'], true);
            self::assertEquals([3.0, 0.0], array_column($weighted, 'weight'));
            self::assertSame(['fields', 'fields'], array_column($weighted, 'source'));
            self::assertArrayNotHasKey('text', $weighted[0]);
            self::assertSame('charl', substr($weighted[0]['stem'], 0, 5));
        }

        public function test_a_filter_can_replace_the_field_list_and_a_field_value(): void
        {
            $GLOBALS['uams_test_meta'][42] = ['custom_field' => 'raw value', 'physician_first_name' => 'ignored'];
            $GLOBALS['uams_test_filters']['uams_search_fields'][] = static fn (array $fields, string $type): array => ['custom_field' => 2];
            $GLOBALS['uams_test_filters']['uams_search_field_value'][] = static fn ($value, string $name, WP_Post $post): string => strtoupper($value).' '.$name.' '.$post->ID;
            $row = Extractor::extract($this->post(['post_type' => 'provider']));

            self::assertSame('RAW VALUE custom_field 42', $row['fields']);
        }

        public function test_a_configured_acf_select_indexes_its_label_and_the_filter_receives_the_label(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['location' => ['location_state' => 2]]];
            $GLOBALS['uams_test_meta'][42] = ['location_state' => 'AR', '_location_state' => 'field_state'];
            $GLOBALS['uams_test_acf_fields']['field_state'] = ['type' => 'select', 'choices' => ['AR' => 'Arkansas']];
            $seen = null;
            $GLOBALS['uams_test_filters']['uams_search_field_value'][] = static function ($value, string $name) use (&$seen) {
                $seen = [$value, $name];

                return $value;
            };
            $row = Extractor::extract($this->post(['post_type' => 'location']));

            self::assertSame('Arkansas', $row['fields']);
            self::assertSame([['Arkansas'], 'location_state'], $seen);
        }

        public function test_a_configured_field_acf_does_not_know_reaches_the_filter_unchanged(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['location' => ['location_code' => 1]]];
            $GLOBALS['uams_test_meta'][42] = ['location_code' => 'AR-12', '_location_code' => 'field_gone'];
            $seen = null;
            $GLOBALS['uams_test_filters']['uams_search_field_value'][] = static function ($value) use (&$seen) {
                $seen = $value;

                return $value;
            };
            $row = Extractor::extract($this->post(['post_type' => 'location']));

            self::assertSame('AR-12', $seen);
            self::assertSame('AR-12', $row['fields']);
        }

        public function test_a_skipped_acf_type_adds_no_digits_to_the_fields(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['page_hero' => 1, 'page_note' => 1]]];
            $GLOBALS['uams_test_meta'][42] = ['page_hero' => '3', '_page_hero' => 'field_hero', 'page_note' => 'Open late'];
            $GLOBALS['uams_test_acf_fields']['field_hero'] = ['type' => 'repeater'];
            $row = Extractor::extract($this->post());

            self::assertSame('Open late', trim($row['fields']));
        }

        public function test_a_block_field_uses_the_key_saved_beside_it(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['hero' => 1]]];
            $GLOBALS['uams_test_acf_fields']['field_style'] = ['type' => 'select', 'choices' => ['dark' => 'Dark Blue']];
            $GLOBALS['uams_test_acf_fields']['field_rows'] = ['type' => 'repeater'];
            $GLOBALS['uams_test_blocks'] = [
                ['blockName' => 'acf/hero', 'attrs' => ['data' => ['hero' => '2', '_hero' => 'field_rows', 'hero_style' => 'dark', '_hero_style' => 'field_style', 'hero_0_caption' => 'Spine']], 'innerBlocks' => [], 'innerHTML' => ''],
            ];
            $row = Extractor::extract($this->post());

            self::assertStringContainsString('Dark Blue Spine', $row['fields']);
            self::assertStringNotContainsString('dark ', $row['fields']);
            self::assertSame(0, preg_match('/\d/', $row['fields']));
        }

        public function test_extract_adds_the_related_provider_dependency_for_a_location(): void
        {
            $GLOBALS['uams_test_meta'][42]['physician_locations'] = [77];
            $GLOBALS['uams_test_posts'][77] = $this->post(['ID' => 77, 'post_type' => 'provider', 'post_title' => 'Barnes']);
            $row = Extractor::extract($this->post(['post_type' => 'location']));

            $weighted = json_decode($row['weighted_data'], true);
            self::assertContains(77, array_column($weighted, 'provider_id'));
            self::assertStringContainsString('Barnes', $row['fields']);
        }

        public function test_extract_takes_allowed_field_values_from_block_attributes(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['hero' => 4]]];
            $GLOBALS['uams_test_blocks'] = [
                ['blockName' => 'acf/hero', 'attrs' => ['data' => ['hero' => 'Welcome', 'hero_0_caption' => 'Spine', '_hero' => 'field_abc', 'other' => 'No', 'heroic' => 'Wrong']], 'innerBlocks' => [], 'innerHTML' => ''],
                ['blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [['blockName' => 'acf/hero', 'attrs' => ['data' => ['hero' => 'Nested']], 'innerBlocks' => [], 'innerHTML' => '']], 'innerHTML' => ''],
            ];
            $row = Extractor::extract($this->post());

            $weighted = json_decode($row['weighted_data'], true);
            self::assertCount(2, $weighted);
            self::assertEquals(4.0, $weighted[1]['weight']);
            self::assertSame(1, preg_match('/welcom/', $weighted[1]['stem']));
            self::assertSame(1, preg_match('/nest/', $weighted[1]['stem']));
            self::assertStringContainsString('Welcome Spine Nested', $row['fields']);
            self::assertStringNotContainsString('field_abc', $row['fields']);
            self::assertStringNotContainsString('No', $row['fields']);
            self::assertStringNotContainsString('Wrong', $row['fields']);
        }

        public function test_extract_ignores_blocks_nested_deeper_than_twenty_levels(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['hero' => 1]]];
            $block = ['blockName' => 'acf/hero', 'attrs' => ['data' => ['hero' => 'Deepest']], 'innerBlocks' => [], 'innerHTML' => ''];
            for ($i = 0; $i < 21; $i++) {
                $block = ['blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [$block], 'innerHTML' => ''];
            }

            $GLOBALS['uams_test_blocks'] = [$block];
            $row = Extractor::extract($this->post());

            self::assertStringNotContainsString('Deepest', $row['fields']);
        }

        public function test_extract_reads_terms_for_each_configured_taxonomy_that_exists(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['taxonomies' => ['page' => ['category' => 2, 'ghost' => 1]]];
            $GLOBALS['uams_test_terms'] = ['category' => ['Orthopaedics', 'Sports']];
            $row = Extractor::extract($this->post());

            self::assertSame('Orthopaedics Sports', $row['terms']);
            $weighted = json_decode($row['weighted_data'], true);
            self::assertSame(['terms'], array_column($weighted, 'source'));
            self::assertEquals([2.0], array_column($weighted, 'weight'));
        }

        public function test_a_negative_term_weight_is_stored_as_zero(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['taxonomies' => ['page' => ['category' => -3]]];
            $GLOBALS['uams_test_terms'] = ['category' => ['Sports']];
            $row = Extractor::extract($this->post());

            self::assertEquals([0.0], array_column(json_decode($row['weighted_data'], true), 'weight'));
        }

        public function test_an_object_twelve_levels_down_is_still_reported_as_an_unsupported_value(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['gallery' => 1]]];
            $value = new stdClass;
            for ($i = 0; $i < 12; $i++) {
                $value = [$value];
            }

            $GLOBALS['uams_test_meta'][42]['gallery'] = $value;
            $log = $this->captureLog(fn (): array => Extractor::extract($this->post()));

            self::assertStringContainsString('type=stdClass', $log);
            self::assertStringNotContainsString('deeply nested', $log);
        }

        public function test_extract_throws_when_a_term_lookup_fails(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['taxonomies' => ['page' => ['category' => 1]]];
            $GLOBALS['uams_test_terms'] = ['category' => new WP_Error('invalid_taxonomy', 'Invalid taxonomy.')];

            $this->expectExceptionObject(new RuntimeException('Invalid taxonomy.'));
            Extractor::extract($this->post());
        }

        public function test_extract_builds_one_stem_column_per_text_column_and_a_combined_search_column(): void
        {
            $row = Extractor::extract($this->post(['post_title' => 'Knee clinic', 'post_content' => '<p>Spine care</p>']));

            self::assertSame('knee clinic', $row['title_stem']);
            self::assertSame('spine care', $row['content_stem']);
            self::assertSame('', $row['fields_stem']);
            self::assertSame('knee clinic uamsboundaryzz spine care uamsboundaryzz  uamsboundaryzz  uamsboundaryzz ', $row['search_stem']);
        }

        public function test_extract_collects_tokens_too_short_to_stem_into_the_short_token_column(): void
        {
            $row = Extractor::extract($this->post(['post_title' => 'Ox clinic']));

            $token = 'uamsshort'.bin2hex('ox').'zz';
            self::assertSame($token.' clinic', $row['title_stem']);
            self::assertSame($token, $row['short_tokens']);
        }

        // --- field logging -----------------------------------------------------

        private function captureLog(callable $run): string
        {
            $file = tempnam(sys_get_temp_dir(), 'uams');
            $previous = ini_set('error_log', $file);
            try {
                $run();
            } finally {
                ini_set('error_log', (string) $previous);
            }

            $log = (string) file_get_contents($file);
            unlink($file);

            return $log;
        }

        public function test_an_object_field_value_is_logged_as_skipped_with_its_type(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['gallery' => 1]]];
            $GLOBALS['uams_test_meta'][42]['gallery'] = new ArrayObject;
            $log = $this->captureLog(fn (): array => Extractor::extract($this->post()));

            self::assertStringContainsString('UAMS Search: skipped unsupported field value site=1 post=42 field=gallery type=ArrayObject', $log);
        }

        public function test_objects_inside_an_array_field_are_logged_too(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['gallery' => 1]]];
            $GLOBALS['uams_test_meta'][42]['gallery'] = ['a', ['b', new stdClass]];
            $log = $this->captureLog(fn (): array => Extractor::extract($this->post()));

            self::assertStringContainsString('type=stdClass', $log);
        }

        public function test_plain_field_values_are_not_logged(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['gallery' => 1]]];
            $GLOBALS['uams_test_meta'][42]['gallery'] = ['a', 7, 1.5, null, true];
            $log = $this->captureLog(fn (): array => Extractor::extract($this->post()));

            self::assertSame('', $log);
        }

        public function test_a_field_nested_more_than_twelve_levels_is_logged_as_deeply_nested(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['gallery' => 1]]];
            $value = 'leaf';
            for ($i = 0; $i < 13; $i++) {
                $value = [$value];
            }

            $GLOBALS['uams_test_meta'][42]['gallery'] = $value;
            $log = $this->captureLog(fn (): array => Extractor::extract($this->post()));

            self::assertStringContainsString('UAMS Search: skipped deeply nested field site=1 post=42 field=gallery', $log);
        }

        // --- pins added for the full coverage bar (#189) ----------------------

        public function test_every_post_meta_read_asks_for_a_single_value(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['note' => 1]]];
            $GLOBALS['uams_test_meta_record'] = true;
            Extractor::eligible($this->post(['post_type' => 'provider']));
            Extractor::eligible($this->post(['post_type' => 'location']));
            Extractor::extract($this->post());

            $reads = $GLOBALS['uams_test_meta_reads'];
            $keys = array_column($reads, 0);
            foreach (['_uams_search_exclude', 'physician_hidden', 'physician_searchable', 'location_hidden', '_note'] as $key) {
                self::assertContains($key, $keys);
            }

            self::assertSame([true], array_values(array_unique(array_column($reads, 1))));
        }

        public function test_a_field_is_read_through_acf_unformatted_when_acf_is_active(): void
        {
            if (! function_exists('get_field')) {
                self::markTestSkipped('`CardFieldsTest` defines the get_field stand-in; without it the field is read from post meta.');
            }

            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['note' => 1]]];
            $GLOBALS['uams_test_acf'] = true;
            $seen = null;
            $GLOBALS['uams_test_filters']['uams_search_field_value'][] = static function ($value) use (&$seen): string {
                $seen = $value;

                return '';
            };
            Extractor::extract($this->post());

            self::assertSame(['note', 42, false], $seen);
        }

        public function test_extract_returns_exactly_this_row_for_a_plain_page(): void
        {
            $row = Extractor::extract($this->post());

            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['indexed_at']);
            unset($row['indexed_at']);
            self::assertSame([
                'site_id' => 1,
                'object_id' => 42,
                'object_type' => 'post',
                'post_type' => 'page',
                'title' => 'Orthopaedics',
                'content' => 'Knee care',
                'fields' => '',
                'terms' => '',
                'pdf' => '',
                'url' => 'https://example.test/?p=42',
                'published_at' => '2026-01-02 03:04:05',
                'modified_at' => '2026-02-03 04:05:06',
                'status' => 'ready',
                'error' => '',
                'file_signature' => '',
                'file_size' => 0,
                'weighted_data' => '[]',
                'title_stem' => 'orthopaed',
                'content_stem' => 'knee care',
                'fields_stem' => '',
                'terms_stem' => '',
                'pdf_stem' => '',
                'search_stem' => 'orthopaed uamsboundaryzz knee care uamsboundaryzz  uamsboundaryzz  uamsboundaryzz ',
                'short_tokens' => '',
            ], $row);
        }

        public function test_the_weighted_data_is_a_list_whether_it_holds_fields_terms_or_both(): void
        {
            $GLOBALS['uams_test_terms'] = ['category' => ['Sports']];
            $GLOBALS['uams_test_meta'][42] = ['note' => 'Knee'];

            $GLOBALS['uams_test_options']['uams_search_settings'] = ['taxonomies' => ['page' => ['category' => 2]]];
            self::assertStringStartsWith('[{', Extractor::extract($this->post())['weighted_data']);

            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['note' => 1]], 'taxonomies' => ['page' => ['category' => 2]]];
            $weighted = Extractor::extract($this->post())['weighted_data'];
            self::assertStringStartsWith('[{', $weighted);
            self::assertSame(['fields', 'terms'], array_column(json_decode($weighted, true), 'source'));
        }

        public function test_weights_are_stored_as_floats_and_never_below_zero(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['hero' => '3', 'dim' => '0.5', 'neg' => '-2']], 'taxonomies' => ['page' => ['category' => '2']]];
            $GLOBALS['uams_test_terms'] = ['category' => ['Sports']];
            $GLOBALS['uams_test_blocks'] = [
                ['blockName' => 'acf/hero', 'attrs' => ['data' => ['hero' => 'A', 'dim' => 'B', 'neg' => 'C']], 'innerBlocks' => [], 'innerHTML' => ''],
            ];
            $weighted = json_decode(Extractor::extract($this->post())['weighted_data'], true);

            // Saved text becomes a number (JSON drops the fraction of a whole one), a weight under zero is zero, and the order is the three fields, the three block entries, then the term
            self::assertSame([3, 0.5, 0, 3, 0.5, 0, 2], array_column($weighted, 'weight'));
            self::assertSame(['fields', 'fields', 'fields', 'fields', 'fields', 'fields', 'terms'], array_column($weighted, 'source'));
        }

        public function test_a_block_field_text_starts_with_one_space_before_its_values(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['hero' => 1]]];
            $GLOBALS['uams_test_blocks'] = [
                ['blockName' => 'acf/hero', 'attrs' => ['data' => ['hero' => 'Welcome', 'hero_0_caption' => 'Spine']], 'innerBlocks' => [], 'innerHTML' => ''],
            ];

            // The field itself holds no text, so its empty text comes first
            self::assertSame('  Welcome Spine', Extractor::extract($this->post())['fields']);
        }

        public function test_blocks_nested_up_to_twenty_levels_are_read_and_no_deeper(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['hero' => 1]]];
            $found = function (int $levels): bool {
                $block = ['blockName' => 'acf/hero', 'attrs' => ['data' => ['hero' => 'Deepest']], 'innerBlocks' => [], 'innerHTML' => ''];
                for ($i = 0; $i < $levels; $i++) {
                    $block = ['blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [$block], 'innerHTML' => ''];
                }

                $GLOBALS['uams_test_blocks'] = [$block];

                return str_contains(Extractor::extract($this->post())['fields'], 'Deepest');
            };

            self::assertTrue($found(0));
            self::assertTrue($found(15));
            self::assertTrue($found(20));
            self::assertFalse($found(21));
        }

        public function test_a_taxonomy_that_does_not_exist_does_not_end_the_list_and_terms_are_asked_for_by_name(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['taxonomies' => ['page' => ['ghost' => 1, 'category' => 2]]];
            $GLOBALS['uams_test_terms'] = ['category' => ['Sports']];

            self::assertSame('Sports', Extractor::extract($this->post())['terms']);
            self::assertSame([['category', ['fields' => 'names']]], $GLOBALS['uams_test_term_args']);
        }

        // --- attachments ------------------------------------------------------

        /** @return array<string, mixed> The earlier row of the fixture PDF, as the cache of an unchanged file reads it. */
        private function cachedPdfRow(): array
        {
            $path = realpath(get_attached_file(42));

            return ['file_signature' => hash('sha256', $path.'|'.filesize($path).'|'.filemtime($path)), 'status' => 'ready', 'pdf' => 'cached text', 'error' => ''];
        }

        public function test_an_attachment_row_carries_the_pdf_text_signature_and_file_name(): void
        {
            $old = $this->cachedPdfRow();
            $row = Extractor::extract($this->pdf(['post_title' => 'Flyer']), $old);

            self::assertSame('attachment', $row['object_type']);
            self::assertSame('attachment', $row['post_type']);
            self::assertSame('Flyer', $row['title']);
            self::assertSame('cached text', $row['pdf']);
            self::assertSame('cach text', $row['pdf_stem']);
            self::assertSame($old['file_signature'], $row['file_signature']);
            self::assertSame(filesize((string) realpath(get_attached_file(42))), $row['file_size']);
            self::assertSame('ready', $row['status']);
            self::assertSame('', $row['error']);
            // With no fields configured, the file name stands alone
            self::assertSame('text', $row['fields']);
            self::assertSame('https://example.test/files/42.pdf', $row['url']);
        }

        public function test_an_attachment_puts_its_configured_fields_before_its_file_name(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['attachment' => ['caption' => 1]]];
            $GLOBALS['uams_test_meta'][42] = ['caption' => 'Spring flyer'];
            $row = Extractor::extract($this->pdf(), $this->cachedPdfRow());

            self::assertSame('Spring flyer text', $row['fields']);
            self::assertSame('spring flyer text', $row['fields_stem']);
        }

        public function test_an_attachment_links_to_its_parent_only_when_the_setting_asks_for_it_and_it_has_one(): void
        {
            $old = $this->cachedPdfRow();

            $GLOBALS['uams_test_options']['uams_search_settings'] = ['pdf_parent' => true];
            self::assertSame('https://example.test/?p=7', Extractor::extract($this->pdf(['post_parent' => 7]), $old)['url']);
            self::assertSame('https://example.test/files/42.pdf', Extractor::extract($this->pdf(['post_parent' => 0]), $old)['url']);

            $GLOBALS['uams_test_options']['uams_search_settings'] = ['pdf_parent' => false];
            self::assertSame('https://example.test/files/42.pdf', Extractor::extract($this->pdf(['post_parent' => 7]), $old)['url']);
        }
    }
}

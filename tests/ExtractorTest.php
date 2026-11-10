<?php

use PHPUnit\Framework\TestCase;
use UAMS_Search\Extractor;

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(string $code = '', private string $message = '', mixed $data = '') {}

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
        return $GLOBALS['uams_test_terms'][$taxonomy] ?? [];
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error($value): bool
    {
        return $value instanceof WP_Error;
    }
}

if (! function_exists('get_permalink')) {
    function get_permalink($post)
    {
        return 'https://example.test/?p='.$post->ID;
    }
}

/** Pins for what the indexer reads from a post (#5). WordPress functions are stood in; attachments (the PDF worker) are not covered here. */
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
        unset($GLOBALS['uams_test_blocks']);
    }

    protected function tearDown(): void
    {
        $GLOBALS['uams_test_options'] = [];
        $GLOBALS['uams_test_filters'] = [];
        $GLOBALS['uams_test_meta'] = [];
        $GLOBALS['uams_test_posts'] = [];
        $GLOBALS['uams_test_terms'] = [];
        unset($GLOBALS['uams_test_blocks']);
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
}

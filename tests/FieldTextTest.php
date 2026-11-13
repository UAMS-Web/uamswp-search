<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UAMS_Search\FieldText;

// Stand-ins for the ACF definitions and the post and term reads FieldText makes. Each answers from a global the test sets.
if (! function_exists('acf_get_field')) {
    function acf_get_field($selector)
    {
        $GLOBALS['uams_test_acf_lookups'][] = $selector;

        return $GLOBALS['uams_test_acf_fields'][$selector] ?? false;
    }
}

if (! function_exists('get_post_status')) {
    function get_post_status($id)
    {
        return $GLOBALS['uams_test_post_status'][$id] ?? false;
    }
}

if (! function_exists('get_post_field')) {
    function get_post_field($field, $id)
    {
        return $GLOBALS['uams_test_post_titles'][$id] ?? '';
    }
}

if (! function_exists('get_term_field')) {
    function get_term_field($field, $id, $taxonomy = '')
    {
        $GLOBALS['uams_test_term_lookups'][] = [$field, $id, $taxonomy];

        // WordPress answers a missing term with a WP_Error; ExtractorTest declares the class
        return $GLOBALS['uams_test_term_names'][$id] ?? (class_exists('WP_Error') ? new WP_Error('invalid_term', 'Empty Term.') : null);
    }
}

if (! function_exists('is_post_status_viewable')) {
    function is_post_status_viewable($status): bool
    {
        return in_array($status, $GLOBALS['uams_test_viewable_statuses'] ?? ['publish'], true);
    }
}

/** Pins for how a stored ACF value becomes searchable text, by field type (#159). */
final class FieldTextTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['uams_test_acf_fields'] = [];
        $GLOBALS['uams_test_acf_lookups'] = [];
        $GLOBALS['uams_test_post_status'] = [];
        $GLOBALS['uams_test_post_titles'] = [];
        $GLOBALS['uams_test_term_names'] = [];
        $GLOBALS['uams_test_term_lookups'] = [];
        $GLOBALS['uams_test_viewable_statuses'] = ['publish'];
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    /** @param array<string, mixed> $definition */
    private function field(string $type, array $definition = []): void
    {
        $GLOBALS['uams_test_acf_fields']['field_1'] = ['type' => $type] + $definition;
    }

    /** @return array<string, array{0: string}> */
    public static function choice_types(): array
    {
        return ['select' => ['select'], 'radio' => ['radio'], 'button group' => ['button_group'], 'checkbox' => ['checkbox']];
    }

    #[DataProvider('choice_types')]
    public function test_a_choice_field_indexes_its_labels_not_its_keys(string $type): void
    {
        $this->field($type, ['choices' => ['AR' => 'Arkansas', 'TX' => 'Texas', 2 => 'Two']]);

        self::assertSame(['Arkansas'], FieldText::readable('AR', 'field_1', 'location_state'));
        self::assertSame(['Arkansas', 'Texas', 'Two'], FieldText::readable(['AR', 'TX', '2'], 'field_1', 'location_state'));
    }

    public function test_a_choice_key_with_no_label_keeps_the_key_and_empty_items_are_dropped(): void
    {
        $this->field('select', ['choices' => ['AR' => 'Arkansas']]);

        self::assertSame(['Arkansas', 'gone'], FieldText::readable(['AR', '', null, 'gone'], 'field_1', 'location_state'));
        self::assertSame([], FieldText::readable('', 'field_1', 'location_state'));
    }

    /** @return array<string, array{0: string}> */
    public static function post_types(): array
    {
        return ['relationship' => ['relationship'], 'post object' => ['post_object']];
    }

    #[DataProvider('post_types')]
    public function test_a_post_field_indexes_the_titles_of_viewable_posts(string $type): void
    {
        $this->field($type);
        $GLOBALS['uams_test_post_status'] = [12 => 'publish', 13 => 'draft', 14 => 'publish'];
        $GLOBALS['uams_test_post_titles'] = [12 => 'Heart Clinic', 13 => 'Unpublished Clinic', 14 => 'Eye Clinic'];

        self::assertSame(['Heart Clinic', 'Eye Clinic'], FieldText::readable(['12', 13, '14', 'not-an-id', '99'], 'field_1', 'physician_locations'));
        self::assertSame(['Heart Clinic'], FieldText::readable('12', 'field_1', 'physician_locations'));
    }

    /** @return array<string, array{0: string}> */
    public static function term_types(): array
    {
        return ['taxonomy' => ['taxonomy'], 'ACFE taxonomy terms' => ['acfe_taxonomy_terms']];
    }

    #[DataProvider('term_types')]
    public function test_a_term_field_indexes_the_term_names(string $type): void
    {
        $this->field($type, ['taxonomy' => 'region']);
        $GLOBALS['uams_test_term_names'] = [7 => 'Central Arkansas', 8 => 'Northwest Arkansas'];

        self::assertSame(['Central Arkansas', 'Northwest Arkansas'], FieldText::readable([7, '8', '404', 'slug'], 'field_1', 'location_region'));
        self::assertSame([['name', 7, 'region'], ['name', 8, 'region'], ['name', 404, 'region']], $GLOBALS['uams_test_term_lookups']);
    }

    public function test_a_term_field_without_one_taxonomy_reads_the_term_by_id_alone(): void
    {
        $this->field('acfe_taxonomy_terms', ['taxonomy' => ['region', 'portal']]);
        $GLOBALS['uams_test_term_names'] = [7 => 'Central Arkansas'];

        self::assertSame(['Central Arkansas'], FieldText::readable('7', 'field_1', 'location_region'));
        self::assertSame([['name', 7, '']], $GLOBALS['uams_test_term_lookups']);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function skipped_types(): array
    {
        return [
            'image' => ['image', '345'],
            'file' => ['file', '346'],
            'gallery' => ['gallery', ['345', '346']],
            'true/false' => ['true_false', '1'],
            'repeater row count' => ['repeater', '3'],
            'group with no rows' => ['group', ''],
            'link' => ['link', ['title' => 'Book', 'url' => 'https://example.test/', 'target' => '']],
            'Google Map' => ['google_map', ['address' => '4301 W Markham St', 'lat' => '34.75', 'lng' => '-92.32', 'zoom' => 14]],
        ];
    }

    #[DataProvider('skipped_types')]
    public function test_a_skipped_type_adds_nothing(string $type, mixed $value): void
    {
        $this->field($type);

        self::assertSame('', FieldText::readable($value, 'field_1', 'some_field'));
    }

    public function test_a_flexible_content_layout_list_adds_no_text(): void
    {
        $this->field('flexible_content');

        self::assertSame([], FieldText::readable(['hero', 'content'], 'field_1', 'uamswp_modules'));
    }

    public function test_loaded_repeater_rows_index_each_sub_field_by_its_own_type(): void
    {
        $this->field('repeater');
        $GLOBALS['uams_test_acf_fields']['field_school'] = ['type' => 'text'];
        $GLOBALS['uams_test_acf_fields']['field_kind'] = ['type' => 'select', 'choices' => ['md' => 'Medical School']];
        $GLOBALS['uams_test_acf_fields']['field_logo'] = ['type' => 'image'];
        $rows = [
            ['field_school' => 'Harvard Medical School', 'field_kind' => 'md', 'field_logo' => '345'],
            ['field_school' => 'UAMS', 'field_kind' => 'md', 'field_logo' => '346'],
        ];

        self::assertSame([[['Harvard Medical School', ['Medical School'], ''], ['UAMS', ['Medical School'], '']]], [FieldText::readable($rows, 'field_1', 'physician_education')]);
    }

    public function test_loaded_flexible_content_rows_drop_the_layout_name_and_keep_sub_fields(): void
    {
        $this->field('flexible_content');
        $GLOBALS['uams_test_acf_fields']['field_title'] = ['type' => 'text'];

        self::assertSame([['Welcome']], FieldText::readable([['acf_fc_layout' => 'hero', 'field_title' => 'Welcome']], 'field_1', 'uamswp_modules'));
    }

    public function test_a_loaded_group_indexes_its_sub_fields_and_a_sub_field_ac_f_does_not_know_keeps_its_value(): void
    {
        $this->field('group');
        $GLOBALS['uams_test_acf_fields']['field_state'] = ['type' => 'radio', 'choices' => ['AR' => 'Arkansas']];

        self::assertSame([['Arkansas'], 'kept'], FieldText::readable(['field_state' => 'AR', 'field_gone' => 'kept', 'plain_key' => 'dropped'], 'field_1', 'physician_resident_profile_group'));
        // A sub-field key that ACF does not know is not looked up by an empty name
        self::assertNotContains('', $GLOBALS['uams_test_acf_lookups']);
    }

    public function test_sub_fields_are_followed_no_deeper_than_twelve_levels(): void
    {
        $GLOBALS['uams_test_acf_fields']['field_nest'] = ['type' => 'group'];
        $value = 'deep';
        for ($i = 0; $i < 14; $i++) {
            $value = ['field_nest' => $value];
        }

        self::assertStringNotContainsString('deep', json_encode(FieldText::readable($value, 'field_nest', 'outer')));
        self::assertLessThanOrEqual(14, count($GLOBALS['uams_test_acf_lookups']));
    }

    public function test_choices_inside_option_groups_index_their_labels(): void
    {
        $this->field('select', ['choices' => ['South' => ['AR' => 'Arkansas', 'TX' => 'Texas'], 'OK' => 'Oklahoma']]);

        self::assertSame(['Arkansas', 'Oklahoma'], FieldText::readable(['AR', 'OK'], 'field_1', 'location_state'));
    }

    public function test_a_date_picker_value_indexes_as_a_readable_date(): void
    {
        $this->field('date_picker');

        self::assertSame('March 5, 2026', FieldText::readable('20260305', 'field_1', 'location_reopen_date'));
        self::assertSame('March 5, 2026', FieldText::readable(20260305, 'field_1', 'location_reopen_date'));
    }

    public function test_a_date_picker_value_that_is_not_a_valid_date_adds_nothing(): void
    {
        $this->field('date_picker');

        foreach (['', '2026-03-05', '20261305', '20260230', 'soon', '202603051'] as $value) {
            self::assertSame('', FieldText::readable($value, 'field_1', 'location_reopen_date'), $value);
        }
    }

    public function test_a_type_not_handled_here_keeps_its_value(): void
    {
        $this->field('text');

        self::assertSame('Walk-ins welcome', FieldText::readable('Walk-ins welcome', 'field_1', 'location_note'));
        self::assertSame(['a', 'b'], FieldText::readable(['a', 'b'], 'field_1', 'location_note'));
    }

    public function test_a_field_acf_does_not_know_keeps_its_value_exactly(): void
    {
        $value = ['AR', 12, ['nested' => true]];

        self::assertSame($value, FieldText::readable($value, '', 'unknown_field'));
        self::assertSame($value, FieldText::readable($value, 'field_missing', 'unknown_field'));
        self::assertSame(['unknown_field', 'field_missing', 'unknown_field'], $GLOBALS['uams_test_acf_lookups']);
    }

    public function test_a_definition_without_a_type_counts_as_unknown(): void
    {
        $GLOBALS['uams_test_acf_fields']['field_1'] = ['choices' => ['AR' => 'Arkansas']];

        self::assertSame('AR', FieldText::readable('AR', 'field_1', 'location_state'));
    }

    public function test_the_saved_key_is_preferred_and_the_name_is_the_fallback(): void
    {
        $GLOBALS['uams_test_acf_fields']['field_child'] = ['type' => 'select', 'choices' => ['mon' => 'Monday']];
        $GLOBALS['uams_test_acf_fields']['location_hours_0_day'] = ['type' => 'text'];
        $GLOBALS['uams_test_acf_fields']['location_state'] = ['type' => 'select', 'choices' => ['AR' => 'Arkansas']];

        // A sub-field's saved name matches nothing useful, so its key decides
        self::assertSame(['Monday'], FieldText::readable('mon', 'field_child', 'location_hours_0_day'));
        // Without a key, the name is looked up; a value that is not a field key is not used as one
        self::assertSame(['Arkansas'], FieldText::readable('AR', '', 'location_state'));
        self::assertSame(['Arkansas'], FieldText::readable('AR', 'not-a-key', 'location_state'));
        self::assertSame(['field_child', 'location_state', 'location_state'], $GLOBALS['uams_test_acf_lookups']);
    }
}

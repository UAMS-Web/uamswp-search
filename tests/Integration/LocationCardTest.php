<?php

/**
 * Location cards built from live post data, with parent inheritance, phones, notices and escaping.
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use DOMDocument;
use DOMXPath;
use ReflectionMethod;
use RuntimeException;
use UAMS_Search\DB;
use UAMS_Search\Integration;
use UAMS_Search\LocationCard;
use UAMS_Search\Result;
use UAMS_Search\Tests\Support\IntegrationTestCase;
use WP_Query;

use function array_column;
use function count;
use function str_contains;
use function str_ends_with;
use function wp_json_encode;

/**
 * Port of the former location-cards.php script.
 */
final class LocationCardTest extends IntegrationTestCase
{
    private int $parent = 0;

    private int $child = 0;

    private int $page = 0;

    private int $remote = 0;

    /** @var list<int> Terms created by a test, deleted afterwards. */
    private array $terms = [];

    private mixed $old_query = null;

    private mixed $old_post = null;

    private mixed $old_timezone = false;

    public function set_up(): void
    {
        parent::set_up();
        $this->old_query = $GLOBALS['wp_query'];
        $this->old_post = $GLOBALS['post'] ?? null;
        $this->old_timezone = get_option('timezone_string');
        foreach (['location', 'provider'] as $type) {
            if (! post_type_exists($type)) {
                register_post_type($type, ['public' => true]);
            }
        }

        if (! taxonomy_exists('building')) {
            register_taxonomy('building', 'location', ['public' => true]);
        }

        $this->settings();
        update_option('timezone_string', 'America/Chicago');
        $this->parent = $this->make_page(['post_type' => 'location', 'post_title' => 'Parent & "Campus"']);
        $this->child = $this->make_page(['post_type' => 'location', 'post_title' => 'Child Clinic']);
        $this->page = $this->make_page(['post_title' => 'Ordinary page']);
        $GLOBALS['post'] = get_post($this->page);
        $this->set($this->parent, ['location_address_1' => '100 Main Street', 'location_address_2' => 'Campus Building<br>Suite 200', 'location_city' => 'Little Rock', 'location_state' => 'AR', 'location_zip' => '72205', 'location_map' => ['lat' => 34.74, 'lng' => -92.32], 'location_prepend_the' => '1', 'location_phone' => '5011111111']);
        $this->set($this->child, ['location_parent' => '1', 'location_parent_id' => $this->parent, 'location_address_1' => 'Child address', 'location_city' => 'Child city', 'location_phone' => '(501) 526-4020', 'location_appointments_query' => '1']);
        $this->image($this->parent, 'parent-photo-'.$this->token);
        $this->image($this->child, 'child-photo-'.$this->token);
    }

    public function tear_down(): void
    {
        global $wpdb;
        foreach ($this->terms as $term) {
            wp_delete_term($term, 'building');
        }

        $this->terms = [];
        $GLOBALS['wp_query'] = $this->old_query;
        $GLOBALS['post'] = $this->old_post;
        update_option('timezone_string', $this->old_timezone);
        if ($this->remote > 0) {
            wpmu_delete_blog($this->remote, true);
            foreach (['index', 'stage', 'queue'] as $suffix) {
                $wpdb->delete(DB::table($suffix), ['site_id' => $this->remote]);
            }

            $this->remote = 0;
        }

        parent::tear_down();
    }

    public function test_child_cards_inherit_from_the_parent(): void
    {
        $caller = $GLOBALS['post'];
        $card = LocationCard::data($this->snapshot($this->child));
        $this->assertTrue($card['name'] === 'Child Clinic' && $card['url'] === get_permalink($this->child), 'Location cards use the live child title and permalink');
        $this->assertTrue($card['parent']['name'] === 'Parent & "Campus"' && $card['parent']['prefix'] === 'Part of the ', 'Parent links honor the prepend-the setting');
        $this->assertTrue($card['address'] === ['100 Main Street', 'Campus Building', 'Suite 200', 'Little Rock, AR 72205'], 'Child cards inherit the full parent address and preserve legacy line breaks');
        $this->assertTrue(str_contains($card['image'], 'parent-photo-'.$this->token), 'Child cards inherit the parent photo by default');
        $this->assertTrue($card['phones']['numbers'][0]['text'] === '501-526-4020' && ! str_contains((string) wp_json_encode($card['phones']), '501-111-1111'), 'Phone numbers belong to the child, not the inherited address location');
        $this->assertTrue(str_contains(rawurldecode($card['directions']), '34.74,-92.32'), 'Directions use the inherited location coordinates');
        $this->assertTrue($GLOBALS['post'] === $caller && $GLOBALS['wp_query'] === $this->old_query && get_current_blog_id() === $this->site, 'Local card construction preserves caller post, query, and site');
        $this->set($this->child, ['location_image_override_parent' => '1', 'location_image_override_parent_featured' => '1']);
        $this->assertTrue(str_contains(LocationCard::data($this->snapshot($this->child))['image'], 'child-photo-'.$this->token), 'Both override flags select the child photo');
        update_post_meta($this->child, 'location_image_override_parent_featured', '0');
        $this->assertTrue(str_contains(LocationCard::data($this->snapshot($this->child))['image'], 'parent-photo-'.$this->token), 'One override flag alone retains the parent photo');
    }

    public function test_phone_numbers_labels_and_dial_links(): void
    {
        $child = $this->child;
        $this->set($child, ['location_clinic_phone_query' => '1', 'location_new_appointments_phone' => '+1 (501) 111-2222', 'location_appointment_phone_query' => '1', 'location_return_appointments_phone' => '5013334444']);
        $phone = LocationCard::data($this->snapshot($child))['phones'];
        $this->assertTrue($phone['heading'] === 'Appointment Phone Numbers' && array_column($phone['numbers'], 'label') === ['New Patients', 'Returning Patients'], 'Separate appointment numbers retain the correct patient labels');
        $this->assertTrue($phone['numbers'][0]['url'] === 'tel:+15011112222' && $phone['numbers'][1]['text'] === '501-333-4444', 'Phone display and dial targets preserve the configured digits');
        update_post_meta($child, 'location_appointment_phone_query', '0');
        $this->assertTrue(LocationCard::data($this->snapshot($child))['phones']['numbers'][0]['label'] === 'New and Returning Patients', 'A single appointment number serves both patient groups');
        update_post_meta($child, 'location_appointments_query', '0');
        $phone = LocationCard::data($this->snapshot($child))['phones'];
        $this->assertTrue($phone['heading'] === 'General Information' && $phone['numbers'][0]['text'] === '501-526-4020', 'A location without appointments shows its general information number');
        $this->set($child, ['location_appointments_query' => '1', 'location_ac_query' => '1', 'location_ac_appointments_query' => '1', 'location_ac_appointments_primary' => '5015550001', 'location_ac_appointments_specialty' => '5015550002']);
        $this->assertTrue(array_column(LocationCard::data($this->snapshot($child))['phones']['numbers'], 'label') === ['Primary Care', 'Specialty Care'], "Children's locations retain primary and specialty care phone labels");
        $this->set($child, ['location_ac_query' => '0', 'location_clinic_phone_query' => '0', 'location_phone' => '501-526-4020 ext. 123']);
        $this->assertTrue(LocationCard::data($this->snapshot($child))['phones']['numbers'][0]['url'] === 'tel:5015264020;ext=123', 'Phone extensions are retained in the dial link');
        update_post_meta($child, 'location_phone', '<script>alert(1)</script>5015551234');
        $this->assertTrue(LocationCard::data($this->snapshot($child))['phones']['numbers'] === [], 'Invalid phone markup is omitted rather than converted into another number');
    }

    public function test_closures_and_modified_hours_notices(): void
    {
        $child = $this->child;
        $today = current_datetime()->setTime(0, 0);
        $temporary = "This location's hours have been temporarily modified.";
        $this->set($child, ['location_closing' => '1', 'location_closing_length' => 'permanent', 'location_closing_date' => $today->modify('-1 day')->format('Ymd')]);
        $this->assertTrue(LocationCard::data($this->snapshot($child))['notice']['text'] === 'This location is permanently closed.', 'Current permanent closures show a notice');
        update_post_meta($child, 'location_closing_date', $today->modify('+2 days')->format('Ymd'));
        $this->assertTrue(str_contains(LocationCard::data($this->snapshot($child))['notice']['text'], $today->modify('+2 days')->format('F j, Y')), 'Future closures use the correct local calendar date');
        $this->set($child, ['location_closing_length' => 'temporary', 'location_closing_date' => $today->modify('-1 day')->format('Ymd'), 'location_reopen_date' => $today->format('Ymd')]);
        $this->assertTrue(LocationCard::data($this->snapshot($child))['notice'] === null, 'A temporary closure ends on its reopening date');
        update_post_meta($child, 'location_reopen_date', '');
        $this->assertTrue(LocationCard::data($this->snapshot($child))['notice']['text'] === 'This location is temporarily closed.', 'A temporary closure without an end date stays visible');
        update_post_meta($child, 'location_closing', '0');
        $hours = ['location_modified_hours' => '1', 'location_modified_hours_start_date' => $today->modify('+3 days')->format('Ymd'), 'location_modified_hours_end' => '0'];
        update_post_meta($child, 'location_hours_group', $hours);
        $this->assertTrue(str_contains(LocationCard::data($this->snapshot($child))['notice']['text'], $today->modify('+3 days')->format('F j, Y')), 'Upcoming modified hours are shown within the next 30 days');
        $hours['location_modified_hours_start_date'] = $today->modify('+31 days')->format('Ymd');
        update_post_meta($child, 'location_hours_group', $hours);
        $this->assertTrue(LocationCard::data($this->snapshot($child))['notice'] === null, 'Modified hours more than 30 days away are not announced early');
        $hours = ['location_modified_hours' => '1', 'location_modified_hours_start_date' => $today->modify('-5 days')->format('Ymd'), 'location_modified_hours_end' => '1', 'location_modified_hours_end_date' => $today->modify('-1 day')->format('Ymd')];
        update_post_meta($child, 'location_hours_group', $hours);
        $this->assertTrue(LocationCard::data($this->snapshot($child))['notice'] === null, 'Expired modified hours are omitted');
        $hours = ['location_modified_hours' => '1', 'location_modified_hours_start_date' => "bad\0date", 'location_modified_hours_end' => '0'];
        update_post_meta($child, 'location_hours_group', $hours);
        $this->assertTrue(LocationCard::data($this->snapshot($child))['notice'] === null, 'Malformed dates cannot break the result page');
        $key_hours = ['location_modified_hours' => '1', 'location_modified_hours_start_date' => $today->format('Ymd'), 'location_modified_hours_end' => '0'];
        $raw = [];
        foreach ($key_hours as $key => $value) {
            $raw['field_'.$key] = $value;
            update_post_meta($child, 'location_hours_group_'.$key, $value);
        }

        update_post_meta($child, 'location_hours_group', $raw);
        $this->assertTrue(LocationCard::data($this->snapshot($child))['notice']['text'] === $temporary, 'Field-key-shaped raw ACF groups resolve through saved prefixed subfields');
        foreach (array_keys($key_hours) as $key) {
            delete_post_meta($child, 'location_hours_group_'.$key);
        }

        $this->assertTrue(LocationCard::data($this->snapshot($child))['notice']['text'] === $temporary, 'Raw field-key groups also work without duplicate prefixed metadata');
        $parsed = (new ReflectionMethod(LocationCard::class, 'date'))->invoke(null, '20261030');
        $this->assertTrue($parsed->format('P H:i:s') === '-05:00 00:00:00', 'Raw dates resolve to midnight in the configured WordPress timezone');
        $hours = ['location_telemed_modified_hours_query' => '1', 'location_telemed_modified_hours_start_date' => $today->format('Ymd'), 'location_telemed_modified_hours_end' => '0'];
        update_post_meta($child, 'location_hours_group', $hours);
        $this->assertTrue(LocationCard::data($this->snapshot($child))['notice']['text'] === $temporary, 'Telemedicine modified hours are included');
    }

    public function test_map_values_and_hidden_or_private_locations(): void
    {
        update_post_meta($this->parent, 'location_map', ['lat' => 0, 'lng' => 0]);
        $this->assertTrue(str_ends_with(LocationCard::data($this->snapshot($this->child))['directions'], '0%2C0'), 'Zero coordinates are valid map values');
        update_post_meta($this->parent, 'location_map', ['lat' => 91, 'lng' => -92]);
        $this->assertTrue(LocationCard::data($this->snapshot($this->child))['directions'] === '', 'Out-of-range map coordinates do not create directions links');
        update_post_meta($this->parent, 'location_hidden', '1');
        $card = LocationCard::data($this->snapshot($this->child));
        $this->assertTrue($card['parent'] === null && $card['address'] === ['Child address', 'Child city'] && ! str_contains($card['image'], 'parent-photo-'.$this->token), 'A hidden parent cannot expose its title, inherited address, or photo');
        delete_post_meta($this->parent, 'location_hidden');
        wp_update_post(['ID' => $this->parent, 'post_status' => 'private']);
        $this->assertTrue(LocationCard::data($this->snapshot($this->child))['parent'] === null, 'A private parent is not exposed');
        wp_update_post(['ID' => $this->parent, 'post_status' => 'publish']);
        update_post_meta($this->child, 'location_hidden', '1');
        $this->assertTrue(LocationCard::data($this->snapshot($this->child)) === null, 'A hidden child location is omitted');
        delete_post_meta($this->child, 'location_hidden');
        $fallback = $this->make_page(['post_type' => 'location', 'post_title' => 'Fallback clinic']);
        $this->assertTrue(LocationCard::data($this->snapshot($fallback))['address'] === [], 'A location with missing optional data still has a usable card');
    }

    public function test_hostile_values_are_escaped(): void
    {
        $this->set($this->child, ['location_closing' => '1', 'location_closing_length' => 'permanent', 'location_closing_date' => current_datetime()->modify('-1 day')->format('Ymd')]);
        $card = LocationCard::data($this->snapshot($this->child));
        $card['name'] = '<script>alert(1)</script> & "Clinic"';
        $card['parent']['name'] = '<img src=x onerror=alert(1)>';
        $card['address'] = ['<script>alert(2)</script>'];
        $card['notice']['text'] = '<img src=x onerror=alert(2)>';
        ob_start();
        LocationCard::render($card);
        $html = (string) ob_get_clean();
        $xp = $this->xpath($html);
        $this->assertTrue($xp->query('//script|//*[@onerror]')->length === 0, 'Titles, parent names, addresses, and notices are escaped');
        update_post_meta($this->parent, 'location_map', ['lat' => 91, 'lng' => -92]);
        $card = LocationCard::data($this->snapshot($this->child));
        ob_start();
        LocationCard::render($card);
        $xp = $this->xpath((string) ob_get_clean());
        $this->assertTrue($xp->query('//a[@target="_blank" and contains(@rel,"noopener")]')->length === 0, 'Invalid map values leave no broken external action');
    }

    public function test_mixed_results_keep_their_order_count_and_pagination(): void
    {
        $provider = $this->make_page(['post_type' => 'provider', 'post_title' => 'Profile in mixed results']);
        $fallback = $this->make_page(['post_type' => 'location', 'post_title' => 'Fallback clinic']);
        $payload = ['results' => [new Result(id: $provider, site: $this->site, relevance: 0.0, excerpt: '', title: '', url: (string) get_permalink($provider), post_type: 'provider', file_size: 0), $this->snapshot($this->child), new Result(id: 0, site: $this->site, relevance: 0.0, excerpt: 'Keep this excerpt', title: 'Ordinary page', url: (string) get_permalink($this->page), post_type: 'page', file_size: 0), $this->snapshot($fallback)], 'total' => 44, 'pages' => 5, 'fallback' => false, 'suggestion' => null];
        $query = new WP_Query;
        $query->set('s', 'Clinic');
        $query->set('uams_search_results', $payload);
        $GLOBALS['wp_query'] = $query;
        ob_start();
        Integration::render();
        $html = (string) ob_get_clean();
        $xp = $this->xpath($html);
        $names = [];
        foreach ($xp->query('//article') as $article) {
            $names[] = $article->getAttribute('class');
        }

        $this->assertTrue(count($names) === 4 && str_contains($names[0], 'uams-search-provider') && str_contains($names[1], 'uams-search-location') && $names[2] === 'uams-search-result' && str_contains($names[3], 'uams-search-location'), 'Provider, location, and text results retain their relevance order');
        $this->assertTrue(str_contains($html, '44 results') && str_contains($html, 'Search result pages') && str_contains($html, 'Keep this excerpt'), 'Location cards preserve counts, pagination, and ordinary excerpts');
    }

    public function test_same_id_locations_resolve_on_their_own_site(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Requires a multisite install (WP_MULTISITE=1).');
        }

        $caller = $GLOBALS['post'];
        $remote = wpmu_create_blog('uams-search-test.test', '/loc-'.$this->token.'/', 'Location card fixture', 1, ['public' => 1], get_current_network_id());
        if (is_wp_error($remote)) {
            throw new RuntimeException($remote->get_error_message());
        }

        $this->remote = $remote;
        switch_to_blog($remote);
        try {
            $other = wp_insert_post(['post_type' => 'location', 'post_status' => 'publish', 'post_title' => 'Remote clinic', 'import_id' => $this->child], true);
            $this->set($other, ['location_address_1' => 'Remote address', 'location_phone' => '5015559999', 'location_appointments_query' => '0']);
            $remote_url = get_permalink($other);
        } finally {
            restore_current_blog();
        }

        $card = LocationCard::data($this->snapshot($other, $remote));
        $this->assertTrue($other === $this->child && $card['name'] === 'Remote clinic' && $card['address'] === ['Remote address'] && $card['url'] === $remote_url, 'Same-ID locations resolve fields and links on their own sub-site');
        $this->assertTrue(get_current_blog_id() === $this->site && $GLOBALS['post'] === $caller && get_post($this->child)->post_title === 'Child Clinic', 'Remote cards restore caller state and preserve the local post cache');
    }

    public function test_values_that_cannot_be_phone_numbers_are_dropped_and_the_length_limits_hold(): void
    {
        $child = $this->child;
        $accepted = ['5015264020' => '501-526-4020', '1234567' => '1234567', '123456789012345' => '123456789012345', '1-501-526-4020' => '501-526-4020', '(501)'.str_repeat(' ', 87).'526-4020' => '501-526-4020'];
        $this->assertSame(100, strlen(array_key_last($accepted)), 'The longest accepted value is exactly 100 characters');
        foreach ($accepted as $value => $text) {
            update_post_meta($child, 'location_phone', (string) $value);
            $this->assertSame($text, LocationCard::data($this->snapshot($child))['phones']['numbers'][0]['text'], 'Accepted: '.trim((string) $value));
        }

        $rejected = ['not a number', '123456', '1234567890123456', '(501)'.str_repeat(' ', 88).'526-4020', str_repeat('5', 101)];
        foreach ($rejected as $value) {
            update_post_meta($child, 'location_phone', $value);
            $this->assertSame([], LocationCard::data($this->snapshot($child))['phones']['numbers'], 'Rejected: '.substr($value, 0, 20));
        }

        update_post_meta($child, 'location_phone', ['5015264020']);
        $this->assertSame([], LocationCard::data($this->snapshot($child))['phones']['numbers'], 'A list is not a phone number');
    }

    public function test_an_address_line_that_is_not_text_is_left_out(): void
    {
        $this->set($this->child, ['location_parent' => '0', 'location_address_1' => ['100 Main Street']]);

        $this->assertSame(['Child city'], LocationCard::data($this->snapshot($this->child))['address']);
    }

    public function test_dates_are_read_in_each_stored_format_and_nothing_else(): void
    {
        $date = new ReflectionMethod(LocationCard::class, 'date');
        foreach (['20261030', '2026-10-30', 'October 30, 2026'] as $value) {
            $this->assertSame('2026-10-30', $date->invoke(null, $value)?->format('Y-m-d'), $value);
        }

        foreach ([['20261030'], '', str_repeat('2', 41), "2026\0", '20261340', '2026-02-30', 'Octember 30, 2026', 20261030] as $value) {
            $this->assertNull($date->invoke(null, $value), is_string($value) ? substr($value, 0, 20) : gettype($value));
        }
    }

    public function test_the_address_names_the_building_the_floor_and_the_suite(): void
    {
        if (function_exists('get_field_object')) {
            $this->markTestSkipped('A floor-choice function is already defined in this process.');
        }

        $child = $this->child;
        update_post_meta($child, 'location_parent', '0');
        $building = $this->track_term(wp_insert_term('Tower '.$this->token, 'building')['term_id']);
        $this->set($child, ['location_building' => $building, 'location_building_floor' => 'B', 'location_suite' => 'Suite 5', 'location_address_2' => 'Ignored second line']);
        $city = 'Child city';

        $this->assertSame(['Child address', 'Tower '.$this->token, 'Level B, Suite 5', $city], LocationCard::data($this->snapshot($child))['address'], 'A building and level replace the second address line');
        foreach (['G' => 'Ground Floor, Suite 5', '3' => 'Floor 3, Suite 5', '20' => 'Floor 20, Suite 5', '21' => 'Suite 5', '0' => 'Suite 5', '' => 'Suite 5', 'penthouse' => 'Suite 5'] as $floor => $level) {
            update_post_meta($child, 'location_building_floor', $floor);
            $this->assertSame($level, LocationCard::data($this->snapshot($child))['address'][2], 'Floor value "'.$floor.'"');
        }

        $this->set($child, ['location_suite' => '', 'location_building_floor' => '3']);
        $this->assertSame(['Child address', 'Tower '.$this->token, 'Floor 3', $city], LocationCard::data($this->snapshot($child))['address']);
        $this->set($child, ['location_suite' => 'Suite 5', 'location_building' => 0]);
        $this->assertSame(['Child address', 'Floor 3, Suite 5', $city], LocationCard::data($this->snapshot($child))['address'], 'A level alone still replaces the second address line');
        $this->set($child, ['location_suite' => '', 'location_building_floor' => '']);
        $this->assertSame(['Child address', 'Ignored second line', $city], LocationCard::data($this->snapshot($child))['address'], 'With no building or level the second address line is used');
    }

    public function test_a_building_with_the_placeholder_slug_is_not_named(): void
    {
        $child = $this->child;
        update_post_meta($child, 'location_parent', '0');
        $none = wp_insert_term('None '.$this->token, 'building', ['slug' => '_none']);
        $this->assertIsArray($none);
        $this->track_term($none['term_id']);
        $this->set($child, ['location_building' => $none['term_id'], 'location_address_2' => 'Second line']);

        $this->assertSame(['Child address', 'Second line', 'Child city'], LocationCard::data($this->snapshot($child))['address']);
    }

    /** Declares the ACF function for the rest of the process, so it runs after the tests that need it absent. */
    public function test_the_floor_is_named_from_the_field_choices_when_acf_is_active(): void
    {
        if (! function_exists('get_field_object')) {
            eval('function get_field_object($selector, $post_id = false, $format = true) { return $GLOBALS["uams_floor_object"] ?? false; }');
        }

        $child = $this->child;
        update_post_meta($child, 'location_parent', '0');
        $this->set($child, ['location_building_floor' => '2', 'location_suite' => '']);

        $GLOBALS['uams_floor_object'] = ['choices' => ['2' => 'Second Floor']];
        $this->assertSame('Second Floor', LocationCard::data($this->snapshot($child))['address'][1]);
        $GLOBALS['uams_floor_object'] = ['choices' => ['9' => 'Ninth Floor']];
        $this->assertSame('Child city', LocationCard::data($this->snapshot($child))['address'][1], 'A value with no choice names no floor');
        $GLOBALS['uams_floor_object'] = ['choices' => 'none'];
        $this->assertSame('Child city', LocationCard::data($this->snapshot($child))['address'][1], 'Choices that are not a list name no floor');
        $GLOBALS['uams_floor_object'] = false;
        $this->assertSame('Child city', LocationCard::data($this->snapshot($child))['address'][1], 'A missing field object names no floor');
        unset($GLOBALS['uams_floor_object']);
    }

    /** Defines the placeholder constant for the rest of the process, so it runs last. */
    public function test_a_location_with_no_photo_shows_the_directory_placeholder_when_it_is_known(): void
    {
        $bare = $this->make_page(['post_type' => 'location', 'post_title' => 'Bare '.$this->token]);
        if (! defined('UAMS_FAD_ROOT_URL')) {
            $this->assertSame('', LocationCard::data($this->snapshot($bare))['image'], 'Without the directory there is no placeholder');
            define('UAMS_FAD_ROOT_URL', 'https://directory.example.test/fad/');
        }

        $image = LocationCard::data($this->snapshot($bare))['image'];
        $this->assertStringContainsString('src="'.esc_url(rtrim(UAMS_FAD_ROOT_URL, '/').'/assets/svg/no-image_16-9.svg').'"', $image);
        $this->assertStringContainsString('alt="" loading="lazy" width="512" height="288"', $image);
    }

    /** @param array<string, mixed> $values */
    private function set(int $id, array $values): void
    {
        foreach ($values as $key => $value) {
            update_post_meta($id, $key, $value);
        }
    }

    private function track_term(int $id): int
    {
        $this->terms[] = $id;

        return $id;
    }

    private function image(int $owner, string $label): void
    {
        $id = $this->track(wp_insert_attachment(['post_title' => $label, 'post_status' => 'inherit', 'post_mime_type' => 'image/png'], '', $owner));
        update_post_meta($id, '_wp_attached_file', $label.'.png');
        wp_update_attachment_metadata($id, ['width' => 512, 'height' => 288, 'file' => $label.'.png', 'sizes' => []]);
        set_post_thumbnail($owner, $id);
    }

    public function test_a_result_without_a_site_or_a_post_has_no_card(): void
    {
        // The caller's post must not stand in for a missing ID
        $GLOBALS['post'] = get_post($this->parent);
        $this->assertNull(LocationCard::data($this->snapshot(0)), 'No post ID');
        $this->assertNull(LocationCard::data($this->snapshot(99999999)), 'A deleted post');
        $this->assertNull(LocationCard::data($this->snapshot($this->parent, 0)), 'No site');
    }

    private function snapshot(int $id, ?int $blog = null): Result
    {
        return new Result(id: $id, site: $blog ?? get_current_blog_id(), relevance: 0.0, excerpt: 'Old location excerpt', title: 'Indexed snapshot', url: 'https://example.test/stale/', post_type: 'location', file_size: 0);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        return new DOMXPath($dom);
    }
}

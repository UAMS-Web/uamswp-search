<?php

namespace UAMS_Search;

/** Current location details in the directory's landscape card layout. */
final class LocationCard
{
    /**
     * Split a field into text lines at line breaks and `<br>` tags.
     *
     * @param  mixed  $value  A field value.
     * @return list<string> The non-empty lines as plain text; empty for a value that is not a string or integer.
     */
    private static function lines(mixed $value): array
    {
        // Ignore a value that is not text
        if (! is_string($value) && ! is_int($value)) {
            return [];
        }

        // Split at breaks and drop empty lines
        $lines = preg_split('/(?:<br\s*\/?\s*>|\r?\n)+/i', (string) $value);

        return array_values(array_filter(array_map([CardFields::class, 'text'], $lines), static fn (string $line): bool => $line !== ''));
    }

    /**
     * Read a date field in one of the formats the directory stores.
     *
     * @param  mixed  $value  A field value in `Ymd`, `Y-m-d` or `F j, Y` form.
     * @return \DateTimeImmutable|null The date at midnight in the site's timezone, or null when it is not a valid date.
     */
    private static function date(mixed $value): ?\DateTimeImmutable
    {
        // Reject a value that cannot be a date
        if (! is_string($value) || $value === '' || strlen($value) > 40 || str_contains($value, "\0")) {
            return null;
        }

        // Accept the first format that round-trips to the same text
        foreach (['Ymd', 'Y-m-d', 'F j, Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value, wp_timezone());
            if ($date && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }

    /**
     * The closure or modified-hours notice for a location.
     *
     * @param  int  $id  Location post ID.
     * @return array{text: string, label: string}|null The notice and the label of its link, or null when none applies.
     */
    private static function notice(int $id): ?array
    {
        // Show a closure notice while the closure is in effect or upcoming
        $today = current_datetime()->setTime(0, 0);
        if (CardFields::flag(CardFields::field('location_closing', $id))) {
            $length = CardFields::field('location_closing_length', $id);
            $start = self::date(CardFields::field('location_closing_date', $id));
            $end = self::date(CardFields::field('location_reopen_date', $id));
            if ($length === 'permanent' || ($length === 'temporary' && (! $end instanceof \DateTimeImmutable || $end > $today))) {
                if (! $start instanceof \DateTimeImmutable || $start <= $today) {
                    $text = 'This location is '.($length === 'temporary' ? 'temporarily' : 'permanently').' closed.';
                } else {
                    $text = 'This location will be closing '.($length === 'temporary' ? 'temporarily beginning ' : 'permanently on ').$start->format('F j, Y').'.';
                }

                return ['text' => $text, 'label' => 'Learn more about the closure of this location'];
            }
        }

        // Read the hours group
        $hours = CardFields::field('location_hours_group', $id);
        $hours = is_array($hours) ? $hours : [];

        // Collect the start dates of modified hours that begin within 30 days or are in effect
        $starts = [];
        foreach (['location_modified_hours', 'location_telemed_modified_hours'] as $prefix) {
            $keys = [$prefix === 'location_modified_hours' ? $prefix : $prefix.'_query', $prefix.'_start_date', $prefix.'_end', $prefix.'_end_date'];
            $values = [];
            foreach ($keys as $key) {
                $values[] = array_key_exists($key, $hours) ? $hours[$key] : (array_key_exists('field_'.$key, $hours) ? $hours['field_'.$key] : CardFields::field('location_hours_group_'.$key, $id));
            }

            [$enabled,$start_value,$end_known,$end_value] = $values;
            $start = self::date($start_value);
            $end = self::date($end_value);
            if (CardFields::flag($enabled) && $start instanceof \DateTimeImmutable && $start <= $today->modify('+30 days') && (! CardFields::flag($end_known) || ($end instanceof \DateTimeImmutable && $end >= $today))) {
                $starts[] = $start;
            }
        }

        // Show no notice when no modified hours apply
        if ($starts === []) {
            return null;
        }

        // Describe the earliest modified hours
        usort($starts, static fn (mixed $a, mixed $b): int => $a <=> $b);
        $start = $starts[0];

        return ['text' => $start <= $today ? "This location's hours have been temporarily modified." : "This location's hours will be temporarily modified beginning on ".$start->format('F j, Y').'.', 'label' => 'Learn more about the modified hours'];
    }

    /**
     * Format a phone number for display and dialing.
     *
     * @param  mixed  $value  A field value, optionally ending in an extension.
     * @return array{text: string, url: string, label: string}|null The display text, a `tel:` link and an empty label, or null when the value is not a phone number.
     */
    private static function phone(mixed $value): ?array
    {
        // Ignore a value that is not text
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        // Reject a value too long to be a phone number
        $raw = trim((string) $value);
        if (strlen($raw) > 100) {
            return null;
        }

        // Split off an extension
        $extension = '';
        if (preg_match('/(?:ext\.?|x|#)\s*(\d{1,6})\s*$/i', $raw, $match, PREG_OFFSET_CAPTURE)) {
            $extension = $match[1][0];
            $raw = substr($raw, 0, $match[0][1]);
        }

        // Reject text/markup rather than silently turning an invalid value into another number.
        if (! preg_match('/^\+?[\d\s().-]+$/', $raw)) {
            return null;
        }

        // Check the digit count
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return null;
        }

        // Format a ten-digit number as 000-000-0000 and build the dial string
        $national = strlen($digits) === 11 && $digits[0] === '1' ? substr($digits, 1) : $digits;
        $display = strlen($national) === 10 ? substr($national, 0, 3).'-'.substr($national, 3, 3).'-'.substr($national, 6) : trim($raw);
        $dial = (str_starts_with(trim($raw), '+') ? '+' : '').$digits;

        return ['text' => $display.($extension !== '' ? ' ext. '.$extension : ''), 'url' => 'tel:'.$dial.($extension !== '' ? ';ext='.$extension : ''), 'label' => ''];
    }

    /**
     * The phone numbers shown on a location's card.
     *
     * @param  int  $id  Location post ID.
     * @return array{heading: string, numbers: list<array{text: string, url: string, label: string}>} The heading and numbers; no numbers when the location has no main phone.
     */
    private static function phones(int $id): array
    {
        // Return nothing without a main number
        $main = self::phone(CardFields::field('location_phone', $id));
        if (! $main) {
            return ['heading' => '', 'numbers' => []];
        }

        // Show only the main number when the location has no appointments
        $appointments = CardFields::field('location_appointments_query', $id);
        if ($appointments !== null && ! CardFields::flag($appointments)) {
            return ['heading' => 'General Information', 'numbers' => [$main]];
        }

        // Read how the location handles appointments
        $children = CardFields::flag(CardFields::field('location_ac_query', $id));
        $split_children = $children && CardFields::flag(CardFields::field('location_ac_appointments_query', $id));
        // Collect the appointment numbers
        $numbers = [];
        if ($split_children) {
            foreach (['location_ac_appointments_primary' => 'Primary Care', 'location_ac_appointments_specialty' => 'Specialty Care'] as $key => $label) {
                $phone = self::phone(CardFields::field($key, $id));
                if ($phone) {
                    $phone['label'] = $label;
                    $numbers[] = $phone;
                }
            }
        } else {
            $custom = $children || CardFields::flag(CardFields::field('location_clinic_phone_query', $id));
            $new = $custom ? self::phone(CardFields::field('location_new_appointments_phone', $id)) : null;
            $separate = ! $children && $custom && CardFields::flag(CardFields::field('location_appointment_phone_query', $id));
            if ($new) {
                $new['label'] = $separate ? 'New Patients' : 'New and Returning Patients';
                $numbers[] = $new;
                $returning = $separate ? self::phone(CardFields::field('location_return_appointments_phone', $id)) : null;
                if ($returning) {
                    $returning['label'] = 'Returning Patients';
                    $numbers[] = $returning;
                }
            }
        }

        // Fall back to the main number
        if ($numbers === []) {
            $main['label'] = 'New and Returning Patients';
            $numbers[] = $main;
        }

        // Pluralize the heading for several numbers
        return ['heading' => 'Appointment Phone Number'.(count($numbers) > 1 ? 's' : ''), 'numbers' => $numbers];
    }

    /**
     * The address lines of a location.
     *
     * @param  int  $id  Post ID of the location whose address is shown.
     * @return list<string> The street, building, floor and suite, and city, state and ZIP.
     */
    private static function address(int $id): array
    {
        // Start with the street address and read the building
        $lines = self::lines(CardFields::field('location_address_1', $id));
        $building = CardFields::term(CardFields::field('location_building', $id), 'building');
        $building_name = $building instanceof \WP_Term && $building->slug !== '_none' ? CardFields::text($building->name) : '';
        // Name the floor from the field's choices, or from its value
        $floor_value = CardFields::text(CardFields::field('location_building_floor', $id));
        $floor = '';
        if ($floor_value !== '' && $floor_value !== '0') {
            if (function_exists('get_field_object')) {
                $object = get_field_object('location_building_floor', $id, false);
                if (is_array($object) && isset($object['choices']) && is_array($object['choices'])) {
                    $floor = CardFields::text($object['choices'][$floor_value] ?? '');
                }
            } elseif ($floor_value === 'B' || $floor_value === 'G') {
                $floor = $floor_value === 'B' ? 'Level B' : 'Ground Floor';
            } elseif (ctype_digit($floor_value) && (int) $floor_value <= 20) {
                $floor = 'Floor '.(int) $floor_value;
            }
        }

        // Add the building and the floor and suite
        $suite = CardFields::text(CardFields::field('location_suite', $id));
        $level = implode(', ', array_filter([$floor, $suite], static fn (string $value): bool => $value !== ''));
        if ($building_name !== '') {
            $lines[] = $building_name;
        }

        if ($level !== '') {
            $lines[] = $level;
        }

        // Use the second address line when there is no building or level
        if ($building_name === '' && $level === '') {
            $lines = array_merge($lines, self::lines(CardFields::field('location_address_2', $id)));
        }

        // Add the city, state and ZIP line
        $city = CardFields::text(CardFields::field('location_city', $id));
        $state = CardFields::text(CardFields::field('location_state', $id));
        $zip = CardFields::text(CardFields::field('location_zip', $id));
        $last = trim($city.($city !== '' && $state !== '' ? ', ' : '').$state.($zip !== '' ? ' '.$zip : ''));
        if ($last !== '') {
            $lines[] = $last;
        }

        return $lines;
    }

    /**
     * A Google Maps directions link for a map field.
     *
     * @param  mixed  $map  A map field with numeric `lat` and `lng`.
     * @return string The link, or an empty string when the coordinates are missing or out of range.
     */
    private static function directions(mixed $map): string
    {
        // Reject a map without numeric coordinates
        if (! is_array($map) || ! isset($map['lat'],$map['lng']) || ! is_numeric($map['lat']) || ! is_numeric($map['lng'])) {
            return '';
        }

        // Reject coordinates outside the valid range
        $lat = (float) $map['lat'];
        $lng = (float) $map['lng'];
        if (! is_finite($lat) || ! is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return '';
        }

        // Build the link
        return 'https://www.google.com/maps/dir/Current+Location/'.rawurlencode($lat.','.$lng);
    }

    /**
     * Read the live fields shown on a location's card.
     *
     * @param  object  $result  A search result with `site`, `id` and `url`.
     * @return array{name: string, url: string, image: string, parent: array{name: string, url: string, prefix: string}|null, address: list<string>, phones: array{heading: string, numbers: list<array{text: string, url: string, label: string}>}, directions: string, notice: array{text: string, label: string}|null}|null
     *                                                                                                                                                                                                                                                                                                                         The card, or null when the site is not public or the post is not a searchable location.
     */
    public static function data(object $result): ?array
    {
        // Read the result's site and post, and check the site is public
        $site = (int) $result->site;
        $id = (int) $result->id;
        if ($site < 1 || $id < 1 || ! in_array($site, Config::public_sites(), true)) {
            return null;
        }

        // Switch to the result's site
        $changed = get_current_blog_id() !== $site;
        if ($changed) {
            switch_to_blog($site);
        }

        try {
            // Check the post is a searchable location
            $post = get_post($id);
            if (! $post || $post->post_type !== 'location' || ! Extractor::eligible($post)) {
                return null;
            }

            // Use the parent location's address and image when it is searchable
            $parent = null;
            $address_id = $id;
            $image_id = $id;
            $parent_id = CardFields::id(CardFields::field('location_parent_id', $id));
            if (CardFields::flag(CardFields::field('location_parent', $id)) && $parent_id && $parent_id !== $id) {
                $candidate = get_post($parent_id);
                if ($candidate && $candidate->post_type === 'location' && Extractor::eligible($candidate)) {
                    $parent = $candidate;
                    $address_id = $parent_id;
                    $image_id = $parent_id;
                }
            }

            // Keep the location's own image when it overrides the parent's
            if ($parent && CardFields::flag(CardFields::field('location_image_override_parent', $id)) && CardFields::flag(CardFields::field('location_image_override_parent_featured', $id))) {
                $image_id = $id;
            }

            // Use the featured image, or the Find-a-Doc placeholder
            $name = CardFields::text($post->post_title) ?: 'Location';
            $image = '';
            $thumbnail = get_post_thumbnail_id($image_id);
            if ($thumbnail) {
                $image = wp_get_attachment_image($thumbnail, 'aspect-16-9-small', false, ['class' => 'card-img-top', 'loading' => 'lazy']);
            }

            if (! $image && defined('UAMS_FAD_ROOT_URL')) {
                $image = '<img src="'.esc_url(rtrim(UAMS_FAD_ROOT_URL, '/').'/assets/svg/no-image_16-9.svg').'" alt="" loading="lazy" width="512" height="288">';
            }

            // Assemble the card
            return ['name' => $name, 'url' => get_permalink($post) ?: $result->url, 'image' => $image,
                'parent' => $parent ? ['name' => CardFields::text($parent->post_title), 'url' => get_permalink($parent), 'prefix' => CardFields::flag(CardFields::field('location_prepend_the', $parent->ID)) ? 'Part of the ' : 'Part of '] : null,
                'address' => self::address($address_id), 'phones' => self::phones($id), 'directions' => self::directions(CardFields::field('location_map', $address_id)), 'notice' => self::notice($id)];
        } finally {
            // Switch back to the earlier site
            if ($changed) {
                restore_current_blog();
            }
        }
    }

    /**
     * Print a location card.
     *
     * @param  array{name: string, url: string, image: string, parent: array{name: string, url: string, prefix: string}|null, address: list<string>, phones: array{heading: string, numbers: list<array{text: string, url: string, label: string}>}, directions: string, notice: array{text: string, label: string}|null}  $card
     *                                                                                                                                                                                                                                                                                                                            Card data from `data()`.
     */
    public static function render(array $card): void
    {
        // Print the image, or a placeholder
        echo '<article class="uams-search-location card"><picture>';
        if ($card['image']) {
            echo wp_kses_post($card['image']);
        } else {
            echo '<span class="uams-search-location-placeholder" aria-hidden="true">No Image Available</span>';
        }

        // Print the label, name and parent location
        $label = Config::result_labels()['location'] ?? '';
        echo '</picture><div class="card-body">'.($label !== '' ? '<p class="uams-search-label">'.esc_html($label).'</p>' : '').'<h3 class="card-title h5"><span class="name"><a href="'.esc_url($card['url']).'">'.esc_html($card['name']).'</a></span>';
        if ($card['parent']) {
            echo '<span class="subtitle">'.esc_html($card['parent']['prefix']).'<a href="'.esc_url($card['parent']['url']).'">'.esc_html($card['parent']['name']).'</a></span>';
        }

        // Print the notice, address and phone numbers
        echo '</h3>';
        if ($card['notice']) {
            echo '<div class="alert alert-warning"><p>'.esc_html($card['notice']['text']).'</p><a href="'.esc_url($card['url']).'" aria-label="'.esc_attr($card['notice']['label'].' at '.$card['name']).'">Learn more</a></div>';
        }

        if ($card['address']) {
            echo '<p class="card-text">'.implode('<br>', array_map(esc_html(...), $card['address'])).'</p>';
        }

        if ($card['phones']['numbers']) {
            echo '<dl><dt>'.esc_html($card['phones']['heading']).'</dt>';
            foreach ($card['phones']['numbers'] as $phone) {
                echo '<dd><a class="icon-phone" href="'.esc_url($phone['url'], ['tel']).'">'.esc_html($phone['text']).'</a>';
                if ($phone['label'] !== '') {
                    echo '<br><span class="subtitle">'.esc_html($phone['label']).'</span>';
                }

                echo '</dd>';
            }

            echo '</dl>';
        }

        // Print the page and directions buttons
        echo '</div><div class="btn-container"><div class="inner-container"><a class="btn btn-primary" href="'.esc_url($card['url']).'" aria-label="'.esc_attr('View location page for '.$card['name']).'">View Location</a>';
        if ($card['directions'] !== '') {
            echo '<a class="btn btn-outline-primary" href="'.esc_url($card['directions']).'" target="_blank" rel="noopener noreferrer" aria-label="'.esc_attr('Get Directions to '.$card['name'].' (opens in a new tab)').'">Get Directions</a>';
        }

        echo '</div></div></article>';
    }
}

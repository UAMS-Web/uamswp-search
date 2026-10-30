<?php
namespace UAMS_Search;

/** Current location details in the directory's landscape card layout. */
final class LocationCard {
    private static function lines($value): array {
        if (!is_string($value) && !is_int($value)) { return []; }
        $lines = preg_split('/(?:<br\s*\/?\s*>|\r?\n)+/i', (string)$value);
        return array_values(array_filter(array_map([CardFields::class, 'text'], $lines), static fn($line) => $line !== ''));
    }
    private static function date($value): ?\DateTimeImmutable {
        if (!is_string($value) || $value === '' || strlen($value) > 40 || str_contains($value, "\0")) { return null; }
        foreach (['Ymd','Y-m-d','F j, Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, wp_timezone());
            if ($date && $date->format($format) === $value) { return $date; }
        }
        return null;
    }
    private static function notice(int $id): ?array {
        $today = current_datetime()->setTime(0, 0);
        if (CardFields::flag(CardFields::field('location_closing', $id))) {
            $length = CardFields::field('location_closing_length', $id);
            $start = self::date(CardFields::field('location_closing_date', $id));
            $end = self::date(CardFields::field('location_reopen_date', $id));
            if ($length === 'permanent' || ($length === 'temporary' && (!$end || $end > $today))) {
                if (!$start || $start <= $today) { $text = 'This location is ' . ($length === 'temporary' ? 'temporarily' : 'permanently') . ' closed.'; }
                else { $text = 'This location will be closing ' . ($length === 'temporary' ? 'temporarily beginning ' : 'permanently on ') . $start->format('F j, Y') . '.'; }
                return ['text'=>$text,'label'=>'Learn more about the closure of this location'];
            }
        }
        $hours = CardFields::field('location_hours_group', $id); $hours = is_array($hours) ? $hours : [];
        $starts = [];
        foreach (['location_modified_hours','location_telemed_modified_hours'] as $prefix) {
            $keys = [$prefix === 'location_modified_hours' ? $prefix : $prefix . '_query', $prefix . '_start_date', $prefix . '_end', $prefix . '_end_date'];
            $values = [];
            foreach ($keys as $key) {
                $values[] = array_key_exists($key, $hours) ? $hours[$key] : (array_key_exists('field_' . $key, $hours) ? $hours['field_' . $key] : CardFields::field('location_hours_group_' . $key, $id));
            }
            [$enabled,$start_value,$end_known,$end_value] = $values;
            $start = self::date($start_value); $end = self::date($end_value);
            if (CardFields::flag($enabled) && $start && $start <= $today->modify('+30 days') && (!CardFields::flag($end_known) || ($end && $end >= $today))) { $starts[] = $start; }
        }
        if (!$starts) { return null; }
        usort($starts, static fn($a, $b) => $a <=> $b); $start = $starts[0];
        return ['text'=>$start <= $today ? "This location's hours have been temporarily modified." : "This location's hours will be temporarily modified beginning on " . $start->format('F j, Y') . '.', 'label'=>'Learn more about the modified hours'];
    }
    private static function phone($value): ?array {
        if (!is_string($value) && !is_int($value)) { return null; }
        $raw = trim((string)$value); if (strlen($raw) > 100) { return null; } $extension = '';
        if (preg_match('/(?:ext\.?|x|#)\s*(\d{1,6})\s*$/i', $raw, $match, PREG_OFFSET_CAPTURE)) {
            $extension = $match[1][0]; $raw = substr($raw, 0, $match[0][1]);
        }
        // Reject text/markup rather than silently turning an invalid value into another number.
        if (!preg_match('/^\+?[\d\s().-]+$/', $raw)) { return null; }
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) < 7 || strlen($digits) > 15) { return null; }
        $national = strlen($digits) === 11 && $digits[0] === '1' ? substr($digits, 1) : $digits;
        $display = strlen($national) === 10 ? substr($national, 0, 3) . '-' . substr($national, 3, 3) . '-' . substr($national, 6) : trim($raw);
        $dial = (str_starts_with(trim($raw), '+') ? '+' : '') . $digits;
        return ['text'=>$display . ($extension !== '' ? ' ext. ' . $extension : ''),'url'=>'tel:' . $dial . ($extension !== '' ? ';ext=' . $extension : ''),'label'=>''];
    }
    private static function phones(int $id): array {
        $main = self::phone(CardFields::field('location_phone', $id));
        if (!$main) { return ['heading'=>'','numbers'=>[]]; }
        $appointments = CardFields::field('location_appointments_query', $id);
        if ($appointments !== null && !CardFields::flag($appointments)) { return ['heading'=>'General Information','numbers'=>[$main]]; }
        $children = CardFields::flag(CardFields::field('location_ac_query', $id));
        $split_children = $children && CardFields::flag(CardFields::field('location_ac_appointments_query', $id));
        $numbers = [];
        if ($split_children) {
            foreach (['location_ac_appointments_primary'=>'Primary Care','location_ac_appointments_specialty'=>'Specialty Care'] as $key=>$label) {
                $phone = self::phone(CardFields::field($key, $id)); if ($phone) { $phone['label'] = $label; $numbers[] = $phone; }
            }
        } else {
            $custom = $children || CardFields::flag(CardFields::field('location_clinic_phone_query', $id));
            $new = $custom ? self::phone(CardFields::field('location_new_appointments_phone', $id)) : null;
            $separate = !$children && $custom && CardFields::flag(CardFields::field('location_appointment_phone_query', $id));
            if ($new) {
                $new['label'] = $separate ? 'New Patients' : 'New and Returning Patients'; $numbers[] = $new;
                $returning = $separate ? self::phone(CardFields::field('location_return_appointments_phone', $id)) : null;
                if ($returning) { $returning['label'] = 'Returning Patients'; $numbers[] = $returning; }
            }
        }
        if (!$numbers) { $main['label'] = 'New and Returning Patients'; $numbers[] = $main; }
        return ['heading'=>'Appointment Phone Number' . (count($numbers) > 1 ? 's' : ''),'numbers'=>$numbers];
    }
    private static function address(int $id): array {
        $lines = self::lines(CardFields::field('location_address_1', $id));
        $building = CardFields::term(CardFields::field('location_building', $id), 'building');
        $building_name = $building && $building->slug !== '_none' ? CardFields::text($building->name) : '';
        $floor_value = CardFields::text(CardFields::field('location_building_floor', $id)); $floor = '';
        if ($floor_value !== '' && $floor_value !== '0') {
            if (function_exists('get_field_object')) {
                $object = get_field_object('location_building_floor', $id, false);
                if (is_array($object) && isset($object['choices']) && is_array($object['choices'])) { $floor = CardFields::text($object['choices'][$floor_value] ?? ''); }
            } elseif ($floor_value === 'B' || $floor_value === 'G') { $floor = $floor_value === 'B' ? 'Level B' : 'Ground Floor'; }
            elseif (ctype_digit($floor_value) && (int)$floor_value <= 20) { $floor = 'Floor ' . (int)$floor_value; }
        }
        $suite = CardFields::text(CardFields::field('location_suite', $id));
        $level = implode(', ', array_filter([$floor,$suite], static fn($value) => $value !== ''));
        if ($building_name !== '') { $lines[] = $building_name; }
        if ($level !== '') { $lines[] = $level; }
        if ($building_name === '' && $level === '') { $lines = array_merge($lines, self::lines(CardFields::field('location_address_2', $id))); }
        $city = CardFields::text(CardFields::field('location_city', $id)); $state = CardFields::text(CardFields::field('location_state', $id)); $zip = CardFields::text(CardFields::field('location_zip', $id));
        $last = trim($city . ($city !== '' && $state !== '' ? ', ' : '') . $state . ($zip !== '' ? ' ' . $zip : ''));
        if ($last !== '') { $lines[] = $last; }
        return $lines;
    }
    private static function directions($map): string {
        if (!is_array($map) || !isset($map['lat'],$map['lng']) || !is_numeric($map['lat']) || !is_numeric($map['lng'])) { return ''; }
        $lat = (float)$map['lat']; $lng = (float)$map['lng'];
        if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) { return ''; }
        return 'https://www.google.com/maps/dir/Current+Location/' . rawurlencode((string)$lat . ',' . (string)$lng);
    }
    public static function data(object $result): ?array {
        $site = (int)$result->site; $id = (int)$result->id;
        if ($site < 1 || $id < 1 || !in_array($site, Config::public_sites(), true)) { return null; }
        $changed = get_current_blog_id() !== $site; if ($changed) { switch_to_blog($site); }
        try {
            $post = get_post($id);
            if (!$post || $post->post_type !== 'location' || !Extractor::eligible($post)) { return null; }
            $parent = null; $address_id = $id; $image_id = $id;
            $parent_id = CardFields::id(CardFields::field('location_parent_id', $id));
            if (CardFields::flag(CardFields::field('location_parent', $id)) && $parent_id && $parent_id !== $id) {
                $candidate = get_post($parent_id);
                if ($candidate && $candidate->post_type === 'location' && Extractor::eligible($candidate)) { $parent = $candidate; $address_id = $parent_id; $image_id = $parent_id; }
            }
            if ($parent && CardFields::flag(CardFields::field('location_image_override_parent', $id)) && CardFields::flag(CardFields::field('location_image_override_parent_featured', $id))) { $image_id = $id; }
            $name = CardFields::text($post->post_title) ?: 'Location'; $image = ''; $thumbnail = get_post_thumbnail_id($image_id);
            if ($thumbnail) { $image = wp_get_attachment_image($thumbnail, 'aspect-16-9-small', false, ['class'=>'card-img-top','loading'=>'lazy']); }
            if (!$image && defined('UAMS_FAD_ROOT_URL')) { $image = '<img src="' . esc_url(rtrim(UAMS_FAD_ROOT_URL, '/') . '/assets/svg/no-image_16-9.svg') . '" alt="" loading="lazy" width="512" height="288">'; }
            return ['name'=>$name,'url'=>get_permalink($post) ?: $result->url,'image'=>$image,
                'parent'=>$parent ? ['name'=>CardFields::text($parent->post_title),'url'=>get_permalink($parent),'prefix'=>CardFields::flag(CardFields::field('location_prepend_the', $parent->ID)) ? 'Part of the ' : 'Part of '] : null,
                'address'=>self::address($address_id),'phones'=>self::phones($id),'directions'=>self::directions(CardFields::field('location_map', $address_id)),'notice'=>self::notice($id)];
        } finally { if ($changed) { restore_current_blog(); } }
    }
    public static function render(array $card): void {
        echo '<article class="uams-search-location card"><picture>';
        if ($card['image']) { echo wp_kses_post($card['image']); }
        else { echo '<span class="uams-search-location-placeholder" aria-hidden="true">No Image Available</span>'; }
        echo '</picture><div class="card-body"><h3 class="card-title h5"><span class="name"><a href="' . esc_url($card['url']) . '">' . esc_html($card['name']) . '</a></span>';
        if ($card['parent']) { echo '<span class="subtitle">' . esc_html($card['parent']['prefix']) . '<a href="' . esc_url($card['parent']['url']) . '">' . esc_html($card['parent']['name']) . '</a></span>'; }
        echo '</h3>';
        if ($card['notice']) { echo '<div class="alert alert-warning"><p>' . esc_html($card['notice']['text']) . '</p><a href="' . esc_url($card['url']) . '" aria-label="' . esc_attr($card['notice']['label'] . ' at ' . $card['name']) . '">Learn more</a></div>'; }
        if ($card['address']) { echo '<p class="card-text">' . implode('<br>', array_map('esc_html', $card['address'])) . '</p>'; }
        if ($card['phones']['numbers']) {
            echo '<dl><dt>' . esc_html($card['phones']['heading']) . '</dt>';
            foreach ($card['phones']['numbers'] as $phone) {
                echo '<dd><a class="icon-phone" href="' . esc_url($phone['url'], ['tel']) . '">' . esc_html($phone['text']) . '</a>';
                if ($phone['label'] !== '') { echo '<br><span class="subtitle">' . esc_html($phone['label']) . '</span>'; }
                echo '</dd>';
            }
            echo '</dl>';
        }
        echo '</div><div class="btn-container"><div class="inner-container"><a class="btn btn-primary" href="' . esc_url($card['url']) . '" aria-label="' . esc_attr('View location page for ' . $card['name']) . '">View Location</a>';
        if ($card['directions'] !== '') { echo '<a class="btn btn-outline-primary" href="' . esc_url($card['directions']) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr('Get Directions to ' . $card['name'] . ' (opens in a new tab)') . '">Get Directions</a>'; }
        echo '</div></div></article>';
    }
}

<?php
namespace UAMS_Search;

/** Present current provider fields in the card layout used by Find-a-Doc. */
final class ProviderCard {
    public static function data(object $result): ?array {
        $site = (int)$result->site; $id = (int)$result->id;
        if ($site < 1 || $id < 1 || !in_array($site, Config::public_sites(), true)) { return null; }
        $changed = get_current_blog_id() !== $site;
        if ($changed) { switch_to_blog($site); }
        try {
            $post = get_post($id);
            if (!$post || $post->post_type !== 'provider' || !Extractor::eligible($post)) { return null; }
            $parts = []; $usable_name = [];
            foreach (['physician_first_name','physician_middle_name','physician_last_name','physician_pedigree'] as $key) {
                $value = CardFields::text(CardFields::field($key, $id)); if ($value !== '') { $parts[] = $value; }
                $usable_name[$key] = $value;
            }
            $use_parts = $usable_name['physician_first_name'] !== '' && $usable_name['physician_last_name'] !== '';
            $name = $use_parts ? implode(' ', $parts) : CardFields::text($post->post_title);
            if ($use_parts) {
                $degrees = []; $values = CardFields::field('physician_degree', $id);
                foreach (is_array($values) ? $values : [$values] as $value) {
                    $term = CardFields::term($value, 'degree');
                    if ($term && ($degree = CardFields::text($term->name)) !== '') { $degrees[] = $degree; }
                }
                if ($degrees) { $name .= ', ' . implode(', ', array_unique($degrees)); }
            }
            if ($name === '') { $name = 'Provider'; }
            $subtitle = '';
            if (CardFields::flag(CardFields::field('physician_resident', $id))) { $subtitle = 'Resident Physician'; }
            else {
                $title = CardFields::field('physician_title', $id);
                $term = CardFields::term(is_array($title) ? reset($title) : $title, 'clinical_title');
                if ($term) { $subtitle = CardFields::text(CardFields::field('clinical_specialization_title', $term)) ?: CardFields::text($term->name); }
            }
            $image = ''; $thumbnail = get_post_thumbnail_id($id);
            if ($thumbnail) { $image = wp_get_attachment_image($thumbnail, 'medium', false, ['class'=>'card-img-top','alt'=>$name,'loading'=>'lazy']); }
            if (!$image && defined('UAMS_FAD_ROOT_URL')) {
                $image = '<img src="' . esc_url(rtrim(UAMS_FAD_ROOT_URL, '/') . '/assets/svg/no-image_3-4.svg') . '" alt="" class="card-img-top" loading="lazy" width="253" height="337">';
            }
            return ['name'=>$name,'subtitle'=>$subtitle,'image'=>$image,'url'=>get_permalink($post) ?: $result->url];
        } finally { if ($changed) { restore_current_blog(); } }
    }
    public static function render(array $card): void {
        echo '<article class="uams-search-provider card"><picture>';
        if ($card['image']) { echo wp_kses_post($card['image']); }
        else { echo '<span class="uams-search-provider-placeholder" aria-hidden="true">No Image<br>Available</span>'; }
        echo '</picture><div class="card-body"><h3 class="card-title h6"><span class="name">' . esc_html($card['name']) . '</span>';
        if ($card['subtitle'] !== '') { echo '<span class="subtitle">' . esc_html($card['subtitle']) . '</span>'; }
        echo '</h3></div><div class="btn-container"><div class="inner-container"><a class="btn btn-primary stretched-link" href="' . esc_url($card['url']) . '" aria-label="' . esc_attr('View profile for ' . $card['name']) . '">View Profile</a></div></div></article>';
    }
}

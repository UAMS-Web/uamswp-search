<?php
namespace UAMS_Search;

final class Config {
    public static function defaults(): array {
        return [
            'enabled' => false, 'live' => true, 'logging' => true,
            'sites' => null,
            'weights' => ['title' => 5, 'terms' => 3, 'fields' => 2, 'content' => 1, 'pdf' => 1],
            'types' => ['provider' => 1.5, 'expertise' => 1.3, 'location' => 1.2, 'condition' => 1.0, 'treatment' => 1.0, 'page' => 1.0, 'post' => 0.8, 'attachment' => 0.8],
            'fields' => [
                'provider' => array_fill_keys(['physician_first_name', 'physician_middle_name', 'physician_last_name', 'physician_full_name', 'physician_medium_name', 'physician_clinical_bio', 'physician_short_clinical_bio', 'physician_clinical_focus', 'physician_research_interests', 'physician_additional_info'], 1),
                'location' => array_fill_keys(['location_about', 'location_short_desc', 'location_description', 'location_address_1', 'location_address_2', 'location_city', 'location_state', 'location_zip', 'location_abbreviation', 'location_web_name', 'location_parking', 'location_direction'], 1),
                'expertise' => ['post_excerpt' => 1, 'expertise_alternate_names' => 1],
            ],
            'taxonomies' => [
                'provider' => array_fill_keys(['condition', 'treatment', 'service_line', 'degree', 'language', 'clinical_title', 'affiliation'], 1),
                'location' => array_fill_keys(['service_line', 'language', 'region', 'building', 'parking'], 1),
                'expertise' => [], 'page' => [], 'post' => ['category' => 1, 'post_tag' => 1],
            ],
            'stopwords' => [], 'synonyms' => [],
            'pdf' => true, 'pdf_max_mb' => 20, 'pdf_timeout' => 30, 'pdf_parent' => false,
            'extract_warn_seconds' => 5, 'phrase_bonus' => 5, 'recency' => 0.15,
        ];
    }
    public static function get(): array {
        $saved = get_site_option('uams_search_settings', []);
        return apply_filters('uams_search_config', array_replace(self::defaults(), is_array($saved) ? $saved : []));
    }
    public static function public_sites(): array {
        if (!is_multisite()) { return [get_current_blog_id()]; }
        $args = ['fields' => 'ids', 'number' => 0, 'public' => 1, 'archived' => 0, 'spam' => 0, 'deleted' => 0];
        // Local clones commonly discourage indexing on every site. Preserve those
        // settings and allow their content only for CLI indexing or signed-in testing.
        if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local' && ((defined('WP_CLI') && WP_CLI) || is_user_logged_in())) { unset($args['public']); }
        return array_map('intval', get_sites($args));
    }
    public static function sites(string $scope, int $site): array {
        $public = self::public_sites();
        if ($scope === 'site') { return in_array($site, $public, true) ? [$site] : []; }
        $chosen = self::get()['sites'];
        return $chosen === null ? $public : array_values(array_intersect($public, array_map('intval', $chosen)));
    }
    public static function scope($value = null): string {
        return in_array($value, ['site', 'install'], true) ? $value : (get_option('uams_search_scope', 'site') === 'install' ? 'install' : 'site');
    }
    public static function fingerprint(): string {
        $c = self::get();
        return hash('sha256', wp_json_encode([$c['fields'], $c['taxonomies'], $c['stopwords'], array_keys($c['types']), $c['pdf'], $c['pdf_parent']]));
    }
    public static function needs_rebuild(): bool {
        $fingerprint = self::fingerprint();
        foreach (self::public_sites() as $site) { if (get_site_option('uams_search_fingerprint_' . $site) !== $fingerprint) { return true; } }
        return false;
    }
}

<?php

namespace UAMS_Search;

final class Config
{
    public static function defaults(): array
    {
        return [
            'enabled' => false, 'live' => true, 'logging' => true,
            'preview_noindex' => function_exists('wp_get_environment_type') && in_array(wp_get_environment_type(), ['local', 'development', 'staging'], true),
            'sites' => null, 'related_provider_weight' => 0.25,
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

    public static function get(): array
    {
        $saved = get_site_option('uams_search_settings', []);

        return apply_filters('uams_search_config', array_replace(self::defaults(), is_array($saved) ? $saved : []));
    }

    public static function public_sites(): array
    {
        if (! is_multisite()) {
            return [get_current_blog_id()];
        }

        $preview = self::get()['preview_noindex'] && ((defined('WP_CLI') && WP_CLI) || is_user_logged_in());

        return self::site_ids($preview);
    }

    public static function index_sites(): array
    {
        if (! is_multisite()) {
            return [get_current_blog_id()];
        }

        return self::site_ids((bool) self::get()['preview_noindex']);
    }

    public static function site_ids(bool $include_noindex): array
    {
        if (! is_multisite()) {
            return [get_current_blog_id()];
        }

        $args = ['fields' => 'ids', 'number' => 0, 'archived' => 0, 'spam' => 0, 'deleted' => 0];
        if (! $include_noindex) {
            $args['public'] = 1;
        }

        return array_map(intval(...), get_sites($args));
    }

    public static function sites(string $scope, int $site): array
    {
        $public = self::public_sites();
        if ($scope === 'site') {
            return in_array($site, $public, true) ? [$site] : [];
        }

        $chosen = self::get()['sites'];

        return $chosen === null ? $public : array_values(array_intersect($public, array_map(intval(...), $chosen)));
    }

    /**
     * The names of the content types in the type filter, keyed by post type. They match the UAMS Statamic site; a type with no entry here keeps its WordPress name, and the `uams_search_type_labels` filter can rename any of them.
     *
     * @return array<string, string>
     */
    public static function type_labels(): array
    {
        $labels = ['provider' => 'People', 'expertise' => 'Clinical Areas of Expertise', 'location' => 'Clinical Locations', 'post' => 'News', 'attachment' => 'PDFs'];

        return (array) apply_filters('uams_search_type_labels', $labels);
    }

    /**
     * The label above each result, keyed by post type. A type with no entry gets none. The `uams_search_result_labels` filter can change or add any of them.
     *
     * @return array<string, string>
     */
    public static function result_labels(): array
    {
        $labels = ['provider' => 'Person', 'location' => 'Clinical Location', 'expertise' => 'Area of Expertise', 'condition' => 'Condition', 'treatment' => 'Treatment or Procedure', 'post' => 'News', 'page' => 'Page', 'attachment' => 'PDF'];

        return (array) apply_filters('uams_search_result_labels', $labels);
    }

    /**
     * Whether searching all UAMS websites through Google is offered on this site.
     */
    public static function google_enabled(): bool
    {
        // The reference theme always shows this block; newer settings integrations can override this filter.
        return (bool) apply_filters('uams_search_google_enabled', (bool) get_option('uams_search_google', true));
    }

    /**
     * The scopes this site offers, in order, each with its label. `site` is this site alone, `install` every site the network settings include, `all` every UAMS website through Google.
     *
     * The root site of a subdirectory network sees its own scope as `install`, labelled `This site`, because its children are part of the site a visitor thinks they are on.
     *
     * @param  bool  $google  Whether to leave out `all` where Google results are switched off for this site.
     * @return array<string, string>
     */
    public static function scope_options(bool $google = true): array
    {
        if (is_multisite() && ! is_subdomain_install()) {
            $options = is_main_site() ? ['install' => 'This site'] : ['site' => 'This site', 'install' => 'All of '.get_blog_option(get_main_site_id(), 'blogname')];
        } else {
            $options = ['site' => 'This site'];
        }

        if (! $google || self::google_enabled()) {
            $options['all'] = 'All UAMS websites';
        }

        return $options;
    }

    /**
     * The scope to search with: the requested one when this site offers it, otherwise the site's default, otherwise the first option.
     *
     * @param  mixed  $value
     */
    public static function scope($value = null): string
    {
        $options = array_keys(self::scope_options());
        if (is_string($value) && in_array($value, $options, true)) {
            return $value;
        }

        $default = get_option('uams_search_scope', 'site');

        return is_string($default) && in_array($default, $options, true) ? $default : $options[0];
    }

    public static function fingerprint(): string
    {
        $c = self::get();

        return hash('sha256', wp_json_encode([$c['fields'], $c['taxonomies'], $c['stopwords'], array_keys($c['types']), $c['pdf'], $c['pdf_parent'], $c['pdf_max_mb'], $c['pdf_timeout'], $c['preview_noindex'], Related::VERSION, $c['related_provider_weight']]));
    }

    public static function needs_rebuild(): bool
    {
        $fingerprint = self::fingerprint();
        foreach (self::index_sites() as $site) {
            if (get_site_option('uams_search_fingerprint_'.$site) !== $fingerprint) {
                return true;
            }
        }

        return false;
    }
}

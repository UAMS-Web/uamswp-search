<?php

namespace UAMS_Search;

final class Config
{
    public static function defaults(): array
    {
        return [
            'enabled' => false, 'live' => true, 'logging' => true,
            'preview_noindex' => function_exists('wp_get_environment_type') && in_array(wp_get_environment_type(), ['local', 'development', 'staging'], true),
            'results_per_page' => 20,
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

    /**
     * The whole normalized settings array, for code that writes it back. Readers use `flag()`, `number()`, `section()`, `entry()` or `chosen_sites()`.
     *
     * @return array<mixed>
     */
    public static function get(): array
    {
        $saved = get_site_option('uams_search_settings', []);

        return self::normalize(Filters::array('uams_search_config', array_replace(self::defaults(), is_array($saved) ? $saved : [])));
    }

    /** A WordPress option as a string, or `$default` when the stored value is not one. */
    public static function option_string(string $name, string $default): string
    {
        $value = get_option($name, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** A WordPress option as a boolean, or `$default` when the stored value is not scalar. */
    public static function option_flag(string $name, bool $default): bool
    {
        $value = get_option($name, $default);

        return is_scalar($value) ? (bool) $value : $default;
    }

    /** One boolean setting. */
    public static function flag(string $key): bool
    {
        return (bool) (self::get()[$key] ?? false);
    }

    /** One numeric setting. */
    public static function number(string $key): float
    {
        return (float) (self::get()[$key] ?? 0);
    }

    /**
     * One list or map setting, such as `types` or `stopwords`.
     *
     * @return array<mixed>
     */
    public static function section(string $key): array
    {
        $value = self::get()[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * One named entry of a map setting, such as the field weights for one post type, as an array.
     *
     * @return array<mixed>
     */
    public static function entry(string $key, string $name): array
    {
        $value = self::section($key)[$name] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * The sites chosen in the settings, or null when every public site is searched.
     *
     * @return list<int>|null
     */
    public static function chosen_sites(): ?array
    {
        $value = self::get()['sites'] ?? null;

        return is_array($value) ? array_values(array_map(intval(...), $value)) : null;
    }

    /**
     * Gives every known setting the type of its default, so a saved option or a filter that returns the wrong type
     * cannot reach a caller. `sites` stays null (every public site) or a list.
     *
     * @param  array<mixed>  $config
     * @return array<mixed>
     */
    private static function normalize(array $config): array
    {
        foreach (self::defaults() as $key => $default) {
            $value = $config[$key] ?? $default;
            if ($default === null) {
                $config[$key] = is_array($value) ? $value : null;
            } elseif (is_bool($default)) {
                $config[$key] = is_scalar($value) ? (bool) $value : $default;
            } elseif (is_int($default) || is_float($default)) {
                $config[$key] = is_numeric($value) ? $value + 0 : $default;
            } else {
                $config[$key] = is_array($value) ? $value : $default;
            }
        }

        return $config;
    }

    public static function page_size(mixed $value = null): int
    {
        $value ??= self::number('results_per_page') ?: 20;

        return is_scalar($value) && is_numeric($value) && is_finite((float) $value) ? max(1, min(100, (int) $value)) : 20;
    }

    public static function public_sites(): array
    {
        if (! is_multisite()) {
            return [get_current_blog_id()];
        }

        $preview = self::flag('preview_noindex') && ((defined('WP_CLI') && WP_CLI) || is_user_logged_in());

        return self::site_ids($preview);
    }

    public static function index_sites(): array
    {
        if (! is_multisite()) {
            return [get_current_blog_id()];
        }

        return self::site_ids(self::flag('preview_noindex'));
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

        $chosen = self::chosen_sites();

        return $chosen === null ? $public : array_values(array_intersect($public, $chosen));
    }

    /**
     * The names of the content types in the type filter, keyed by post type. They match the UAMS Statamic site; a type with no entry here keeps its WordPress name, and the `uams_search_type_labels` filter can rename any of them.
     *
     * @return array<string, string>
     */
    public static function type_labels(): array
    {
        $labels = ['provider' => 'People', 'expertise' => 'Clinical Areas of Expertise', 'location' => 'Clinical Locations', 'post' => 'News', 'attachment' => 'PDFs'];

        return Filters::array('uams_search_type_labels', $labels);
    }

    /**
     * The label above each result, keyed by post type. A type with no entry gets none. The `uams_search_result_labels` filter can change or add any of them.
     *
     * @return array<string, string>
     */
    public static function result_labels(): array
    {
        $labels = ['provider' => 'Person', 'location' => 'Clinical Location', 'expertise' => 'Area of Expertise', 'condition' => 'Condition', 'treatment' => 'Treatment or Procedure', 'post' => 'News', 'page' => 'Page', 'attachment' => 'PDF'];

        return Filters::array('uams_search_result_labels', $labels);
    }

    /**
     * Whether searching all UAMS websites through Google is offered on this site.
     */
    public static function google_enabled(): bool
    {
        // The reference theme always shows this block; newer settings integrations can override this filter.
        return (bool) apply_filters('uams_search_google_enabled', self::option_flag('uams_search_google', true));
    }

    /**
     * The scopes this site offers, in order, each with its label. `site` is this site alone, `install` every site the network settings include, `all` every UAMS website through Google.
     *
     * The root site of a subdirectory network sees its own scope as `install`, labeled `This site`, because its children are part of the site a visitor thinks they are on.
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
     */
    public static function scope(mixed $value = null): string
    {
        $options = array_keys(self::scope_options());
        if (is_string($value) && in_array($value, $options, true)) {
            return $value;
        }

        $default = self::option_string('uams_search_scope', 'site');

        return is_string($default) && in_array($default, $options, true) ? $default : $options[0];
    }

    public static function fingerprint(): string
    {
        return hash('sha256', wp_json_encode([self::section('fields'), self::section('taxonomies'), self::section('stopwords'), array_keys(self::section('types')), self::flag('pdf'), self::flag('pdf_parent'), self::number('pdf_max_mb'), self::number('pdf_timeout'), self::flag('preview_noindex'), Related::VERSION, self::number('related_provider_weight')]));
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

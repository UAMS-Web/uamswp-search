Written against v0.6.1.

The filters an integration can hook, and the PHP call that runs a search. For `uams_search_config`, `uams_search_fields`, the two label filters, `uams_search_template`, `uams_search_google_cx` and the two binary paths, a callback that returns another type than it was given is ignored and the original value is used.

## Filters

| Filter | Value | Extra arguments | Effect |
| --- | --- | --- | --- |
| `uams_search_config` | settings array | | Change any setting in [Settings](Settings.md). |
| `uams_search_fields` | `array<string, float>` of field name to weight | post type | Add or remove fields indexed for a post type. |
| `uams_search_field_value` | the field's value | field name, `WP_Post` | Return plain text, or an array of strings or numbers, for a field such as a relationship. |
| `uams_search_include_post` | `true` | `WP_Post` | Return `false` to leave a post out of the index. |
| `uams_search_template` | path to the plugin's template | the template WordPress chose | Replace the results template; see [Results template](Results-template.md). |
| `uams_search_type_labels` | post type to label | | Rename the type filter links. Defaults: People, Clinical Areas of Expertise, Clinical Locations, News, PDFs. |
| `uams_search_result_labels` | post type to label | | Change or add the label above each result. A type with no entry gets none. |
| `uams_search_google_enabled` | the site's checkbox | | Turn the Google block on or off in code. |
| `uams_search_google_cx` | the existing search engine ID | | Use another Google Custom Search engine. |
| `uams_search_pdftotext` | path to `pdftotext`, or empty | | Point at a Poppler binary outside the `PATH`. |
| `uams_search_php_binary` | path to the PHP CLI | | Point at the PHP CLI used for the PDF fallback. |

Changes to a field resolver should be followed by a rebuild; see [Rebuilding the index](Rebuilding-the-index.md).

```php
add_filter('uams_search_fields', function (array $fields, string $type): array {
    if ($type === 'provider') {
        $fields['your_explicit_text_field'] = 1.5;
    }
    return $fields;
}, 10, 2);

add_filter('uams_search_field_value', function ($value, string $field, WP_Post $post) {
    // Return plain text or arrays of strings/numbers for selected relationship fields.
    return $value;
}, 10, 3);
```

## Running a search

`UAMS_Search\Query::run()` runs a search and returns one page of results.

```php
$page = UAMS_Search\Query::run([
    's' => 'heart care',
    'site' => get_current_blog_id(),
    'scope' => 'install',
    'type' => 'provider',
    'page' => 1,
    'per_page' => 10,
]);
// $page: results[], total, pages, fallback, suggestion.
// Each result: id, site, relevance, excerpt, title, url, post_type, file_size.
```

| Argument | Effect |
| --- | --- |
| `s` | The search text. |
| `site` | The site to search from. |
| `scope` | `site` or `install`. |
| `type` | Only results of this post type. |
| `page` | The page number, from 1. |
| `per_page` | Results per page. It overrides **Results per Page**. |

## Excerpts in a theme loop

```php
$excerpt = uams_search_excerpt(get_the_ID(), get_current_blog_id());
// The string is already escaped and contains only plugin-generated <mark> tags.
```

## What is read, and what is not

ACF block saved values use the same field allowlist as post fields. Core dynamic blocks are not rendered, and `the_content` is never called. Only explicitly configured fields are read; object-valued fields are skipped and logged.

## Reference

- [Settings](Settings.md)
- [Find-a-Doc content](Find-a-Doc-content.md)

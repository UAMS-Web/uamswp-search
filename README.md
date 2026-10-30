# uams search

WordPress multisite search owned by UAMS. The build includes core indexing and relevance, PDF text, synonyms, spelling links, no-result counts, and keyboard-accessible live search. It uses the install's InnoDB full-text indexes. No external search service is used. The optional existing Google “All of UAMS” block still contacts Google.

**Status: ready for a staging trial, not approved for production.** PHP tests, browser component checks, and isolated WordPress/database tests pass on MySQL 9.4.0. Target MariaDB 10.6.27, the live reference theme, screen-reader checks, SearchWP relevance comparisons, and largest-install performance still need verification. See [the verification record](docs/VERIFICATION.md).

## Install on staging

1. Upload `dist/uams-search-0.5.0.zip` and network-activate it. To update an existing install, upload the ZIP and choose to replace the current version. On single-site WordPress, activate normally. SearchWP can stay active.
2. Open Network Admin → Settings → uams search. Requirements: PHP 8.1+, mbstring, iconv, zlib, WordPress 6.4+, InnoDB, and `innodb_ft_min_token_size=3`. MariaDB 10.6.27 is the target. PDF extraction also needs `proc_open` and either Poppler's `pdftotext` or PHP CLI.
3. Confirm and save post types, fields, and included sites. Click **Rebuild index** and keep the page open. For staging sites marked no-index, leave **Include no-index sites for signed-in previews** checked. No server command access is needed.
4. While signed in, compare `/?s=heart&engine=uams` with the ordinary SearchWP results. The new engine is **off for visitors by default**. Suggestions work in the signed-in preview too.
5. After all release checks pass, enable “Use uams search for visitors” and disable SearchWP. Leave the old plugins installed through the comparison period so rollback is easy.

On a single-site install, index controls are under Settings → uams search Index. Each site's scope is under Settings → uams search, and under UAMS Settings → Search when the existing top-level menu is available. The older reference theme has no usable settings hook for its Google block, so the plugin preserves the block's default visibility and supplies its own per-site checkbox and filter.

## Operations

The browser rebuild saves its progress after each item. Use **Pause rebuild**, **Resume rebuild**, or **Cancel rebuild** on the settings page. If the tab closes or a request fails, reopen the page and resume. The browser drives short requests; the full rebuild does not depend on WP-Cron. Saved edits and CLI indexing wait until the browser rebuild finishes or is cancelled. A repeatedly interrupted item is marked failed after three attempts so other items can finish. Cancellation discards unfinished staged content; already completed sites retain their new content. Reload the page after completion to refresh the status counts.

Command-line tools remain available when the server provides access:

```sh
wp uams-search index --site=1
wp uams-search index --site=1 --post-type=provider
wp uams-search index --all
wp uams-search process-queue --limit=100 --seconds=45
wp uams-search status
wp uams-search reindex 123 --site=1
wp uams-search query "GI" --site=1 --explain
wp uams-search query "heart care" --scope=install --explain
wp uams-search doctor
```

Example server cron, with the actual WP-CLI executable, PHP CLI, and install path supplied by the host:

```cron
*/5 * * * * /usr/local/bin/wp --path=/path/to/wordpress uams-search process-queue --limit=100 --seconds=45 >> /path/to/private/uams-search-cron.log 2>&1
```

WP-Cron uses 15 items and a 15-second budget when server cron is unavailable. It is registered on the network's main site. Budgets are checked between posts; each PDF also has an enforced total extraction deadline. Overlapping workers exit cleanly. New saves during extraction remain queued for a later pass. Three failed attempts leave an error row and a retry link. A new edit or manual retry resets the attempts.

Rebuilds preserve existing results while filling a staging table. A transaction replaces only the selected site's rows, or the selected post type's rows. Other sub-sites remain intact. Changes made during rebuilding are retained in the edit queue. Run the queue after a large rebuild to catch up. Do not run schema DDL or manual index writes alongside a rebuild.

Changing fields, taxonomies, stop words, PDF enablement, or PDF link destination requires a full rebuild of every affected site. Source and type boosts take effect at query time; individual field weights are stored at indexing time and require a rebuild. Per-site fingerprints keep rebuild warnings visible until every public site has been rebuilt. Partial post-type rebuilds deliberately do not clear that warning.

## Theme and Find-a-Doc

The read-only reference was `/Users/bpmore/Herd/uamshealth`. No files in that site were changed. Its search template runs several secondary queries and does not match the newer template described in the spec. The plugin therefore supplies a Genesis search template while its engine is selected. It renders a combined, paginated list with h1 → h2 → h3 headings, visible scope/type links, safe excerpts, and the existing Google block. Provider results use portrait cards matching the location-page Find-a-Doc layout: name and degrees, clinical title (or Resident Physician), and a View Profile link. Other results retain their text excerpts. Consecutive provider results share a responsive card row without changing relevance order or pagination. Card fields and images are read on the result's own sub-site, with safe name/image fallbacks. Location results use the directory's landscape cards with linked titles/parents, inherited parent addresses/photos, child phone routing, valid directions, and closure or modified-hours notices. Hidden/private parent details are not inherited. These details are read live on the result's own site. Updating from 0.3.0 to 0.4.0 or 0.5.0 does not require another index rebuild.

Relevanssi and Ajax Search Pro yield only after uams search has successfully answered that query. They keep handling ordinary searches and fallback requests. Healthy sub-sites marked noindex can be indexed for signed-in previews with the rebuild checkbox. This defaults on in local, development, and staging environments, and can be explicitly enabled on a staging host with any environment label. Anonymous visitors keep the normal site visibility rule. No search-engine visibility setting is changed.

Outside an active search request, the theme stays in control. A missing index or a query error leaves the existing search running. A signed-in `engine=wordpress` request bypasses uams search and lets the existing engine run. On other themes, the plugin supplies a header/main/footer template, which can be replaced with `uams_search_template`.

Find-a-Doc defaults were taken from its actual saved field definitions: provider names and clinical text, location names/address/about text, expertise excerpts/alternate names, and its relevant taxonomies. Only explicitly configured fields are read. Object-valued fields are skipped and logged. `physician_hidden`, an explicit `physician_searchable=0`, and `location_hidden` are respected both while indexing and while querying. The four saved provider lists are indexed automatically: `physician_locations` on locations, `physician_expertise` on expertise pages, `condition_physicians` on conditions, and `treatment_procedure_physicians` on treatments. Only linked, searchable public provider titles are copied, with a default relative field weight of 0.25. The provider profile normally ranks above a related-only match. Relationships are not followed recursively. Other object IDs are not silently treated as names; an integration can resolve another explicitly selected relationship through `uams_search_field_value`.

Version 0.3.0 requires a full rebuild to add these names to existing rows. Provider edits queue dependent pages when the provider queue item is processed; dependent pages may need the following queue batch. Hidden-provider IDs remain as internal dependencies so publishing a provider can restore related matches. If a copied provider title changes or becomes private, hidden, password-protected, excluded, or deleted, live query guards temporarily suppress the related page until its queued refresh removes the stale name. This intentionally protects excerpts as well as result matching. The page becomes searchable by its own text again after refresh.

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

// Optional excerpt use inside a compatible theme loop:
$excerpt = uams_search_excerpt(get_the_ID(), get_current_blog_id());
// The string is already escaped and contains only plugin-generated <mark> tags.
```

Other filters: `uams_search_config`, `uams_search_include_post`, `uams_search_template`, `uams_search_google_enabled`, `uams_search_google_cx`, `uams_search_pdftotext`, and `uams_search_php_binary`. ACF block saved values use the same allowlist. Core dynamic blocks are not rendered and `the_content` is never called. ACF field resolver changes should be followed by a rebuild.

## Database choices

The spec's `SET SESSION innodb_ft_enable_stopword=OFF` does not apply to MariaDB, where that variable is global. The plugin creates its own empty InnoDB stopword table and temporarily sets the session's `innodb_ft_user_stopword_table` while creating the indexes. It restores the prior session value and never changes global server settings. [MariaDB's variable reference](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-system-variables) documents this distinction. The database integration suite must confirm behavior on the target host.

The implementation adds `search_stem` and its full-text index so required words may appear in different sources. Individual source indexes supply relevance scores. A boundary token prevents quoted phrases from spanning sources. `weighted_data` holds per-field stemmed text for custom weights. JSON_TABLE is used only when those weights differ from 1 or a field-list filter is active. The target MariaDB version supports JSON_TABLE.

Short stems are encoded as `uamsshort` + hexadecimal bytes + `zz` on both sides of the search. Encoding is applied after stemming too, so a longer word whose stem is two letters is not lost. Three-letter words such as ENT are left intact. The dictionary uses document frequency, stores at most 10,000 distinct words per object, and considers at most 500 spelling candidates of similar length. These limits bound background work and suggestion cost; they can limit corrections for rare words. Corrections within two edits take priority over phonetic-only candidates, then frequency decides. This avoids collisions between long names with identical sound-key prefixes. A correction is shown only after an all-words query succeeds in the requested scope and type.

Public status, passwords, PDF parent visibility, and search exclusions are checked against live WordPress tables at read time. Queued publication changes therefore do not expose old private results. Cross-site post objects are kept out of WordPress's site-local post cache. Custom access-control plugins need their own publication rules integrated before rollout.

## PDFs and privacy

PDFs are read from local files within uploads. File size and modified date control reuse. Poppler is preferred. The bundled, namespaced PHP parser runs in a child process with a 128 MB memory limit. Both extractors share the configured time budget; the parent kills a timed-out process. Text output is capped at 16 MB. When safe background extraction is unavailable, the item records an error instead of parsing during a visitor request. PDFs without text retain their title and filename matches and appear in status.

The fallback library is smalot/pdfparser 2.13.1, downloaded from its official repository and pinned to a commit. Its license, source, original package manifest, archive checksum, and namespace changes are included. Both Poppler and the updated PHP fallback pass the real-PDF tests. See [third-party details](THIRD-PARTY.md).

No-result logging stores only search text, UTC date, site, and count. Search text can still contain sensitive health information. Admin access is restricted; the feature can be switched off. Daily retention removes rows older than 90 days, and the status view covers the last 30 days. Live suggestions and direct API searches do not create log entries. Deactivation preserves data and removes scheduled tasks.

## Tests and build

```sh
composer install
composer test
python3 scripts/make-fixtures.py
python3 scripts/build.py

# Disposable WordPress database only; read the guard at the top of this file.
UAMS_SEARCH_TESTS=1 wp --path=/path/to/test-wordpress eval-file /path/to/UAMSearch/tests/integration.php
UAMS_SEARCH_TESTS=1 wp --path=/path/to/test-wordpress eval-file /path/to/UAMSearch/tests/multisite.php

npm install
npx playwright install chromium
npm run test:browser
```

The database name must start with `uams_search_test` and WordPress's environment type must be `local`. The integration script refuses other databases. Browser tests use the actual rendered plugin component with fake data and responses. They do not replace the final axe and screen-reader checks on the real theme. PHP tests use lightweight WordPress seams for API tests and real child processes/PDF fixtures for extraction tests. Developer dependencies are not bundled in the upload ZIP.

## Release gates

- Run the integration suite on MariaDB 10.6.27 with the host's actual database grants, then verify a multisite clone with overlapping post IDs, excluded/archived sites, and rebuilds while editors save.
- Compare the fixed search list with SearchWP, including real provider names and expected top results supplied by the content/Find-a-Doc team. The supplied CSV is a starter list, not a relevance pass.
- Run keyboard, screen-reader, and axe checks on the real theme at desktop/mobile sizes. Test the optional Google block separately.
- Confirm PHP CLI/Poppler availability and run a complete index of the largest install under its real memory limits.
- Confirm server cron, switch only after the comparisons pass, and observe the status/no-result views for two weeks. Roll back by disabling the visitor engine setting or deactivating the plugin.

Set **Results per page** under **Network Admin > Settings > uams search**. The default is 20; choose 1 to 100. The setting applies immediately without rebuilding the index. Explicit API or WordPress query page sizes override this default.

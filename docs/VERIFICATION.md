# Verification record

Build date: 2026-10-30. Version: 0.5.1. Reference: local `uamshealth`, read only. Downloads and checks were retried after the session allowed access requests.

## Verified by execution

- **PHP:** 26 tests and 179 assertions pass on PHP 8.4.27 with project-local PHPUnit 11.5.57. They cover Porter examples, short stems, phrases/operators, nested objects/null/booleans, escaped excerpts, phonetic examples, API identity/pagination, cache/fallback boundaries, staging defaults, and explicit signed-in no-index previews.
- **Database:** 35 integration checks pass on a disposable WordPress 7.0.5 install with a fresh MySQL 9.4.0 server. They cover Unicode ligature dictionary keys, long short-token storage, legacy mixed-collation tables, schema setup without global stopword changes, real GI/ENT full-text matching, words across sources, phrases, synonyms/corrections, per-field ranking and explain scores, private/excluded posts, real PDF attachments and cached text, private PDF parents, scanned-PDF filename matches, queue revisions/retries, staged rebuilding, two-connection locking, and retention.
- **Network:** 13 multisite checks pass on the same disposable server. They cover overlapping post IDs, site scope, real main-query totals/pagination, cache safety, public/preview REST access, rebuilding one site, excluded/archived sites, and remote posts made private before queue processing.
- **Related provider names:** 44 additional real database checks cover all four Find-a-Doc provider lists, direct-profile ranking, hidden/private/password/excluded/deleted providers, renamed titles, queue propagation and restoration, wrong-type dependency cycles, type filtering, staged snapshots, and same-ID multisite isolation. Disabling the live guard, dependent refresh, or wrong-type guard makes its named regression fail; restoring the fixes passes. The actual local Find-a-Doc ACF read path, with the custom-table plugin active, also passes: Barnes finds all 3 linked locations, 2 expertise pages, 7 conditions, and 6 treatments, with C. Lowry Barnes himself first (verified by result ID). Before updating, local Barnes results contained no locations, conditions, or treatments. Only Barnes and those linked pages were reindexed for this check; the full local network was not rebuilt.
- **Browser rebuild:** 36 additional real database checks pass for start/counts, duplicate requests, pause/resume, staged publication, queued edits, stale-tab rejection, cancel, no-index visibility, changed settings, and AJAX permissions/nonces. Failure injection proves a failed cancellation checkpoint retains staged rows. A second database connection proves busy stale requests cannot adopt newer jobs. Separate PHP processes deliberately exit three times during a post; durable checkpoints survive and the next request skips that item while completing the rest. Chromium checks the actual rendered admin controls with intercepted responses: start, preview checkbox, pause, page reload/resume, failed response, cancel, completion, sequential requests, late pause/cancel actions at completion (including busy responses), text safety, axe, and reflow at 1280/320 pixels.
- **Browser:** Chromium 156 / Playwright checks pass for Arrow Up/Down, Escape, Tab, Enter, late responses, text-only suggestion titles, and a normal form with JavaScript disabled. Axe finds no violations for the selected WCAG 2.0/2.1/2.2 A/AA rules at 1280 and 320 pixels. No horizontal overflow was found. Tests use the real plugin component with fixture data and intercepted API responses, not the full theme or Google block.
- **Provider cards:** 26 real WordPress checks cover live name/degree/clinical-title/resident fields, malformed and partial names, portraits, missing data, escaping, private/excluded providers, mixed-result order, counts/pagination, same-ID sub-sites, and caller/cache restoration. The partial-name regression fails with its guard disabled. Component browser checks pass at 1440/768/320 pixels for portrait ratio, profile-link keyboard access, 44px targets, escaping, axe, and reflow. A full Genesis page rendered from the local WordPress install also passes the search-region axe checks with its actual HTTPS styles and images loaded; Barnes uses the reference name, degree, clinical title, and portrait with ACF active. Full-site/Google accessibility and the remote host remain unverified.
- **Location cards:** 40 real WordPress checks cover live location titles, parent photo/address inheritance and overrides, child-owned phone routing and patient labels, extensions, malformed values, closures/reopening/modified hours, both ACF group shapes, WordPress timezone parsing, map ranges including zero, private parents/hidden children, escaping, mixed card/text order, counts/pagination, and same-ID sub-sites. Removing the null-byte date guard fails its negative case; restoring malformed CSS selectors fails the browser's computed-style check. Landscape cards, phone/map actions, fallbacks, keyboard access, 44px actions, axe, and reflow pass component checks at 1440/768/320 pixels. The full Genesis preview with loaded HTTPS styles/images also passes the search-region axe, landscape-ratio, viewport-fit, and measured directory typography checks at those widths. With the real ACF custom-table read path active, both Adult Genetics Clinic cards match their directory title, parent, inherited photo, address, appointment number/patient label, and directions. The remote search response remains unverified.
- **Page size:** Eight real WordPress checks verify the default 20-result page, all 25 fixture records across two pages without overlap, settings-form save/render, immediate changed offsets/page counts, explicit WordPress/API overrides, and an unchanged index fingerprint when only page size changes. Unit checks also cover malformed values and the 1 to 100 limits.
- **PDFs:** Poppler and the updated PHP parser extract a real fixture PDF. A text-free fixture returns no text. A deliberately slow child is killed at the deadline; a child exceeding its memory limit fails without stopping the parent.

PHP and JavaScript syntax checks pass. The upload ZIP is checked for integrity, runtime-only contents, checksum accuracy, and execution of its unpacked PDF worker. Developer dependencies have project lock files. The temporary WordPress install has its own database, uploads, and configuration; it only reads reference WordPress core files. The reference `uamshealth` install was not changed. A later, user-authorized install activated UAMS Search on the separate local `uamshealth-dev` network, with visitor search off; see [the install record](UAMSHEALTH-DEV-INSTALL.md).

## Download and failure fixes

- Replaced the old PDF library with **smalot/pdfparser 2.13.1**, official commit `a71c6dd38e60a995fdb4d36e4433b8b3557c65dc`. Runtime sources, package manifest, license, namespace changes, and archive checksum are included. The bundling script makes this reproducible.
- The first database run exposed a spelling collision between long words with the same truncated phonetic prefix. Corrections within two edits now take priority over phonetic-only candidates, with frequency used within each group. The failing regression now passes.
- Dev-install indexing exposed a collation clash between first/ﬁrst and similar Unicode variants. Dictionary key columns now use binary Unicode comparison, with a guarded upgrade for existing tables. The regression and a real network rebuild verify the fix.
- Dev preview checks found older WordPress tables use different text collations; post-type identity comparisons now use binary equality. Long short-token lists use LONGTEXT storage in both live and staging indexes, upgraded while the empty stopword table is selected. Both regressions pass.
- The installed WP-CLI command registration was checked directly; process-queue is explicitly registered with the documented hyphenated name.
- Compatibility guards keep Relevanssi and Ajax Search Pro from overwriting completed UAMS preview queries. Local noindex sites can be indexed for CLI/signed-in previews without changing their search-engine visibility.
- The earlier download, browser-launch, and sandboxed database failures were resolved by approved access requests. Those checks are no longer blocked.

## Reproducible checks

```sh
composer install
composer test
npm ci
npx playwright install chromium
npm run test:browser
npm run test:rebuild-browser
npm run test:provider-browser
npm run test:location-browser
UAMS_SEARCH_TESTS=1 wp --path=/path/to/test-wordpress eval-file /path/to/UAMSearch/tests/integration.php
UAMS_SEARCH_TESTS=1 wp --path=/path/to/test-wordpress eval-file /path/to/UAMSearch/tests/multisite.php
UAMS_SEARCH_TESTS=1 wp --path=/path/to/test-wordpress eval-file /path/to/UAMSearch/tests/rebuild.php
UAMS_SEARCH_TESTS=1 wp --path=/path/to/test-wordpress eval-file /path/to/UAMSearch/tests/related.php
UAMS_SEARCH_TESTS=1 wp --path=/path/to/test-wordpress eval-file /path/to/UAMSearch/tests/provider-cards.php
UAMS_SEARCH_TESTS=1 wp --path=/path/to/test-wordpress eval-file /path/to/UAMSearch/tests/location-cards.php
UAMS_SEARCH_TESTS=1 wp --path=/path/to/test-wordpress eval-file /path/to/UAMSearch/tests/page-size.php
python3 scripts/build.py
```

Database scripts refuse databases not named `uams_search_test*`, or WordPress environments other than `local`. The network script also requires multisite. The integration tests deliberately fail one fixture post to check retries; its specific error-log entries are expected.

## Spec adjustments

- An empty plugin-owned stopword table replaces the unsupported MariaDB session toggle. Global server settings remain unchanged.
- A transactional per-site copy replaces a table rename, since all sub-sites share one live index table.
- The supplied Genesis template replaces the older reference theme's secondary-query loop while this engine is selected. It preserves the optional Google block but uses a unified list rather than the old cards.
- Per-field weights use stored stemmed JSON; a combined full-text column handles words appearing in different sources.
- The suggestion dictionary uses document frequency and bounded candidate/word lists. Close spelling corrections take priority over distant phonetic collisions.

## Still unverified before production

The target is **MariaDB 10.6.27**, while this session's available server is **MySQL 9.4.0**. Successful MySQL checks do not establish target-host compatibility. Repeat the tests with the actual MariaDB version and database grants. Also required: live reference-theme/settings wiring; SearchWP coexistence and relevance comparisons; all Find-a-Doc fields on the copied database; largest-install time/memory; real concurrent editor saves during large rebuilds; full-theme axe checks, screen-reader review, and the optional Google block; hosting cron/extractor availability; deployment and monitoring.

`docs/relevance-cases.csv` contains 50 starter searches with blank expected IDs. Content owners must add real provider-name cases and expected top results before it can judge relevance. Component checks alone do not establish full WCAG 2.2 AA compliance.

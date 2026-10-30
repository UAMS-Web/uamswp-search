# Verification record

Build date: 2026-10-30. Version: 0.2.0. Reference: local `uamshealth`, read only. Downloads and checks were retried after the session allowed access requests.

## Verified by execution

- **PHP:** 24 tests and 159 assertions pass on PHP 8.4.27 with project-local PHPUnit 11.5.57. They cover Porter examples, short stems, phrases/operators, nested objects/null/booleans, escaped excerpts, phonetic examples, API identity/pagination, cache/fallback boundaries, staging defaults, and explicit signed-in no-index previews.
- **Database:** 35 integration checks pass on a disposable WordPress 7.0.5 install with a fresh MySQL 9.4.0 server. They cover Unicode ligature dictionary keys, long short-token storage, legacy mixed-collation tables, schema setup without global stopword changes, real GI/ENT full-text matching, words across sources, phrases, synonyms/corrections, per-field ranking and explain scores, private/excluded posts, real PDF attachments and cached text, private PDF parents, scanned-PDF filename matches, queue revisions/retries, staged rebuilding, two-connection locking, and retention.
- **Network:** 13 multisite checks pass on the same disposable server. They cover overlapping post IDs, site scope, real main-query totals/pagination, cache safety, public/preview REST access, rebuilding one site, excluded/archived sites, and remote posts made private before queue processing.
- **Browser rebuild:** 36 additional real database checks pass for start/counts, duplicate requests, pause/resume, staged publication, queued edits, stale-tab rejection, cancel, no-index visibility, changed settings, and AJAX permissions/nonces. Failure injection proves a failed cancellation checkpoint retains staged rows. A second database connection proves busy stale requests cannot adopt newer jobs. Separate PHP processes deliberately exit three times during a post; durable checkpoints survive and the next request skips that item while completing the rest. Chromium checks the actual rendered admin controls with intercepted responses: start, preview checkbox, pause, page reload/resume, failed response, cancel, completion, sequential requests, late pause/cancel actions at completion (including busy responses), text safety, axe, and reflow at 1280/320 pixels.
- **Browser:** Chromium 156 / Playwright checks pass for Arrow Up/Down, Escape, Tab, Enter, late responses, text-only suggestion titles, and a normal form with JavaScript disabled. Axe finds no violations for the selected WCAG 2.0/2.1/2.2 A/AA rules at 1280 and 320 pixels. No horizontal overflow was found. Tests use the real plugin component with fixture data and intercepted API responses, not the full theme or Google block.
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
UAMS_SEARCH_TESTS=1 wp --path=/path/to/test-wordpress eval-file /path/to/UAMSearch/tests/integration.php
UAMS_SEARCH_TESTS=1 wp --path=/path/to/test-wordpress eval-file /path/to/UAMSearch/tests/multisite.php
UAMS_SEARCH_TESTS=1 wp --path=/path/to/test-wordpress eval-file /path/to/UAMSearch/tests/rebuild.php
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

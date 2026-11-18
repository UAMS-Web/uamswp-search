# UAMSWP Search

WordPress multisite search owned by UAMS. The build includes core indexing and relevance, PDF text, synonyms, spelling links, no-result counts, and keyboard-accessible live search. It uses the install's InnoDB full-text indexes. No external search service is used. The optional existing Google “All of UAMS” block still contacts Google, and it is off by default (see below).

**Status: ready for a staging trial, not approved for production.** PHP tests, browser component checks, and isolated WordPress/database tests pass on MySQL 9.4.0. Target MariaDB 10.6.27, the live reference theme, screen-reader checks, SearchWP relevance comparisons, and largest-install performance still need verification. See [the verification record](docs/VERIFICATION.md).

## Install on staging

1. Download `uams-search-0.6.1.zip` from the [release page](https://github.com/UAMS-Web/uamswp-search/releases/tag/v0.6.1) and network-activate it. To update an existing install, upload the ZIP and choose to replace the current version. On single-site WordPress, activate normally. SearchWP can stay active.
2. Open Network Admin → UAMSWP Search → Index. Requirements: PHP 8.2+, mbstring, iconv, zlib, WordPress 6.8+, InnoDB, and `innodb_ft_min_token_size=3`. MariaDB 10.6.27 is the target. PDF extraction also needs `proc_open` and either Poppler's `pdftotext` or PHP CLI.
3. Under Network Admin → UAMSWP Search → Settings, confirm and save post types, fields, and included sites. Then return to Index and click **Rebuild index** and keep the page open. For staging sites marked no-index, leave **Include no-index sites for signed-in previews** checked. No server command access is needed.
4. While signed in, compare `/?s=heart&engine=uams` with the ordinary SearchWP results. The new engine is **off for visitors by default**. Suggestions work in the signed-in preview too.
5. After all release checks pass, enable “Use UAMSWP Search for visitors” and disable SearchWP. Leave the old plugins installed through the comparison period so rollback is easy.

The menu has three screens, **Index**, **Reports** and **Settings**; the [wiki](https://github.com/UAMS-Web/uamswp-search/wiki/Admin-screens) describes each one.

## Documentation

Everything past installation is in the [wiki](https://github.com/UAMS-Web/uamswp-search/wiki):

- [Rebuilding the index](https://github.com/UAMS-Web/uamswp-search/wiki/Rebuilding-the-index), [running the queue from server cron](https://github.com/UAMS-Web/uamswp-search/wiki/Running-the-queue-from-server-cron), and [going live](https://github.com/UAMS-Web/uamswp-search/wiki/Going-live), including the release gates and rollback.
- Reference: [settings](https://github.com/UAMS-Web/uamswp-search/wiki/Settings), [WP-CLI commands](https://github.com/UAMS-Web/uamswp-search/wiki/WP-CLI-commands), [filters and PHP API](https://github.com/UAMS-Web/uamswp-search/wiki/Filters-and-PHP-API), the [results template](https://github.com/UAMS-Web/uamswp-search/wiki/Results-template), [Find-a-Doc content](https://github.com/UAMS-Web/uamswp-search/wiki/Find-a-Doc-content), [PDFs](https://github.com/UAMS-Web/uamswp-search/wiki/PDFs), [privacy](https://github.com/UAMS-Web/uamswp-search/wiki/Privacy), and [database design](https://github.com/UAMS-Web/uamswp-search/wiki/Database-design).
- [Troubleshooting](https://github.com/UAMS-Web/uamswp-search/wiki/Troubleshooting).

The wiki's pages live in [`docs/wiki/`](docs/wiki/) and are published from there; edit them through a pull request.

## Tests and build

### Amp orbs

Amp runs the executable `.agents/setup` when preparing an orb snapshot. It installs
PHP 8.2 and its test extensions, Composer, Poppler, locked PHP/npm dependencies,
and Playwright Chromium with its Debian libraries. Node 20+ comes from the orb
base image. Matching snapshots reuse this environment; stale snapshots rerun
setup, skipping installed system packages and cached browser downloads.

`.agents/resume` only checks tool/dependency availability and never reinstalls
anything. Run `.agents/setup` manually to repair missing dependencies. No secrets,
authentication, or running services are needed for the unit and component suites.
WordPress/database integration still requires a separate disposable local install
with the database guards described below; setup does not create or connect to one.

### Commands

```sh
composer install
composer test
python3 scripts/make-fixtures.py
composer build

# WordPress integration suite (PHPUnit 9.6 over wp-phpunit). The test database is dropped and rebuilt on every run,
# so give it its own database (default name uams_search_test, innodb_ft_min_token_size=3) and a core copy in WP_TESTS_ABSPATH.
# Set WP_MULTISITE=1 for the multisite run. CI runs both, on MariaDB 10.6 and MySQL 8.0.
composer setup:integration
WP_TESTS_ABSPATH=/path/to/wordpress composer test:integration

# Disposable WordPress database only; read the guard at the top of each script.

npm install
npx playwright install chromium
npm run test:browser
```

Platforms: the runs in the [verification record](docs/VERIFICATION.md) were on macOS. CI runs the integration suite on Ubuntu, and the Amp orb setup runs on Debian. Windows is not yet measured, including the Windows equivalent of `python3` (usually the Python launcher, `py -3`).

The database name must start with `uams_search_test` and WordPress's environment type must be `local`. The integration suite drops and rebuilds its own database on every run. Browser tests use the actual rendered plugin component with fake data and responses. They do not replace the final axe and screen-reader checks on the real theme. PHP tests use lightweight WordPress seams for API tests and real child processes/PDF fixtures for extraction tests. Developer dependencies are not bundled in the upload ZIP.

## Credits

Developed and maintained by UAMS Web Services.

Developers:

- Josh Daugherty
- Brent Passmore

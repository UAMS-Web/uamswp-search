=== UAMS Search ===
Contributors: uams-web
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

UAMS-owned WordPress multisite search using local InnoDB full-text indexes.

== Description ==
Network-wide or per-site search, explicit ACF fields and taxonomies, weighted results, English stemming, short medical terms, safe highlighted excerpts, background PDF extraction, synonyms, spelling links, no-result counts, and accessible live search.

This version is for staging validation. It is disabled for visitors by default. Signed-in users can preview with ?s=heart&engine=uams. Keep SearchWP until the comparison tests pass.

== Installation ==
Network-activate the plugin. Review Network Admin > Settings > UAMS Search.
Run wp uams-search doctor, then wp uams-search index --all.
Compare the preview with the existing search. Enable visitor search only after validation.

Requires InnoDB with minimum token size 3, native mbstring, iconv and zlib, and WP-CLI for rebuilds. PDFs require proc_open and pdftotext or PHP CLI. Cron falls back to WP-Cron when server cron is unavailable.

== Privacy ==
Optional no-result logging stores search text, date, site, and count, without IP addresses or user IDs. Search text can contain health information. Rows older than 90 days are deleted by scheduled cleanup. Disable logging in network settings when needed.

The optional All of UAMS block loads Google Custom Search, matching the reference theme's existing behavior. The local engine itself sends no queries to an external search service.

== Changelog ==
= 0.1.0 =
Initial three-phase implementation for staging review.

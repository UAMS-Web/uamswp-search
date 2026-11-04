=== uams search ===
Contributors: uams-web
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 0.4.0
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

Requires InnoDB with minimum token size 3, native mbstring, iconv and zlib. Rebuild from Network Admin → Settings → uams search without server command access. Keep the page open, or resume later. PDFs require proc_open and pdftotext or PHP CLI. Cron falls back to WP-Cron when server cron is unavailable.

== Privacy ==
Optional no-result logging stores search text, date, site, and count, without IP addresses or user IDs. Search text can contain health information. Rows older than 90 days are deleted by scheduled cleanup. Disable logging in network settings when needed.

The optional All of UAMS block loads Google Custom Search, matching the reference theme's existing behavior. The local engine itself sends no queries to an external search service.

== Changelog ==

= 0.4.0 =
* Add featured results: keyword rules that show chosen pages or external links above the regular results. Install-wide rules for network admins, per-site rules for site administrators.
* Group post types into result-type links with counts, editable under Result types.
* Let a theme keep its own results template and search form, with helpers for type links, notes, featured results and type labels.
* Rank "Did you mean" by likely typo, so very common words such as "can" lose to specific ones.
* Put the network and site admin on one tabbed page each, with settings in columns and tables.
* Log searches with no results whichever template renders the page.
* Remove Relevanssi compatibility. Deactivate Relevanssi before enabling visitor search.
* No index rebuild is needed to update from 0.3.0.

= 0.3.0 =
* Find linked locations, expertise, conditions, and treatments by provider name.
* Refresh related pages after provider edits, with live guards against stale hidden or private names.
* Rebuild the index after updating to include related provider names.

= 0.2.0 =
* Add a full-index rebuild button with saved progress, pause, resume, and cancel.
* Allow explicit no-index staging previews without changing search-engine visibility.
* Keep existing results available and preserve queued edits during browser rebuilds.
= 0.1.0 =
Initial three-phase implementation for staging review.

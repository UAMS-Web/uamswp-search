Written against v0.6.1.

What UAMSWP Search stores about searches, and how long it keeps it.

## The no-result log

When **Keep Search Text With No Results for up to 90 Days** is on ([Settings](Settings.md)), a search that finds nothing is logged.

- **Stored:** the search text, the UTC date, the site, and a count. Nothing identifies the visitor.
- **Sensitive:** search text can still contain health information, so the **Reports** screen needs `manage_network_options` (on a single site, `manage_options`), and the log can be switched off.
- **Retention:** a daily task removes rows older than 90 days. The **Reports** screen shows the last 30 days.
- **Not logged:** live suggestions and direct API searches.

## Visibility checks

Public status, passwords, PDF parent visibility and search exclusions are checked against the live WordPress tables when results are read, so a queued change to a post's publication never exposes an old private result. Cross-site post objects are kept out of WordPress's site-local post cache. Custom access-control plugins need their own publication rules integrated before rollout.

## Outside services

Search runs on the install's own database, and no external search service is used. The one exception is the optional Google block, which loads Google's script on the results page; it is off until a site turns it on ([Settings](Settings.md#per-site-settings)).

## Deactivation

Deactivating the plugin keeps its data and removes its scheduled tasks.

## Reference

- [Settings](Settings.md)
- [Admin screens](Admin-screens.md)

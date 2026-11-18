This wiki covers running and extending UAMSWP Search, the network-activated WordPress plugin that replaces SearchWP on the UAMS multisite installs. The [README](https://github.com/UAMS-Web/uamswp-search#readme) keeps the overview, the project's status, requirements, installation and the development notes; everything longer is here.

Written against v0.6.1.

## Getting started

- [Installation (README)](https://github.com/UAMS-Web/uamswp-search#install-on-staging): download, network-activate and build the first index on a staging install.
- [Admin screens](Admin-screens.md): what each of the Index, Reports and Settings screens is for.

## Guides

- [Rebuilding the index](Rebuilding-the-index.md): when a rebuild is needed, and how to pause, resume or cancel one.
- [Running the queue from server cron](Running-the-queue-from-server-cron.md): moving queued edits off WP-Cron.
- [Going live](Going-live.md): the checks before visitors are switched over, and how to roll back.

## Reference

- [Settings](Settings.md): every network and per-site setting, with its default.
- [WP-CLI commands](WP-CLI-commands.md): the `wp uams-search` commands.
- [Filters and PHP API](Filters-and-PHP-API.md): the filters and the `Query::run()` call for integrations.
- [Results template](Results-template.md): how results render, and how the theme stays in control.
- [Find-a-Doc content](Find-a-Doc-content.md): which provider, location and expertise fields are indexed, and how related provider names are copied.
- [PDFs](PDFs.md): how PDF text is extracted, and its limits.
- [Privacy](Privacy.md): what the no-result log stores and how long it keeps it.
- [Database design](Database-design.md): the full-text indexes, stopwords, short words and spelling suggestions.

## Troubleshooting

- [Troubleshooting](Troubleshooting.md): error messages and their fixes.

## Project

- [Decision log](https://github.com/UAMS-Web/uamswp-search/blob/main/docs/DECISIONS.md): why the project is built the way it is.
- [Verification record](https://github.com/UAMS-Web/uamswp-search/blob/main/docs/VERIFICATION.md): what has been tested, and what has not.
- [Contributing](https://github.com/UAMS-Web/uamswp-search/blob/main/CONTRIBUTING.md): the issue-to-merge path.

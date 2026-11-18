Written against v0.6.1.

Rebuild a site's index after changing what is indexed, without taking search away from visitors while it runs.

## When a rebuild is needed

Changing fields, taxonomies, stop words, PDF indexing, or where PDF results link requires a full rebuild of every affected site. Individual field weights are stored when an item is indexed, so they need a rebuild too. Source weights and content-type weights apply at query time and need none, and neither does **Results per Page**.

Each site keeps a fingerprint of the settings it was built with, so the rebuild warning stays visible until every public site has been rebuilt. A rebuild of one post type deliberately does not clear that warning.

## Steps

1. Open **Network Admin > UAMSWP Search > Index**.
2. On a staging install whose sites are marked no-index, leave **Include No-Index Sites for Signed-In Previews** checked. It does not change search-engine visibility; see [Settings](Settings.md#signed-in-previews).
3. Click **Rebuild Index** and keep the page open. The browser drives short requests one after another, so the rebuild does not depend on WP-Cron.
4. To stop for a while, click **Pause rebuild**, and later **Resume rebuild**. If the tab closes or a request fails, reopen the page and resume. Progress is saved after each item.
5. To abandon it, click **Cancel rebuild**. Unfinished staged content is discarded; sites already completed keep their new content.
6. When it finishes, reload the page to refresh the status counts.

**Note:** an item interrupted three times is marked failed so the rest can finish. It appears under **Reports**, with a retry link.

## While a rebuild runs

- Existing results stay available. The rebuild fills a staging table, then one transaction replaces only the selected site's rows, or the selected post type's rows. Other sub-sites are untouched.
- Edits saved during the rebuild wait in the queue, and so does command-line indexing, until the browser rebuild finishes or is canceled. Run the queue afterwards to catch up; see [Running the queue from server cron](Running-the-queue-from-server-cron.md).
- Do not run schema changes or manual index writes alongside a rebuild.

## From the command line

Where the server gives command access, `wp uams-search index` rebuilds the same way; see [WP-CLI commands](WP-CLI-commands.md#index).

## Reference

- [Settings](Settings.md)
- [WP-CLI commands](WP-CLI-commands.md)
- [Troubleshooting](Troubleshooting.md#rebuild-messages)

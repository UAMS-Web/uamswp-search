Written against v0.6.1.

Saved edits are queued and indexed in the background. Without server cron, WP-Cron processes the queue in small batches; a server cron job processes bigger batches on a schedule that does not depend on visitor traffic.

## What WP-Cron does without it

WP-Cron runs the queue every five minutes with a budget of 15 items and 15 seconds, registered on the network's main site. Budgets are checked between posts, and each PDF also has its own enforced extraction deadline. Overlapping workers exit cleanly, and saves made during extraction stay queued for a later pass.

## Steps

1. Ask the host for the paths to the WP-CLI executable, the PHP CLI and the WordPress root. The values below are placeholders.
2. Add a cron entry that runs `process-queue` every five minutes:

   ```cron
   */5 * * * * /path/to/wp --path=/path/to/wordpress uams-search process-queue --limit=100 --seconds=45 >> /path/to/private/uams-search-cron.log 2>&1
   ```

3. After a few runs, check **Network Admin > UAMSWP Search > Index** for the queue count, or run `wp uams-search status`.

**Note:** keep the log file outside the web root.

## Failures

An item that fails three times leaves an error row with a retry link under **Reports**. A new edit to the item, or a manual retry, resets its attempts.

## Reference

- [WP-CLI commands](WP-CLI-commands.md#process-queue)
- [PDFs](PDFs.md)

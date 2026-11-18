Written against v0.6.1.

The plugin is ready for a staging trial, not approved for production. This page lists what has to pass before visitors are switched from SearchWP, and how to switch back.

## Before switching

- Run the integration suite on MariaDB 10.6.27 with the host's actual database grants, then verify a multisite clone with overlapping post IDs, excluded and archived sites, and rebuilds while editors save.
- Compare a fixed list of searches with SearchWP, including real provider names and the expected top results supplied by the content and Find-a-Doc team. `docs/relevance-cases.csv` is a starter list, not a relevance pass.
- Run keyboard, screen-reader and axe checks on the real theme at desktop and mobile sizes. Test the optional Google block separately.
- Confirm that PHP CLI or Poppler is available, and run a complete index of the largest install under its real memory limits.
- Confirm server cron; see [Running the queue from server cron](Running-the-queue-from-server-cron.md).

What has already been verified, and on which database, is in the [verification record](https://github.com/UAMS-Web/uamswp-search/blob/main/docs/VERIFICATION.md).

## Switching

1. While signed in, compare `/?s=heart&engine=uams` with the ordinary SearchWP results.
2. When every check above passes, tick **Use UAMSWP Search for Visitors** under **Network Admin > UAMSWP Search > Settings**, then disable SearchWP.
3. Leave the old search plugins installed through the comparison period, and watch the **Index** screen's status and the **Reports** screen's failures and no-result searches for two weeks.

**Warning:** the optional Google block loads Google's script, which cannot carry Subresource Integrity. It stays off until a site turns it on, and turning it on is a launch decision; see [Settings](Settings.md#per-site-settings).

## Rolling back

Clear **Use UAMSWP Search for Visitors**, or deactivate the plugin. Deactivation keeps the index data and removes the scheduled tasks.

## Reference

- [Settings](Settings.md)
- [Results template](Results-template.md)

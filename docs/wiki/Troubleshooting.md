Written against v0.6.1.

One heading per message or symptom, as it appears in the admin screens, the **Reports** screen or WP-CLI.

- [Setup messages](#setup-messages)
- [Rebuild messages](#rebuild-messages)
- [PDF messages](#pdf-messages)
- [Search behavior](#search-behavior)

## Setup messages

### `UAMS Search requires innodb_ft_min_token_size=3. Run wp uams-search doctor.`

**Cause:** the database server indexes only longer words, so short searches such as GI would never match.

**Fix:** ask the host to set `innodb_ft_min_token_size=3` in the server configuration and restart it, then rebuild. See [Database design](Database-design.md#requirements).

### `UAMS Search requires the mbstring, zlib and iconv PHP extensions.`

**Cause:** one of the three extensions is missing from the PHP the site runs on.

**Fix:** ask the host to enable it.

### `Index is missing. Run wp uams-search index.`

**Cause:** no index has been built for the site yet.

**Fix:** click **Rebuild Index** on the **Index** screen, or run `wp uams-search index`.

## Rebuild messages

### `No eligible sites. Include no-index sites for signed-in previews if this is a staging install.`

**Cause:** every site is marked no-index, archived or excluded, which is usual on staging.

**Fix:** tick **Include No-Index Sites for Signed-In Previews** beside **Rebuild Index**, then start the rebuild. The CLI form of this message is `No sites are eligible.`

### `Choose at least one content type in the settings first.`

**Fix:** tick **Include This Content Type** for at least one type under **Content To Index** in [Settings](Settings.md).

### `Index settings changed. Cancel this rebuild, then start a new one.`

**Cause:** the settings were saved while a rebuild was paused or running, so its staged rows no longer match them.

**Fix:** click **Cancel rebuild**, then **Rebuild Index**.

### `The server is low on memory. Pause other work, then resume the rebuild.`

**Cause:** the rebuild request reached 80 percent of PHP's memory limit and stopped before running out.

**Fix:** click **Resume rebuild**; progress is kept. If it stops on the same item again, ask the host to raise PHP's `memory_limit`.

### `This rebuild is no longer current. Reload the page.`

**Cause:** a newer rebuild was started, for example from another tab, so this tab's rebuild is no longer the active one.

**Fix:** reload the page.

### `A browser rebuild is active. Resume or cancel it in UAMSWP Search settings first.`

**Cause:** a WP-CLI index or reindex was run while a browser rebuild was running or paused.

**Fix:** finish or cancel the browser rebuild, then run the command again.

### `Another indexer is running. Try again after it finishes.`

**Cause:** an index run, a queue run or a rebuild step already holds the indexer lock; only one runs at a time.

**Fix:** wait for the first to finish. Overlapping queue workers exit cleanly on their own.

## PDF messages

These appear under **Reports > Failed Items and PDFs Without Text**. See [PDFs](PDFs.md).

### `PDF exceeds size limit`

**Cause:** the file is larger than `pdf_max_mb` (20 MB by default). It stays searchable by title and file name.

### `PDF extraction timed out`

**Cause:** extraction ran past `pdf_timeout` (30 seconds by default), and the child process was killed.

### `PDF extraction requires proc_open for enforced time and memory limits`

**Fix:** ask the host to allow `proc_open`. Without it, PDFs are not read during visitor requests.

### `PDF fallback needs an executable PHP CLI; set uams_search_php_binary.`

**Cause:** Poppler's `pdftotext` is missing or failed, and no PHP CLI was found for the fallback parser.

**Fix:** install Poppler, or return the PHP CLI's path from the `uams_search_php_binary` filter ([Filters and PHP API](Filters-and-PHP-API.md)). `wp uams-search doctor` shows what was found.

### `PDF file is missing or outside uploads`

**Cause:** the attachment's file is gone, or its path points outside the uploads folder. Only files inside uploads are read.

## Search behavior

### A signed-in search shows the old results

**Cause:** visitor search is off, so the existing engine still answers.

**Fix:** add `engine=uams` to the search URL to preview UAMSWP Search, for example `/?s=heart&engine=uams`.

### A related page stopped matching a provider's name

**Cause:** the provider was renamed, hidden or made private, and the page is hidden until its queued refresh runs. See [Find-a-Doc content](Find-a-Doc-content.md#keeping-copied-names-current).

**Fix:** run the queue, or wait for the next batch.

### The All UAMS websites scope is missing

**Cause:** the Google block is off by default.

**Fix:** tick **Show the Existing All of UAMS Google Search** in the site's settings, once launch has approved it ([Settings](Settings.md#per-site-settings)).

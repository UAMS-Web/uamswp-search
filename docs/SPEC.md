# UAMS Search Plugin Spec

Oct 29, 2026 · @Todd

## Summary and goals

Build a UAMS-owned WordPress search plugin to replace SearchWP on the \~25 uams-2020 installs, using the database's built-in full-text indexes (MariaDB on BigScoots) and no outside services. It is a bridge until sites move to Statamic, so it favors simple, debuggable code over new search technology.

**Why replace SearchWP**

- **License cost:** no annual license across 25+ installs.
- **Control and reliability:** SearchWP stalled on every provider post because of one meta value, and its errors were hard to trace. We want code we own and can debug.
- **Fewer dependencies:** one less third-party plugin in each install.

**Success criteria**

- On a fixed list of test searches, results are as good as or better than SearchWP's.
- A full index of the largest install finishes over WP-CLI without timeouts.
- One bad post or field never blocks indexing; it is logged and skipped.
- Results pages meet WCAG 2.2 AA.
- Search covers one install. "All of UAMS" stays with Google Custom Search.

## Scope and phases

Phase 1 replaces SearchWP; phases 2 and 3 add what's on the wish list once phase 1 is live.

| Phase | Delivers | Done when |
| --- | --- | --- |
| 1. Core | Index table and queue; WP-CLI indexing; whole-install or current sub-site scope; post types, chosen ACF fields and taxonomy terms; field and post-type weighting; stemming; highlighted excerpts; theme integration; network settings and status page | Test searches match or beat SearchWP on uamshealth.com, and SearchWP is turned off there |
| 2. Wish list | PDF text indexing; "Did you mean" suggestions; synonyms; log of searches with no results | PDFs on the largest install are indexed, and suggestions show on misspelled test searches |
| 3. Nice to have | Live search-as-you-type through a REST endpoint | The search box shows suggestions as you type, with full keyboard and screen-reader support |

**Out of scope**

- Searching across installs. Google Custom Search covers "All of UAMS".
- Images and other media as separate results. They count through the page they sit on.
- OCR for scanned PDFs.
- External search services such as Elasticsearch, Meilisearch or Algolia.

## Architecture

Each install gets one index table, shared by all of its sub-sites, and searches it with MySQL full-text indexes. The plugin is network-activated, so a whole-install search is one query and a sub-site search adds `WHERE site_id = <current site>`. Single-site installs use the same table with `site_id = 1`.

**Index table:** `{base_prefix}uams_search_index`, one row per indexed post or PDF.

| Column | Holds |
| --- | --- |
| `id` | Primary key |
| `site_id`, `object_id` | Sub-site and post ID; unique together |
| `object_type`, `post_type` | `post` or `attachment`, and the post type |
| `title`, `title_stem` | Title, and a stemmed copy |
| `content`, `content_stem` | Plain text of the post body, and a stemmed copy |
| `fields`, `fields_stem` | Text from the chosen ACF fields, and a stemmed copy |
| `terms`, `terms_stem` | Taxonomy term names, and a stemmed copy |
| `short_tokens` | Words shorter than MySQL's minimum word length (GI, ENT), encoded so they still match |
| `url`, `published_at`, `modified_at` | For links, sorting and recency |
| `indexed_at`, `status`, `error` | When it was indexed, and why it failed if it did |

Each text column has its own `FULLTEXT` index, so each can be weighted separately. The plugin's own stop-word list and stemmer run in PHP, so nothing depends on database server settings BigScoots may not let us change.

**Search scope**

- **Whole install:** all sub-sites the network settings include.
- **This sub-site:** only the current site.
- Each sub-site picks its default scope. Visitors can switch with a plain link or radio group above the results; there's no hidden toggle.
- "All of UAMS" across installs stays with Google Custom Search, controlled by the existing UAMS Settings > Search option.

**Code layout**

- **Indexer:** builds and updates rows; runs from WP-CLI and cron.
- **Extractors:** one per source (post content, ACF fields, terms, PDFs), each returning plain text.
- **Text pipeline:** tokenizer, stop words, stemmer and short-word encoding, shared by indexing and searching so both sides treat words the same way.
- **Query:** turns a search into SQL, scores it, and returns `id` and `site` per result.
- **Integration:** `posts_pre_query` hook, excerpt and highlight helpers, REST endpoint (phase 3).
- **Admin:** network settings, per-site settings, index status.

&#91;embedded content: plugin architecture · indexing and search paths\]

Edits reach the index only through the queue, and searches only read it, so a slow or broken post can never hold up a page load.

## Indexing

Edits go into a queue and a WP-CLI command works through it on server cron; full rebuilds run per sub-site over WP-CLI. Nothing calls the site's own URL, so the loopback failure that stalled SearchWP can't happen.

**What adds a post to the queue**

- Saving, publishing, trashing, deleting or restoring a post
- Changes to the ACF fields or post meta on a post
- Changes to a post's taxonomy terms, or renaming a term
- Uploading, replacing or deleting a PDF (phase 2)

The queue is its own small table (`site_id`, `object_id`, `action`, `queued_at`, `attempts`), so repeated saves of one post collapse to one entry.

**How it runs**

- `wp uams-search process-queue` runs every 5 minutes from server cron. It processes posts in batches within a time and memory limit, then stops.
- If server cron isn't available, a WP-Cron event runs the same code, with a smaller batch size.
- `wp uams-search index --site=<id>` rebuilds one sub-site. It writes to a staging copy and swaps it in at the end, so search keeps working during a rebuild.

**What gets indexed, per post**

- **Title.**
- **Content:** `post_content` with blocks parsed, shortcodes and tags stripped. It does *not* run `the_content`, which avoids slow or recursive filters like MinervaKB's.
- **ACF fields:** an explicit list per post type, each with a weight. No "any meta key".
- **Taxonomy terms:** names of the terms on the post, for the taxonomies chosen per post type.
- **Text from ACF blocks:** the block's saved field values, through the same field list.

The field list is a PHP config array with a filter, so Find-a-Doc can register its own provider, location and expertise fields.

**Failure handling**

- Each post is indexed inside `try { … } catch ( \Throwable $e )`, so PHP errors are caught, not only exceptions.
- Any value that isn't a string or number is skipped, with arrays flattened. This covers the Press Ganey `stdClass` that broke SearchWP.
- A failed post gets `status = error` and the message in `error`. The queue moves on, retrying up to 3 times.
- Posts that take longer than a set time to extract are logged with how long they took.

## Query and relevance

A search runs through the same text pipeline as indexing, then a weighted `MATCH … AGAINST` across the stemmed columns. It requires all words first, and falls back to any word if that finds nothing.

**Steps**

1. Normalize the search: lowercase, strip punctuation, split into words. Quoted text stays a phrase.
2. Drop stop words, stem each word, and encode short words the way the index does.
3. Expand synonyms from the admin list (phase 2), for example "heart" → "cardiac, cardiology".
4. **All-words pass:** boolean mode with every stem required, using a trailing wildcard (`+cardio*`), so a partial word still matches.
5. **Any-word pass:** only if the first pass finds nothing, drop the requirement. A note above the results says not every word matched.
6. Score each row, sort, page with `LIMIT`/`OFFSET`, and count totals with a separate `COUNT` query.

**Score** = (sum of each column's match score × that column's weight) × post-type boost + an exact-phrase bonus when the whole search appears in the title. News posts can also get a small recency boost.

**Starting weights** (tuned against the test searches; editable in network settings):

| Source | Weight |
| --- | --- |
| Title | 5 |
| Taxonomy terms | 3 |
| ACF fields | 2 |
| Content | 1 |
| PDF text | 1 |

| Post type | Boost |
| --- | --- |
| `provider` | 1.5 |
| `expertise` | 1.3 |
| `location` | 1.2 |
| `page` | 1.0 |
| `post` (news) | 0.8 |
| PDF | 0.8 |

**Short words:** BigScoots runs MariaDB with `innodb_ft_min_token_size = 3`, so 3-letter terms like MRI and ENT are indexed as is. Two-letter terms (GI, ER, OB) are padded with a reserved suffix on both sides so they still match. When it creates the index, the plugin turns off MariaDB's built-in stop words for that session (`innodb_ft_enable_stopword = OFF`) and applies its own in PHP. The phase 1 spike confirms both.

**"Did you mean" (phase 2)**

- Indexing keeps a word list per install: each word, how often it appears, and a sound-alike key (Double Metaphone).
- When a search finds few or no results, each unmatched word is compared to listed words of similar length with the same sound-alike key, or within 2 letters (Levenshtein). The most common match wins.
- "Did you mean *cardiology*?" shows only when that search would return results, as a normal link.

## Results and theme integration

The plugin answers the main WordPress search query, and results come back in the same shape SearchWP returns. The uams-2020 search loop from October 2026 works without changes.

**Hooking in**

- `posts_pre_query` on the main search query returns objects with `id`, `site`, `relevance` and `excerpt`, then sets `found_posts` and `max_num_pages` so `genesis_posts_nav()` paginates as it does now.
- If the plugin is off or its table is missing, WordPress search runs as normal. The theme's built-in fallback and Google option keep working.
- Filters: `?type=<post type>` narrows results (unknown values are ignored), and `?scope=site|install` picks the scope.
- For other code: `UAMS_Search\Query::run( $args )` returns the same results.

**Excerpts and highlighting**

- Excerpts are cut from the stored plain text. The plugin finds the 30-word window with the most matched words, in this order of preference: content, then fields, then PDF text.
- Matched words are wrapped in `<mark>`. Text is escaped first, and `<mark>` is the only markup added.
- Results get a new `results-*.php` excerpt helper. Templates call `uams_search_excerpt( $post_id )` and fall back to their current excerpt when it returns nothing.

**Accessibility (WCAG 2.2 AA)**

- Heading order stays h1 (page) > h2 (result count) > h3 (each result).
- Scope and type filters are plain links or a radio group in a `<fieldset>` with a `<legend>`, not a toggle or accordion. The current choice is marked with `aria-current`.
- `<mark>` keeps meaning without relying on color alone: matched words also get bold weight, and the highlight color meets 4.5:1 contrast with the text.
- "Did you mean" and the "not every word matched" note are plain text and links at the top of the results, read before the list.
- Live search (phase 3) follows the ARIA combobox pattern: arrow-key navigation, `aria-activedescendant`, and a polite live region announcing the number of suggestions.

## PDF indexing (phase 2)

PDF attachments get their own index rows with extracted text, labelled "PDF" in results. Extraction happens only in the background queue, never during a page load.

**Extracting text**

1. Use the server tool `pdftotext` (Poppler) if BigScoots has it installed and PHP may run it. It's fast and uses little memory.
2. Otherwise use the PHP library smalot/pdfparser, bundled with the plugin through Composer.
3. Skip files over a size limit (default 20 MB) or extraction over a time limit (default 30 seconds), and record why.

Extracted text is kept in the index row only, not in post meta. It's re-extracted only when the file changes, checked by file size and modified date.

**What a PDF result links to**

- Default: the PDF file itself, with the title, "PDF" and file size in the link text, so screen-reader users know it opens a document.
- Network setting: link to the page the PDF is attached to instead, when it has one.
- All PDFs in the media library are indexed, attached or not, unless they're private or someone ticks "Exclude from search" on the file's edit screen.

**Scanned PDFs** have no text layer, so they match by title and file name only. The status page lists them, so content owners can replace them with accessible, text-based PDFs; that's also needed for accessibility.

## Admin and operations

Network admins set what gets indexed and how it's weighted; each sub-site picks only its default scope. A status page shows the index's health, and WP-CLI does the heavy work.

**Network settings** (Network Admin > Settings > UAMS Search)

- Sub-sites included in whole-install search
- Post types to index, with a boost for each
- Fields per post type: ACF fields and taxonomies, with a weight for each (defaults come from code, so Find-a-Doc's fields are set up automatically)
- Column weights, stop-word additions, synonyms (phase 2)
- PDF options: on or off, size and time limits, link to the file or its parent page (phase 2)

**Per-site settings** (on the existing UAMS Settings > Search page)

- Default scope: this sub-site or the whole install
- The existing Google search display option

**Status page**

- Indexed count per sub-site and post type, queue length, and when the queue last ran
- Failed items with their error message and a "retry" link
- Scanned PDFs with no text (phase 2)
- Searches with no results, with counts, for the last 30 days (phase 2)

**WP-CLI commands**

| Command | Does |
| --- | --- |
| `wp uams-search index [--site=<id>] [--post-type=<type>]` | Full rebuild, swapped in when done |
| `wp uams-search process-queue [--limit=<n>]` | Works through the queue; run by server cron |
| `wp uams-search status` | Counts, queue length, failures |
| `wp uams-search reindex <post-id> --site=<id>` | Re-index one post and print what was extracted |
| `wp uams-search query "<terms>" [--site=<id>] [--explain]` | Run a search; `--explain` shows each column's score |

**Logging and privacy**

- Indexing errors go to the index table and to the PHP error log, prefixed `UAMS Search:`.
- The no-results log (phase 2) stores only the search text, the date and the sub-site. No IP address or user ID, and entries older than 90 days are deleted. Patients may type health terms, so we keep only what we need to improve search.

## Migration and testing

SearchWP stays on until the new plugin matches it on a fixed list of test searches, one install at a time, starting with uamshealth.com.

**Rollout**

1. **Spike on Local:** confirm full-text behavior on BigScoots's database version, the short-word rule, and whether `pdftotext` is available.
2. **Build phase 1 on Local** against a copy of uamshealth.com.
3. **Side-by-side on staging:** run both plugins. A `?engine=uams` URL parameter, for logged-in users only, shows the new results, so the two can be compared page by page.
4. **Switch uamshealth.com:** turn on the new plugin, turn off SearchWP, and watch the status page and no-results log for two weeks.
5. **Roll out to the other installs,** largest last (medicine.uams.edu, healthprofessions.uams.edu), with a full WP-CLI index before each switch.
6. **Remove SearchWP** and the UAMSWP SearchWP Customizations plugin everywhere.

**Test searches** (a shared list, about 50, with the expected top results for each)

- Provider names, including partial and misspelled names
- Conditions and treatments ("breast cancer", "knee replacement")
- Short terms (GI, ENT, MRI)
- Locations and clinic names
- Common pages (parking, billing, MyChart, careers)
- Words that only appear in ACF fields, and (phase 2) only inside PDFs

**Automated tests**

- PHPUnit tests for the tokenizer, stemmer, short-word encoding and excerpt highlighting
- An integration test that indexes sample posts, including a post with an object-valued meta field, and checks the test searches
- axe checks of the results page on each release

## Risks and open questions

The biggest risk is relevance: SearchWP has years of tuning, and ours starts from guessed weights. The test-search list and the `--explain` command are how we close that gap.

| Risk | Mitigation |
| --- | --- |
| Results rank worse than SearchWP's | Side-by-side testing before each switch; editable weights; `--explain` to see why a result ranked where it did |
| MariaDB full-text quirks (stop words, two-letter words, InnoDB ranking) | Phase 1 spike on BigScoots's database version; our own stop words and short-word encoding in PHP |
| No server cron on BigScoots | WP-Cron fallback with smaller batches; ask BigScoots before phase 1 ships |
| Large installs slow to rebuild | Per-sub-site WP-CLI rebuilds into a staging copy, swapped in when done |
| PDF extraction uses too much memory | Size and time limits; prefer `pdftotext`; extraction only in the queue |
| Effort spent on a plugin that Statamic replaces | Phase 1 only covers what SearchWP did; phases 2 and 3 go ahead only if the Statamic timeline leaves room |

**Open questions**

- [x] Database: MariaDB, `innodb_ft_min_token_size = 3` (confirmed 2026-10-29). Version 10.6.27.
- [ ] Can BigScoots add a server cron job that runs WP-CLI every 5 minutes?
- [ ] Is `pdftotext` installed, and may PHP run it (`exec` / `proc_open`)?
- [ ] Which sub-sites on each install belong in whole-install search, and what default scope does each want?
- [ ] Who writes and keeps the test-search list (content owners, Find-a-Doc team)?
- [ ] Plugin name and repo, for example `UAMSWP-Search` under UAMS-Web on GitHub.
- [ ] When do the first installs move to Statamic? This decides whether phases 2 and 3 are worth building.

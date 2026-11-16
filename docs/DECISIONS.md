# Decision log

The canonical record of why the project is the way it is: the reasoning and the rejected alternatives behind decisions that have closed. It guards against silently reopening a settled decision.

**Consult it before acting.** Before proposing a change, reopening a question or picking an approach, check here first. The path under consideration may already be a decided-and-recorded one, or an explicitly rejected one. If it is, follow the record, or make the case to revisit it.

**When a decision closes, record the verdict in both places.** Capture the what, the why and the rejected alternatives here (the cross-ticket index) and in the GitHub issue's `## Decision` section (the per-ticket record), keeping the two consistent. Put the full reasoning in the relevant `docs/` companion when the decision touches a documented area. The issue tracker stays the system of record for a single ticket's status.

## How to add an entry

Entries run oldest first, one per closed decision, so a new entry is appended to the end of the file. Keep it terse; the companion or the issue carries the depth.

Every entry heading is `## YYYY-MM-DD`, a space, and a short title. Leave exactly one blank line above each heading. The check (`decision-log` skill) fails on a heading dated earlier than the one before it, and on a heading that sits directly under the previous line.

```markdown
## YYYY-MM-DD <short decision title> (<#issue or area>)

**Decision.** <what was decided, in a sentence or two>

**Why.** <the reasoning that settled it>

**Rejected.** <the alternatives considered, and why each lost>

**Companion.** <link to the docs/ companion or the GitHub issue holding the full reasoning>
```


## 2026-10-29 Search with the database's full-text indexes, not an external service (spec)

**Decision.** Search runs on the database's built-in full-text indexes with no outside service. Searching across installs stays with Google Custom Search.

**Why.** The plugin replaces SearchWP on the installs, favors simple code the team can debug over new search technology, and is a bridge until sites move to Statamic. The spec names the goals: no annual license, code the team owns, and one less third-party plugin per install.

**Rejected.** External search services such as Elasticsearch, Meilisearch or Algolia: listed as out of scope in the spec, because they add an outside dependency that the replacement exists to remove.

**Companion.** [`docs/SPEC.md`](https://github.com/UAMS-Web/uamswp-search/blob/main/docs/SPEC.md) ("Summary and goals", "Out of scope").

## 2026-10-29 Use a plugin-owned empty stopword table, not the session toggle (spec)

**Decision.** The plugin creates its own empty InnoDB stopword table and sets the session's `innodb_ft_user_stopword_table` to it only while the full-text indexes are created. It restores the prior session value and never changes global server settings.

**Why.** The spec's `SET SESSION innodb_ft_enable_stopword=OFF` does not apply to MariaDB, where that variable is global, and the plugin cannot change server settings on the host.

**Rejected.** The session toggle in the spec: unsupported on MariaDB. A global server setting: not the plugin's to change.

**Companion.** [`README.md`](https://github.com/UAMS-Web/uamswp-search/blob/main/README.md) ("Database choices"); [`docs/VERIFICATION.md`](https://github.com/UAMS-Web/uamswp-search/blob/main/docs/VERIFICATION.md) ("Spec adjustments").

## 2026-10-29 Rebuild a site with a transactional per-site copy, not a table rename (spec)

**Decision.** A rebuild replaces one site's rows with a transactional per-site copy.

**Why.** All sub-sites share one live index table, so a rename would replace every site's rows at once.

**Rejected.** Swapping a staging table in by rename, as the spec's rebuild step describes.

**Companion.** [`docs/VERIFICATION.md`](https://github.com/UAMS-Web/uamswp-search/blob/main/docs/VERIFICATION.md) ("Spec adjustments").

## 2026-10-29 Render results with the supplied Genesis template (spec)

**Decision.** While this engine is selected, the supplied Genesis template renders results. It keeps the optional Google block and shows a unified list instead of the old cards.

**Why.** The record states the replacement but no further reason; the spec's integration returns results through `posts_pre_query`, so the theme no longer runs its own secondary query.

**Rejected.** The older reference theme's secondary-query loop and its cards.

**Companion.** [`docs/VERIFICATION.md`](https://github.com/UAMS-Web/uamswp-search/blob/main/docs/VERIFICATION.md) ("Spec adjustments"); [`docs/SPEC.md`](https://github.com/UAMS-Web/uamswp-search/blob/main/docs/SPEC.md).

## 2026-10-29 Store per-field weights as stemmed JSON with a combined full-text column (spec)

**Decision.** Per-field weights use stemmed text stored as JSON in `weighted_data`, and a combined `search_stem` full-text column lets required words come from different sources. `JSON_TABLE` reads the JSON only when a weight differs from 1 or a field-list filter is active.

**Why.** Individual source indexes supply relevance scores, but required words may appear in different sources, so a combined column is needed. The target MariaDB version supports `JSON_TABLE`.

**Rejected.** Separate full-text indexes only: a query whose words are spread across sources would not match.

**Companion.** [`README.md`](https://github.com/UAMS-Web/uamswp-search/blob/main/README.md) ("Database choices"); [`docs/VERIFICATION.md`](https://github.com/UAMS-Web/uamswp-search/blob/main/docs/VERIFICATION.md) ("Spec adjustments").

## 2026-10-29 Rank spelling suggestions by document frequency with bounded candidate lists (spec)

**Decision.** The suggestion dictionary uses document frequency, stores at most 10,000 distinct words per object and considers at most 500 spelling candidates of similar length. Corrections within two edits take priority over phonetic-only candidates, then frequency decides.

**Why.** The limits bound background work and suggestion cost, and the priority avoids collisions between long names with identical sound-key prefixes.

**Rejected.** Phonetic matching alone: it collides on long names with the same sound-key prefix. Unbounded candidate lists: unbounded background and suggestion cost, at the price of missing some corrections for rare words.

**Companion.** [`README.md`](https://github.com/UAMS-Web/uamswp-search/blob/main/README.md) ("Database choices"); [`docs/VERIFICATION.md`](https://github.com/UAMS-Web/uamswp-search/blob/main/docs/VERIFICATION.md) ("Spec adjustments").

## 2026-10-29 Bundle smalot/pdfparser as a namespaced copy under `lib/pdfparser`, not through Composer (#26)

**Decision.** The PDF text fallback is smalot/pdfparser 2.13.1, pinned to a commit and vendored by `scripts/bundle-pdfparser.py` from the official archive. Its namespace is renamed from `Smalot\PdfParser` to `UAMS_Search_Vendor\PdfParser`, and it runs only in a child PHP process with time, memory and output limits. The copy first went into `vendor/pdfparser` and moved to `lib/pdfparser` in [#53](https://github.com/UAMS-Web/uamswp-search/pull/53), so `/vendor/` can be ignored whole.

**Why.** The renamed namespace avoids collisions with other plugins that load the same library, and the copy is pinned to a commit with its license and a modification record. The upload ZIP carries only the keep-list, so Composer's `vendor/` is not part of what ships.

**Rejected.** A Composer runtime dependency, as the spec said: it would load under the upstream namespace and `vendor/` is not shipped. Keeping the copy in `vendor/`: it blocked ignoring `/vendor/` whole.

**Companion.** [`THIRD-PARTY.md`](https://github.com/UAMS-Web/uamswp-search/blob/main/THIRD-PARTY.md); [#26](https://github.com/UAMS-Web/uamswp-search/issues/26).


## 2026-11-11 Attach release ZIPs to GitHub releases and drop `dist/` (#24)

**Decision.** The upload ZIP is attached to each GitHub release and is no longer committed to `dist/`. `release.yml` builds it from the keep-list in `.gitattributes` when a release is published, with `scripts/build-zip.mjs`. The release step also writes and attaches a `.sha256` file beside it.

**Why.** One list decides what ships, so the release asset and the GitHub download cannot differ. The committed `dist/uams-search-0.5.1.zip` already lacked the `Plugin URI` header that the `v0.5.1` tag carries, and each committed ZIP added a binary to the history.

**Rejected.** Keep committing to `dist/`: the committed archive drifts from the tag and the history grows by a binary per release. Rely on the bare GitHub download: it offers installers no separate ZIP asset and gives up the checksum.

**Companion.** [#24](https://github.com/UAMS-Web/uamswp-search/issues/24); the build is [#111](https://github.com/UAMS-Web/uamswp-search/issues/111).

## 2026-11-15 Copy linked post titles and term names with dependency markers and a state check on requeue (#162)

**Decision.** A top-level relationship, post object, taxonomy or ACFE taxonomy terms field copies the title of each searchable linked post (never an attachment) and the name of each linked term as its own weighted entry that records the linked ID beside the text, as `Related` does for providers. The queue requeues a page that holds an ID only when its copy no longer matches the live post or term: the exact `linked_post_id` and title (or `linked_term_id` and name) fragment, compared with a binary `LIKE`, decides. The query hides a page whose copied title or name no longer matches a live published, unprotected, non-excluded post or an existing term. `FieldText::VERSION` is 2.

**Why.** The state check is what keeps two pages that link to each other from requeueing each other without end; `Related` avoids the loop only because providers do not link back. It also means a save that leaves a title unchanged queues no dependent page. A term referenced only through field meta is reached through its ID marker, not through `term_relationships`.

**Rejected.** Requeueing every page that holds the ID on every change: a cycle of two linking pages never settles. Copying the titles of attachments: the query rechecks no attachment visibility for a copied title, so a hidden attachment would hide the page for good. Following the same fields inside blocks and repeaters: out of the ticket's scope, so those still add nothing.

**Companion.** [#162](https://github.com/UAMS-Web/uamswp-search/issues/162); [#159](https://github.com/UAMS-Web/uamswp-search/issues/159).

## 2026-11-15 Default the Google Custom Search block to off until launch, and leave the script to a site-layer Content Security Policy (#16)

**Decision.** The Google block, which loads `cse.google.com/cse.js`, stays a supported feature but `uams_search_google` defaults to off until launch; turning it on is a launch-checklist decision. A Content Security Policy at the site layer (theme or server headers) is the durable control and its ticket blocks launch. Searching across installs stays with Google Custom Search, as an earlier entry in this log records. The default-off change is [#269](https://github.com/UAMS-Web/uamswp-search/issues/269); the policy is [UAMS-Web/uams-2020#845](https://github.com/UAMS-Web/uams-2020/issues/845).

**Why.** `cse.js` is unversioned, changes without notice and loads further scripts from Google's own hosts, so it cannot carry Subresource Integrity and the plugin can only control whether it loads. The plugin is not in production yet, so defaulting off removes any exposure before launch at no cost. A Content Security Policy covers every third-party script, not only this one, but it only partly helps here, since the policy has to allow the hosts `cse.js` loads from.

**Rejected.** Recording an accepted risk now: nothing is exposed yet, so there is no risk to accept. Removing the block: it loses the cross-install results that stay with Google.

**Companion.** [#16](https://github.com/UAMS-Web/uamswp-search/issues/16).

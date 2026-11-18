Written against v0.6.1.

Every setting UAMSWP Search reads, where it is changed, its default, and whether changing it needs a rebuild. The network settings are stored in the `uams_search_settings` site option, and the `uams_search_config` filter can change any of them in code; see [Filters and PHP API](Filters-and-PHP-API.md).

## Network settings

Under **Network Admin > UAMSWP Search > Settings** (on a single site, **Settings > UAMSWP Search Settings**).

| Setting | Key | Default | Effect | Rebuild |
| --- | --- | --- | --- | --- |
| Use UAMSWP Search for Visitors | `enabled` | off | Answers visitors' searches. While off, only signed-in previews use the engine. | no |
| Show Live Search Suggestions | `live` | on | Shows suggestions as visitors type. | no |
| Keep Search Text With No Results for up to 90 Days | `logging` | on | Logs searches that found nothing; see [Privacy](Privacy.md). | no |
| Index PDFs | `pdf` | on | Extracts PDF text; see [PDFs](PDFs.md). | yes |
| Link PDFs to Their Parent Page When Available | `pdf_parent` | off | Points a PDF result at the page it is attached to. | yes |
| Results per Page | `results_per_page` | 20 | 1 to 100. An explicit page size in an API or WordPress query overrides it. | no |
| Sub-Sites in Whole-Website Search | `sites` | every eligible site | The sites searched by the install-wide scope. | no |
| Source Weights | `weights` | title 5, terms 3, fields 2, content 1, pdf 1 | How much a match in each source counts. | no |
| Content To Index: Include This Content Type | `types` | provider 1.5, expertise 1.3, location 1.2, condition 1.0, treatment 1.0, page 1.0, post 0.8, attachment 0.8 | Which post types are indexed, and each type's weight. | yes to add or remove a type; no for its weight |
| Content To Index: fields and taxonomies | `fields`, `taxonomies` | see [Find-a-Doc content](Find-a-Doc-content.md) | Which fields and terms are read, each with a weight. | yes |
| Extra Stop Words, Separated by Spaces | `stopwords` | none | Words left out of the index. | yes |
| Synonyms, One Rule per Line | `synonyms` | none | Rules such as `heart = cardiac, cardiology`. | no |

A field's weight is stored when an item is indexed, so changing it needs a rebuild. Source and content-type weights apply at query time.

Keys with no control on the screen, changed only through `uams_search_config`:

| Key | Default | Effect |
| --- | --- | --- |
| `related_provider_weight` | 0.25 | Weight of provider names copied onto related pages. |
| `pdf_max_mb` | 20 | Largest PDF read, in megabytes. |
| `pdf_timeout` | 30 | Seconds allowed for one PDF's extraction. |
| `extract_warn_seconds` | 5 | Extraction time above which a `UAMS Search: slow extraction` line is written to the PHP error log. |
| `phrase_bonus` | 5 | Extra score for an exact phrase match. |
| `recency` | 0.15 | Extra score for a recent news post (`post` type), shrinking with each year since it was published. |
| `preview_noindex` | on in `local`, `development` and `staging` environments | Default for the signed-in preview checkbox below. |

## Signed-in previews

**Include No-Index Sites for Signed-In Previews** sits beside **Rebuild Index** on the **Index** screen. It lets healthy sub-sites marked no-index be indexed so signed-in users can preview them. It defaults on in `local`, `development` and `staging` environments and can be ticked on a staging host with any environment label. It does not change any site's search-engine visibility, and anonymous visitors keep the normal visibility rule.

## Per-site settings

Under each site's **Settings > UAMSWP Search**, or **UAMS Settings > Search** where that menu exists.

- **Default Search Scope:** **This site**, **All of** the main site's name (on a sub-site of a subdirectory network), or **All UAMS websites** (the Google block). On the main site of a subdirectory network, **This site** searches the whole install.
- **Show the Existing All of UAMS Google Search** (`uams_search_google`): off by default. The block loads Google's Custom Search script, which cannot carry Subresource Integrity, so it prints nothing and the All UAMS websites scope is not offered until this is ticked or the `uams_search_google_enabled` filter returns true. Turning it on is a launch decision, recorded in the [decision log](https://github.com/UAMS-Web/uamswp-search/blob/main/docs/DECISIONS.md) (2026-11-15).

## PDF attachments

Each PDF in the media library has a **Search Visibility** field with an **Exclude From Search** checkbox, for anyone who can edit the attachment.

## Reference

- [Rebuilding the index](Rebuilding-the-index.md)
- [Filters and PHP API](Filters-and-PHP-API.md)

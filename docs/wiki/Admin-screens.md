Written against v0.6.1.

UAMSWP Search adds three screens under **Network Admin > UAMSWP Search**, and one per-site screen. By the end of this page you will know which one to open for each job.

On a single-site install the three network screens are under **Settings** instead: **UAMSWP Search Index**, **UAMSWP Search Reports** and **UAMSWP Search Settings**.

## Index

Rebuild the index and read its status. The **Rebuild index** button runs the rebuild in the browser, and **Index Status** shows the counts per site. The **Include No-Index Sites for Signed-In Previews** checkbox sits beside the button; see [Rebuilding the index](Rebuilding-the-index.md).

## Reports

- **Failed Items and PDFs Without Text:** each item that failed to index, with its message and a retry link, and each PDF that indexed by title and file name only.
- **Searches With No Results, Last 30 Days:** the search text visitors typed that found nothing. See [Privacy](Privacy.md) for what is stored.

## Settings

The network settings: the visitor switch, live suggestions, logging, PDFs, results per page, the sub-sites in whole-website search, weights, the content to index, stop words and synonyms. Every setting is listed in [Settings](Settings.md).

## Per-site scope

Each site's own settings are under **Settings > UAMSWP Search**, and also under **UAMS Settings > Search** when that top-level menu exists. This is where a site chooses its **Default Search Scope** and whether to show the existing All of UAMS Google search.

## Next

- [Rebuilding the index](Rebuilding-the-index.md)
- [Settings](Settings.md)

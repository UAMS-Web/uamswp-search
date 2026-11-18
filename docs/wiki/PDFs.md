Written against v0.6.1.

How PDF text is extracted, and the limits that keep extraction from slowing a site. PDFs are indexed when **Index PDFs** is on ([Settings](Settings.md)).

## Extraction

- PDFs are read from local files within the uploads folder. File size and modified date decide whether text extracted earlier is reused.
- Poppler's `pdftotext` is preferred. When it is missing or fails within the time budget, the bundled PHP parser (smalot/pdfparser 2.13.1, namespaced so it cannot collide with another plugin's copy) runs in a child process with a 128 MB memory limit.
- Both extractors share one time budget, and the parent kills a process that runs past it.
- Extraction needs `proc_open`, and either `pdftotext` or a PHP CLI. Use `uams_search_pdftotext` or `uams_search_php_binary` when they are outside the `PATH` ([Filters and PHP API](Filters-and-PHP-API.md)). `wp uams-search doctor` reports `proc_open` and whether `pdftotext` is on the `PATH`; it does not apply either filter or test the PHP CLI the fallback uses.

## Limits

| Limit | Default | Key |
| --- | --- | --- |
| Largest PDF read | 20 MB (1 to 100) | **Largest PDF, MB** |
| Time per PDF | 30 seconds (1 to 120) | **PDF Time Limit, Seconds** |
| Extractor output per PDF | 16 MB; more fails the PDF | fixed |
| Memory for the PHP parser | 128 MB | fixed |

When safe background extraction is unavailable, the item records an error instead of parsing during a visitor's request.

**Warning:** a PDF whose extraction fails (too large, timed out, too much output, no extractor) becomes an error row and drops out of search entirely, title included, until it is fixed and retried. Only a PDF that extracts with no text keeps its title and file name matches.

## PDFs without text

A scanned PDF with no extractable text keeps its title and file name matches and is listed under **Reports > Failed Items and PDFs Without Text**.

## Excluding a PDF

Tick **Exclude From Search** in the PDF's **Search Visibility** field in the media library.

## Third-party code

The parser's license, source, original package manifest, archive checksum and namespace changes are in [THIRD-PARTY.md](https://github.com/UAMS-Web/uamswp-search/blob/main/THIRD-PARTY.md).

## Reference

- [Troubleshooting](Troubleshooting.md#pdf-messages)
- [Settings](Settings.md)

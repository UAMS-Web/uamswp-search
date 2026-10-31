# Third-party source

`lib/pdfparser` contains smalot/pdfparser **v2.13.1**, commit `a71c6dd38e60a995fdb4d36e4433b8b3557c65dc`.

- Upstream: https://github.com/smalot/pdfparser
- Official archive: https://api.github.com/repos/smalot/pdfparser/zipball/a71c6dd38e60a995fdb4d36e4433b8b3557c65dc
- License: LGPL-3.0, included as `lib/pdfparser/LICENSE.txt`.
- Change: `Smalot\PdfParser` was renamed to `UAMS_Search_Vendor\PdfParser`, including dynamic class-name strings, to avoid collisions with other plugins.
- Native mbstring, iconv and zlib are required. No Symfony polyfill is bundled.
- Used only in a child PHP process with time, memory, and output limits.
- Reproduction: download the official pinned ZIP, then run `python3 scripts/bundle-pdfparser.py /path/to/archive.zip` and the PDF tests. The bundled modification record contains the downloaded archive's SHA-256 checksum.

The plugin's stemmer and phonetic code implement published Porter and Double Metaphone rules. The root `LICENSE` contains GPL v3. The distribution includes the LGPL source and license so recipients can inspect and replace the PDF library. Developer tools are locked in `composer.lock` and `package-lock.json` and excluded from the WordPress upload ZIP.

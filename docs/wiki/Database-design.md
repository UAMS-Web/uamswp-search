Written against v0.6.1.

How the index uses the database's full-text search, for anyone debugging a ranking or checking a host. The reasoning and rejected alternatives behind each choice are in the [decision log](https://github.com/UAMS-Web/uamswp-search/blob/main/docs/DECISIONS.md).

## Requirements

InnoDB with `innodb_ft_min_token_size=3`. MariaDB 10.6.27 is the target; the verification runs so far were on MySQL. Run `wp uams-search doctor` to check a host.

## Stopwords

The spec's `SET SESSION innodb_ft_enable_stopword=OFF` does not apply to MariaDB, where that variable is global. Instead the plugin creates its own empty InnoDB stopword table and sets the session's `innodb_ft_user_stopword_table` to it only while it creates the indexes. It restores the prior session value and never changes a global server setting. [MariaDB's variable reference](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-system-variables) documents the distinction. The integration suite must confirm the behavior on the target host.

## Words in different sources

Each source (title, terms, fields, content, PDF) has its own full-text index, which supplies its relevance score. A combined `search_stem` column and its index let the required words of a search appear in different sources. A boundary token stops a quoted phrase from spanning two sources.

## Per-field weights

`weighted_data` holds each field's stemmed text so a field can carry its own weight. `JSON_TABLE` is used when those weights differ from 1, when a field-list filter is active, or when `related_provider_weight` is not 1. That weight defaults to 0.25, so with default settings every query uses it. The target MariaDB version supports it.

## Short words

Stems shorter than the minimum token size are encoded as `uamsshort`, the hexadecimal bytes, and `zz`, on both the indexing and the searching side. Encoding is applied after stemming, so a longer word whose stem is two letters is not lost. Three-letter words such as ENT are left as they are.

## Spelling suggestions

- The dictionary uses document frequency, stores at most 10,000 distinct words per object, and considers at most 500 candidates of similar length. These limits bound background work and suggestion cost, and can limit corrections for rare words.
- Corrections within two edits take priority over phonetic-only candidates; frequency then decides. This avoids collisions between long names that share a sound-key prefix.
- A correction is shown only after an all-words query succeeds in the requested scope and type.

## Rebuilds

All sub-sites share one live index table, so a rebuild fills a staging table and replaces one site's rows in a transaction rather than renaming a table; see [Rebuilding the index](Rebuilding-the-index.md).

## Reference

- [Settings](Settings.md)
- [WP-CLI commands](WP-CLI-commands.md#query)

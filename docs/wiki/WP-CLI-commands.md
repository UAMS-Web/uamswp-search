Written against v0.6.1.

The `wp uams-search` commands, for hosts that give command-line access. Nothing here is required: the browser rebuild and WP-Cron cover the same work. Stored dates are UTC; dates printed by these commands use the site's timezone and formats.

## index

Rebuild a site's index while existing search stays available.

| Option | Default | Effect |
| --- | --- | --- |
| `--site=<id>` | the current site | The site to rebuild. |
| `--post-type=<type>` | every configured type | Rebuild only this post type. It does not clear the rebuild warning. |
| `--all` | off | Rebuild every eligible site. |

```sh
wp uams-search index --site=1
wp uams-search index --site=1 --post-type=provider
wp uams-search index --all
```

It refuses to run while a browser rebuild is active; resume or cancel that rebuild first. See [Rebuilding the index](Rebuilding-the-index.md).

## process-queue

Process queued edits within a count and time budget.

| Option | Default | Effect |
| --- | --- | --- |
| `--limit=<n>` | 100 | The most queue entries to process. |
| `--seconds=<n>` | 45 | The time budget, held between 1 and 240. |

```sh
wp uams-search process-queue --limit=100 --seconds=45
```

See [Running the queue from server cron](Running-the-queue-from-server-cron.md).

## status

Print the index, queue and failure counts.

```sh
wp uams-search status
```

## reindex

Reindex one object and print its extracted fields.

| Option | Effect |
| --- | --- |
| `<post-id>` | The post to reindex. |
| `--site=<id>` | The site it belongs to. Required. |

```sh
wp uams-search reindex 123 --site=1
```

## query

Search, and optionally explain each column's score.

| Option | Default | Effect |
| --- | --- | --- |
| `<terms>` | | The search text. |
| `--site=<id>` | the current site | The site to search from. |
| `--scope=<scope>` | `site` | `site` or `install`. On the main site of a subdirectory network, `site` is not offered and the search covers the whole install. |
| `--type=<type>` | every type | Only results of this post type. |
| `--explain` | off | Print each column's contribution to the score. |

```sh
wp uams-search query "GI" --site=1 --explain
wp uams-search query "heart care" --scope=install --explain
```

## doctor

Check the database's capabilities and the PDF extraction tools.

```sh
wp uams-search doctor
```

## Reference

- [Troubleshooting](Troubleshooting.md)
- [Settings](Settings.md)

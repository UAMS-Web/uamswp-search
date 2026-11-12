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


## 2026-11-11 Attach release ZIPs to GitHub releases and drop `dist/` (#24)

**Decision.** The upload ZIP is attached to each GitHub release and is no longer committed to `dist/`. `release.yml` builds it from the keep-list in `.gitattributes` when a release is published, with `scripts/build-zip.mjs`. The release step also writes and attaches a `.sha256` file beside it.

**Why.** One list decides what ships, so the release asset and the GitHub download cannot differ. The committed `dist/uams-search-0.5.1.zip` already lacked the `Plugin URI` header that the `v0.5.1` tag carries, and each committed ZIP added a binary to the history.

**Rejected.** Keep committing to `dist/`: the committed archive drifts from the tag and the history grows by a binary per release. Rely on the bare GitHub download: it offers installers no separate ZIP asset and gives up the checksum.

**Companion.** [#24](https://github.com/UAMS-Web/uamswp-search/issues/24); the build is [#111](https://github.com/UAMS-Web/uamswp-search/issues/111).

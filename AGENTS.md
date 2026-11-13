# Agent instructions for uamswp-search

This file is the single source of agent instructions for this repository. Claude Code, Cursor and Codex all read it directly; there is no `CLAUDE.md`, because Claude Code reads `AGENTS.md` only when `CLAUDE.md` is absent. Put instructions here, never in a `CLAUDE.md`.

Everything above the generated section below belongs to this repository and is edited here. The generated section is rewritten by the sync from `UAMS-Web/uams-claude-skills`; edit the shared skills and rules there.

## About this repository

`uams search` is a network-activated WordPress multisite plugin that replaces SearchWP on the UAMS installs. It indexes posts, fields and PDF text into its own tables, searches them with InnoDB full-text indexes, and calls no external search service; the one exception is the optional Google "All UAMS websites" block, which loads Google's script on the results page. Runtime code is `uams-search.php`, `includes/`, `assets/`, `templates/`, `lib/pdfparser/` and `scripts/pdf-worker.php`, which is exactly what the `.gitattributes` keep-list puts in the download. `README.md` holds the operator view: install, settings, requirements, database choices and release gates.

### Development install

Check the plugin out in `wp-content/plugins/` of a local WordPress install, multisite for anything that touches network settings or more than one site, and network-activate it. The database needs `innodb_ft_min_token_size=3`, and the plugin needs PHP 8.2 or later. `composer install` installs Pest, PHPStan, Pint and Rector into the ignored `vendor/`; the plugin itself loads nothing from it. `npm ci` and `npx playwright install chromium` set up the browser suites.

In Amp orbs, `.agents/setup` installs PHP 8.2, Composer, Poppler, the locked PHP and npm development dependencies, and Playwright's Chromium with its system libraries; Node.js and npm come from the orb base image. It is safe to rerun after a lockfile changes. `.agents/resume` only checks that the tools and dependencies are present and never installs anything. Neither one provisions a WordPress install or a database.

### Test suites

- **Unit:** `composer test` runs Pest over `tests/*Test.php`, with WordPress stand-ins from `tests/bootstrap.php` and real child processes and PDF fixtures for extraction. It needs no WordPress, no database and no secrets. `python3 scripts/make-fixtures.py` regenerates the small test PDFs.
- **Browser components:** `npm run test:browser`, `test:rebuild-browser`, `test:provider-browser` and `test:location-browser` render each component with fake data and drive it in Chromium, with axe. `test:browser` overwrites the tracked screenshot `docs/search-preview.png`; do not commit it unless the change is meant to update it. These do not replace the axe and screen-reader checks on the real theme.
- **WordPress integration:** PHPUnit 9.6 over wp-phpunit in `tests/Integration/`, which has its own `composer.json`. Run `composer setup:integration` once, then `composer test:integration`; set `WP_MULTISITE=1` for the multisite run. `WP_TESTS_ABSPATH` defaults to the WordPress install the checkout sits in, and `WP_TESTS_DB_NAME` (with `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD` and `WP_TESTS_DB_HOST`) defaults to `uams_search_test` as passwordless `root` on `127.0.0.1`. **The test installer drops every table in that database on each run.** Create a database used for nothing else; `tests/Integration/bootstrap.php` refuses to run when the name matches the `DB_NAME` in that install's `wp-config.php`, and that is the only guard. CI runs single site and multisite on MariaDB 10.6 and MySQL 8.0.
- **Static checks and the build:** `composer stan`, `composer test:pint` and `composer test:refactor`; `composer build` writes the keep-list ZIP and its `.sha256` to the ignored `build/`.

### The bundled PDF parser

`lib/pdfparser/` is a tracked copy of smalot/pdfparser 2.13.1 (commit `a71c6dd38e60a995fdb4d36e4433b8b3557c65dc`) with every namespace changed from `Smalot\PdfParser` to `UAMS_Search_Vendor\PdfParser`, so it cannot collide with another plugin's copy. `includes/autoload.php` loads it; Composer does not, so never add `smalot/pdfparser` to `composer.json`. It runs only in the child process `scripts/pdf-worker.php` starts, as the fallback when Poppler's `pdftotext` is missing or fails within the time budget. To update it, download the upstream release archive for the pinned commit and run `python3 scripts/bundle-pdfparser.py <archive.zip>`: the script refuses an archive whose top folder does not match the pinned commit, replaces `lib/pdfparser/src/`, and rewrites `lib/pdfparser/UAMS-MODIFICATIONS.md` with the archive's SHA-256. Change `VERSION` and `COMMIT` in the script first, then update `THIRD-PARTY.md` to match. Why it is bundled this way, and what was rejected, is in `docs/DECISIONS.md`.

<!-- uams-claude-skills:begin -->
## Shared skills and rules

The files listed here are synced from [UAMS-Web/uams-claude-skills](https://github.com/UAMS-Web/uams-claude-skills) (`shared/`) and are edited there, never here; a sync pull request brings changes into this repository. This section is generated; everything outside it belongs to this repository.

Each rule below is a standing instruction for every task in this repository. Its full text is delivered three times: `.claude/rules/<name>.md`, which Claude Code loads in every session; `.cursor/rules/<name>.mdc`, which Cursor always applies; and the skill `.agents/skills/rule-<name>/`, which Codex selects by its description. Before work a rule governs, read its full text. Skills are in `.claude/skills/` and, as the same files, in `.agents/skills/`, and load when their description matches the task.

Profiles for this repository: core, php, pest, wordpress-plugin, tests, quality.

### Contributing

Follow [`CONTRIBUTING.md`](CONTRIBUTING.md) for the issue-to-merge path: a branch from `main` linked to the issue and a pull request to `main`. It also maps each branch to the environment its pull request's test plan is run on. Start each pull request body from [`.github/pull_request_template.md`](.github/pull_request_template.md).

### Rules

- `adversarial-review`: Adversarially verify before it reaches anyone else. Full text: [`.claude/rules/adversarial-review.md`](.claude/rules/adversarial-review.md).
- `american-english-prose`: American English for prose, and a dictionary entry that overrides it needs a stated reason. Full text: [`.claude/rules/american-english-prose.md`](.claude/rules/american-english-prose.md).
- `an-empty-result-is-not-evidence`: A result is not evidence until the instrument has been shown to tell the two answers apart. Full text: [`.claude/rules/an-empty-result-is-not-evidence.md`](.claude/rules/an-empty-result-is-not-evidence.md).
- `closing-a-ticket`: Finish a ticket where it is visible, not just where it builds. Full text: [`.claude/rules/closing-a-ticket.md`](.claude/rules/closing-a-ticket.md).
- `coordination-plumbing-stays-out-of-artifacts`: Keep session-coordination plumbing out of GitHub artifacts and documentation. Full text: [`.claude/rules/coordination-plumbing-stays-out-of-artifacts.md`](.claude/rules/coordination-plumbing-stays-out-of-artifacts.md).
- `cutting-releases`: Cut a release each business day at 10:00 Central when there is unreleased work. Full text: [`.claude/rules/cutting-releases.md`](.claude/rules/cutting-releases.md).
- `design-decision-forks`: Presenting design-decision forks (lead with a compare/contrast). Full text: [`.claude/rules/design-decision-forks.md`](.claude/rules/design-decision-forks.md).
- `filing-defects-across-repos`: File a defect in the repository that owns it, and claim it first when a race is likely. Full text: [`.claude/rules/filing-defects-across-repos.md`](.claude/rules/filing-defects-across-repos.md).
- `github-api-budget`: Spend the REST quota to preserve the GraphQL one. Full text: [`.claude/rules/github-api-budget.md`](.claude/rules/github-api-budget.md).
- `impersonal-voice-in-github-artifacts`: Write GitHub artifacts in an impersonal voice. Full text: [`.claude/rules/impersonal-voice-in-github-artifacts.md`](.claude/rules/impersonal-voice-in-github-artifacts.md).
- `keep-the-download-to-what-runs`: Keep a WordPress download to what the plugin runs. Full text: [`.claude/rules/keep-the-download-to-what-runs.md`](.claude/rules/keep-the-download-to-what-runs.md).
- `long-running-commands`: Bound every long-running command, and resolve what you kill. Full text: [`.claude/rules/long-running-commands.md`](.claude/rules/long-running-commands.md).
- `measurement-parity`: Mirror the *job*, not the command, before comparing to a baseline. Full text: [`.claude/rules/measurement-parity.md`](.claude/rules/measurement-parity.md).
- `no-em-dashes`: No em dashes in prose, docs, comments, or strings. Full text: [`.claude/rules/no-em-dashes.md`](.claude/rules/no-em-dashes.md).
- `no-emoji-in-durable-records`: No emoji in durable records. Full text: [`.claude/rules/no-emoji-in-durable-records.md`](.claude/rules/no-emoji-in-durable-records.md).
- `pre-merge-check`: Re-verify at the merge, not only at the build. Full text: [`.claude/rules/pre-merge-check.md`](.claude/rules/pre-merge-check.md).
- `reading-exit-status`: Read the result, not the exit code. Full text: [`.claude/rules/reading-exit-status.md`](.claude/rules/reading-exit-status.md).
- `supported-platforms`: MacOS and Windows are both supported; name the platform, write paths repo-relative. Full text: [`.claude/rules/supported-platforms.md`](.claude/rules/supported-platforms.md).
- `sync-pr-branch`: Sync every PR branch from `origin/main` before the gating run. Full text: [`.claude/rules/sync-pr-branch.md`](.claude/rules/sync-pr-branch.md).
- `verify-claims-before-a-third-party-post`: Verify every claim three independent ways before a post to a third-party tracker. Full text: [`.claude/rules/verify-claims-before-a-third-party-post.md`](.claude/rules/verify-claims-before-a-third-party-post.md).
- `worktrees`: Work from stable, pre-bootstrapped worktree slots (branch work never happens in the primary checkout). Full text: [`.claude/rules/worktrees.md`](.claude/rules/worktrees.md).

Skills: [`code-quality`](.claude/skills/code-quality/SKILL.md), [`decision-log`](.claude/skills/decision-log/SKILL.md), [`download-archive-check`](.claude/skills/download-archive-check/SKILL.md), [`evidence-behind-rules`](.claude/skills/evidence-behind-rules/SKILL.md), [`github-wiki`](.claude/skills/github-wiki/SKILL.md), [`managing-linked-stacks`](.claude/skills/managing-linked-stacks/SKILL.md), [`pcov-setup`](.claude/skills/pcov-setup/SKILL.md), [`pest-testing`](.claude/skills/pest-testing/SKILL.md), [`php-coding-standards`](.claude/skills/php-coding-standards/SKILL.md), [`php-documentation`](.claude/skills/php-documentation/SKILL.md), [`phpunit-to-pest`](.claude/skills/phpunit-to-pest/SKILL.md), [`repository-settings`](.claude/skills/repository-settings/SKILL.md), [`security-audit`](.claude/skills/security-audit/SKILL.md), [`synced-files`](.claude/skills/synced-files/SKILL.md), [`tests`](.claude/skills/tests/SKILL.md), [`update-dependencies-ticket`](.claude/skills/update-dependencies-ticket/SKILL.md), [`upstream-patch-lifecycle`](.claude/skills/upstream-patch-lifecycle/SKILL.md), [`writing-comments`](.claude/skills/writing-comments/SKILL.md), [`writing-commits`](.claude/skills/writing-commits/SKILL.md), [`writing-issues`](.claude/skills/writing-issues/SKILL.md), [`writing-pull-requests`](.claude/skills/writing-pull-requests/SKILL.md), [`writing-release-notes`](.claude/skills/writing-release-notes/SKILL.md).
<!-- uams-claude-skills:end -->

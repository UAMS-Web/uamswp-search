# Herd dev installation

Installed and network-activated UAMS Search on https://uamshealth-dev.test/ on 2026-10-29.

- 5,136 items indexed across 30 sub-sites; this includes 37 PDFs with no extractable text, still searchable by title/filename.
- Three PDFs exceed the 20 MB limit and are listed as errors. No other indexing errors remain.
- Visitor search is disabled. Relevanssi Premium and Ajax Search Pro remain active.
- Signed-in preview: https://uamshealth-dev.test/?s=GI&scope=install&engine=uams
- Verified the real main query returns UAMS results, retains totals/pagination, selects the Genesis template, and makes both old search engines yield only for UAMS queries.
- Verified the preview REST endpoint returns HTTP 200 and six suggestions for GI.
- Checked install-wide searches: GI 40, ENT 572, MRI 121, parking 297, breast cancer 100; all use the all-words pass.
- Development search-engine visibility settings stay unchanged. Only local CLI indexing/signed-in previews can include the noindex sub-sites.
- DISABLE_WP_CRON is true on this install. No server schedule was added; queued edits need manual processing while testing.

Manual queue command:

```sh
wp --path=/Users/bpmore/Herd/uamshealth-dev uams-search process-queue
```

Installed code matches the runtime files in the project's upload ZIP. No theme files, existing search plugins, or the reference uamshealth install were changed. The database is MySQL 9.4.0; target MariaDB 10.6.27 and relevance against the existing engine still require separate verification.

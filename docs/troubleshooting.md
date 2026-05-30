# Troubleshooting

Common issues and how to resolve them. If your problem isn't here, grab the
diagnostic ZIP (see the [bottom of this page](#getting-help)) and reach out.

## A scan or bulk job never finishes

You click **Scan Content**, **Check Links**, or **Apply** and the progress banner
sticks — it starts but never completes, or the button seems to do nothing.

- **Most likely cause:** your host blocks PHP's `exec()` / `proc_open()` via the
  `disable_functions` directive. Linkwise dispatches its background jobs with
  these functions; when they're blocked, the job can't actually start. Linkwise
  detects this and shows a banner in the Control Panel — if you see that banner,
  this is your issue.
- **Fix:** enable `exec()` and `proc_open()` (remove them from `disable_functions`
  in `php.ini`), or move to hosting that allows them. Most managed and VPS plans
  do; some budget shared plans don't. After enabling, retry the job.
- The diagnostic ZIP records this check, so it confirms the diagnosis quickly.

## Linkwise isn't in the Control Panel

- **Cause:** the **Manage Linkwise** permission isn't granted to your role.
- **Fix:** **Users → Roles →** your role **→** enable **Manage Linkwise**. Super
  admins have it automatically. See [Installation](/getting-started/installation#permissions).

## Suggestions or counts look out of date

After adding or heavily editing content, the Links Report or suggestion counts
don't reflect the change.

- **Cause:** the index is stale. Linkwise updates the index when you save a
  single entry, but large or bulk changes (imports, many new entries) need a full
  rescan.
- **Fix:** **Overview → Scan Content**. The Overview tab flags when the index is
  getting stale.

## A working link is reported as broken

- **Cause:** a transient network blip during the scan, or a server that rejects
  `HEAD` requests.
- **Fix:** re-run **Check Links**. Transient failures (timeouts, refused
  connections) are *not* cached — the next scan re-checks them. Servers that
  reject `HEAD` are automatically retried with `GET`, so false positives should
  be rare.

## A link I want ignored still shows as broken

- **Cause:** `ignored_links` patterns match the **full URL**; a bare domain won't
  match a full URL.
- **Fix:** use a wildcard — `*example.com*` ignores every link to that domain.
  See [Broken Links](/usage/broken-links).

## Drafts appear in reports (or published entries are missing)

- **Cause:** the `entry_status` setting. The default `published` excludes drafts;
  `all` includes them.
- **Fix:** adjust `entry_status` in your [configuration](/usage/configuration) and
  rescan.

## An entry keeps getting suggested that I don't want linked

- **Fix:** add the entry to `excluded_entries`, the whole collection to
  `excluded_collections`, or blacklist its title via `title_blacklist`. For a
  single suggestion, use the **ignore** action in the suggestions modal.

## A bulk operation says "modified by another editor" — but only I edited it

- **Cause:** right after a bulk operation, the page can still hold a stale content
  snapshot, so the next bulk sends an out-of-date hash.
- **Fix:** reload the page, then retry the operation.

## Multilingual: a "re-run Scan Content" banner, or cross-language oddities

- **Cause:** the index was built before per-site locale tagging — e.g. before you
  enabled multisite or upgraded — so some entries have no locale stamp.
- **Fix:** run **Scan Content** once (the banner prompts exactly this). Afterwards,
  suggestions are correctly scoped per language.

## Inserted links open (or don't open) in a new tab

- **Fix:** the `open_in_new_tab` option controls whether links Linkwise inserts
  carry `target="_blank"`. See [Configuration](/usage/configuration).

## A first scan is slow on a very large site

This is expected on sites with thousands of entries or links. The scan runs in
the **background** and is **cancellable and resumable**, and broken-link results
are cached for 24 hours against each entry's content — so re-runs are fast. Let
the first full scan finish.

## Getting help

If none of the above resolves it, send us a **diagnostic ZIP**:

**Overview → Help → Diagnostic ZIP.**

It bundles the information we need to reproduce an issue fast — environment and
hosting checks (including the `exec()` / `proc_open()` status above), index and
configuration state, and recent operation metadata. Attach it when you
[open a GitHub issue](https://github.com/arturrossbach/statamic-linkwise/issues)
or email [linkwise.support@gmail.com](mailto:linkwise.support@gmail.com) — bug
reports with the ZIP attached are resolved considerably faster.

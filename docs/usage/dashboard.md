# Dashboard

The **Overview** tab is Linkwise's home screen: a snapshot of your internal-link
health and the jumping-off point for every other tool.

## What it does

It answers "how healthy is my internal linking right now?" at a glance, and
points you at the things worth fixing — orphaned entries, broken links, a stale
index — with one-click links into the relevant tab.

## How it works

The dashboard reads the index built by **Scan Content** and shows:

- **Core counts** — entries indexed, outbound internal links, orphaned entries
  (no inbound links), and external domains.
- **Health signals** — *Inbound Coverage* (share of entries that receive at
  least one internal link) and *Average Outbound* links per entry, each with a
  green / amber / red badge.
- **Highlights** — your most- and least-linked entries.
- **Broken-link status** — the count from the last [link check](/usage/broken-links).
- **Recommendations** — a dynamic, prioritised list (broken links, high orphan
  rate, stale index, low coverage) with a button that jumps straight to the fix.
  You can dismiss any recommendation for the session.

On multilingual sites a per-language breakdown appears under the entry count, and
a [locale filter](/usage/multilingual) scopes every stat to one language.

::: tip On the health badges
The green/amber/red badges are a quick at-a-glance indicator based on sensible
default thresholds — treat them as guidance, not a strict SEO grade. The
underlying numbers (coverage %, average outbound) are the real signal.
:::

## Using it in the Control Panel

- Every metric card is clickable and drills into the matching tab (e.g. the
  Orphaned card opens the Links Report filtered to orphans).
- **Scan Content** (re)builds the index. The tab tells you when the index is
  getting stale.
- The data-freshness line shows when you last indexed and last checked links.

## Settings

| Option | Default | Effect |
|---|---|---|
| `orphaned_ignore` | `[]` | Entry IDs to exclude from the orphaned count (e.g. intentionally standalone pages). |

## Notes & limits

- The dashboard reflects the **last scan** — re-run **Scan Content** after large
  content changes for accurate numbers.
- Counts are scoped by your indexing settings (`collections`, `entry_status`,
  exclusions). See [Configuration](/usage/configuration).

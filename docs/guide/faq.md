# FAQ

Common questions about Linkwise — operations, recovery, integrations.

## How do I undo a bulk operation?

The fastest answer: **open the Activity Log tab and click "Revert this operation"** on the entry. For `Apply Rule`, `Inbound Insert`, `Outbound Insert`, internal `Detail-modal Bulk Unlink`, and `URL Changer` runs, Linkwise dispatches the inverse bulk through the same heavy-bulk pipeline (with concurrency-lock, hash-conflict detection, progress banner). Entries you've edited yourself since the original bulk are skipped automatically.

If the Revert button is unavailable (e.g. the operation was a `Bulk-Unlink` of broken links — re-linking those would re-introduce broken references), Linkwise falls back to the manual recovery paths below.

### Manual recovery paths

The Statamic ecosystem doesn't have a single universal undo — recovery depends on how your content is persisted. Use whichever fits your setup:

- **Statamic Revisions** (Statamic Pro feature, optional per collection): open the affected entry → Revisions tab → Restore. Best for editor-driven recovery — non-technical users can do this themselves.
- **Git** (Stache driver + content/ in version control): `git checkout HEAD~1 -- content/<collection>/<entry>.md` rolls a single entry back, or `git checkout HEAD~1 -- content/` rolls the whole content tree. After restore: click "Scan Content" in the CP so Linkwise's index reflects the new state.
- **Eloquent driver** (database-backed entries): use your hosting provider's database backup — most managed Statamic hosters (Forge, Ploi, Cleavr) take daily snapshots.
- **Hosting backup**: every serious hosting provider has scheduled snapshots. Check your provider's restore UI.

## What happens to inserted links if I uninstall Linkwise?

They stay. Linkwise only writes to entry content fields (Bard, Markdown, Replicator) — those changes live in your Statamic content store, not in Linkwise's own data files. Uninstalling the plugin won't roll those back. If you want to cleanly remove all Linkwise-inserted links, run `Apply Rule` reverts from the Activity Log (or use the URL Changer to bulk-replace) before uninstalling.

The plugin's own state (`storage/linkwise/*.json` — index, broken-link reports, auto-link rules, activity-log snapshots) IS removed when you delete the package, but that's metadata, not content.

## What's the deal with bulk operations that take 1–3 minutes?

After a bulk has touched all entries, Linkwise rebuilds its in-memory index and recomputes per-entry suggestion counts for the affected entries. On large sites this can take 1–3 minutes — the banner shows "Finalizing index…" during this phase. The bulk is **already complete** content-wise at this point; the index work is metadata cleanup so the table shows fresh numbers when you go back to it. You can safely navigate to other Linkwise tabs or away from the page; the work continues server-side.

## Is Linkwise safe to use with Statamic Eloquent driver?

Yes. Linkwise talks to entries through Statamic's `Entry` facade — driver-agnostic. Stache (flat-file) and Eloquent (database) both work. The only difference is where your content is persisted; the Linkwise behavior is identical. Recovery paths differ (git works for Stache; database backup works for both), but that's a backup-tool decision, not a Linkwise decision.

## How do I recommend a backup strategy before a large bulk?

Linkwise's confirmation modals show the entry count before any bulk runs (e.g. "This will replace 380 URL(s)…"). For bulks larger than ~50 entries, Linkwise's heuristic best-practice is:

1. **Stache + Git users**: `git commit -am "snapshot before linkwise bulk"` — gives you `git diff HEAD` to inspect after, `git checkout HEAD~1 -- content/` to roll back if needed.
2. **Statamic Revisions enabled**: nothing extra — Revisions captures changes automatically per entry.
3. **Eloquent or no version control**: trigger a manual hosting snapshot from your provider's dashboard, or rely on the daily scheduled one.
4. **All users**: rely on the Activity Log + Revert flow — Linkwise records a forensic snapshot before each bulk runs and gives you a one-click reverse path for the most common kinds.

## What happens if the bulk crashes mid-run?

Linkwise registers a shutdown-time crash-guard on every bulk. If the PHP process dies (segfault, OOM, server restart, manual kill) before reaching the terminal phase, the next status poll sees `phase: error` and the banner switches to a "stuck operation" warning with a Force-clear button. Entries that were already written are saved (atomic per-entry) — Linkwise never half-writes a single entry's Bard tree.

The Activity Log still records the snapshot; the partial-state means the Revert may report skipped entries (e.g. those that were never touched).

## Why are some entries marked "modified since bulk" in the Activity Log?

Linkwise captures a content hash for each entry it touched, both **before** and **after** the bulk runs. The "modified since bulk" badge shows entries whose live hash no longer matches the post-bulk hash — meaning a user (or an external process) edited them after Linkwise was done with them. Reverting will skip these entries and tell you up-front in the confirm modal: e.g. "47 entries will be reverted, 3 were edited since and will be skipped".

For snapshots created before this feature shipped (older than the upgrade date), no post-bulk hashes exist; those entries are reported "unknown" instead of false-positive "modified".

## Activity Log retention

Snapshots auto-cleanup after **30 days**. The directory `storage/linkwise/bulk-snapshots/` is bounded; older snapshots are removed on the next bulk write. The 30-day window is a "you should know what happened recently" tradeoff — long-term audit isn't Linkwise's job (use git or your DB).

## Auto-Link cascade — what triggers it?

When `auto_apply_on_save_enabled` is true (in Linkwise settings) and a rule has `autoApplyOnSave: 'follow_global'` or `'always'`, the rule fires every time an editor saves an entry. Linkwise has a per-entry cascade-guard so the auto-apply doesn't recursively trigger itself.

While a heavy bulk (apply, insert, unlink, URL change) is running, the on-save subscriber is **paused** to avoid colliding with the bulk's own writes. Editor saves still succeed normally; the auto-apply pass is skipped for that save and a log breadcrumb is left. After the bulk completes, manual saves trigger auto-apply again.

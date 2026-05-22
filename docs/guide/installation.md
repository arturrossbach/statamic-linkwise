# Installation

Linkwise is a Statamic 6 addon distributed via Composer.

## Requirements

| | Minimum |
|---|---|
| Statamic | 6.x |
| Laravel | 12+ |
| PHP | 8.2+ |
| `exec()` | enabled |

`exec()` is required because Linkwise dispatches background bulk operations (content scan, broken-link check, apply-rule, etc.) as detached `php artisan` processes so the CP stays responsive. Most managed hosts allow it; some shared hosts disable it.

To verify, check `php.ini`:

```ini
disable_functions =   ; ensure 'exec' is NOT listed
```

## Install via Composer

```bash
composer require arturrossbach/statamic-linkwise
```

The addon auto-registers via `AddonServiceProvider`. No manual provider registration, no config-file publishing required to get started.

## Open the Control Panel

Navigate to **Control Panel → Linkwise** in the main nav. The first visit triggers an automatic content scan covering:

- All entries in all collections (or just the ones you whitelist in `config/linkwise.php`)
- All Bard, Replicator, Markdown, text, and textarea fields
- Internal links (`statamic://entry::uuid`), external links (`https://...`)
- TF-IDF keyword extraction with Snowball stemming (English + German)

Scan time depends on your content volume. Roughly 1–2 seconds per 100 entries on a modern dev machine; expect proportionally slower on shared hosting. The first scan is the longest; incremental updates only re-process changed entries.

## Verify it works

After the scan completes, the Overview tab should show:

- Total entries indexed
- Total inbound + outbound links
- Suggestion candidates ready for review

If the Overview shows zero entries:

1. Check that at least one of your collections is included in `config/linkwise.php`'s `collections` array (or that the array is empty, meaning "all collections").
2. Check the scan log at `storage/linkwise/scan-content.log`.
3. Trigger a manual rescan via **Settings → Linkwise → Re-scan content**.

## Uninstall

```bash
composer remove arturrossbach/statamic-linkwise
```

Linkwise data lives in `storage/linkwise/`. Uninstalling does **not** delete this directory — you can remove it manually if you want a clean slate, or keep it in case you reinstall later.

## Next steps

- **[Configuration →](/guide/configuration)** — tune the suggestion engine, language, and scoping
- Open the **Links Report** tab to see existing internal links and suggestions for new ones
- Open the **Broken Links** tab and trigger your first scan

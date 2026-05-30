# Configuration Options

Every setting in `config/linkwise.php`, with its default. Publish the file with
`php artisan vendor:publish --tag=linkwise-config`. For a guided walk-through of
the settings that matter most, see [Configuration](/usage/configuration).

## Language & text

| Option | Default | Description |
|---|---|---|
| `language` | `null` | Primary content language (drives stemming, stop-words, keyword extraction). `null` = auto-detect from the Statamic site locale. On multilingual sites, language is detected per entry. |
| `custom_stopwords` | `''` | Extra stop-words to ignore, one per line, added to the language defaults. |

## Indexing scope

| Option | Default | Description |
|---|---|---|
| `collections` | `[]` | Collections to index. Empty = all collections. |
| `target_collections` | `[]` | Collections suggestions may point **to**. Empty = same as `collections`. |
| `entry_status` | `'published'` | Which entries to index: `'published'` or `'all'` (includes drafts). |
| `excluded_entries` | `[]` | Entry IDs to exclude from indexing and suggestions. |
| `excluded_collections` | `[]` | Collection handles to exclude. |
| `title_blacklist` | `''` | Newline-separated titles; entries with these titles are skipped. |
| `orphaned_ignore` | `[]` | Entry IDs excluded from the "Orphaned" count on the dashboard. |

## Suggestions

| Option | Default | Description |
|---|---|---|
| `max_suggestions` | `10` | Maximum candidates shown per entry. |
| `min_phrase_words` | `2` | Minimum words a title phrase must have to count as a match. |
| `min_score` | `0.4` | Minimum relevance score (0–1) for title-based suggestions. |
| `prevent_two_way` | `false` | If A links to B, don't suggest B → A. |

## Auto-linking & inserted links

| Option | Default | Description |
|---|---|---|
| `auto_apply_on_save_enabled` | `false` | Master switch for applying auto-link rules on entry save. A rule's own `auto_apply_on_save` flag must also be on. |
| `open_in_new_tab` | `false` | Add `target="_blank"` to links Linkwise inserts. |

## Broken links

| Option | Default | Description |
|---|---|---|
| `broken_links.timeout` | `10` | Seconds to wait per link request. |
| `broken_links.retries` | `2` | Retries before a transient failure is treated as broken. |
| `ignored_links` | `''` | Newline-separated full-URL patterns to skip; `*` is a wildcard (e.g. `*example.com*`). |

## Reserved / internal keys

The published config file also contains keys that are **not** user features:

- `dev_mode` — an addon-development aid (surfaces a debug badge in CP tables).
  Leave it `false`.
- `ai` — a reserved block for a planned integration. It is **not active** in the
  1.x line; leaving it set has no effect. See the [FAQ](/faq).
- A few other keys are inactive in the 1.x line and have no documented effect —
  leave them at their defaults.

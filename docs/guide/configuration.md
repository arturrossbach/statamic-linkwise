# Configuration

Linkwise has two configuration surfaces:

1. **`config/linkwise.php`** — file-based settings for things that should live in version control (collection scoping, AI keys via `.env`).
2. **Statamic CP → Settings → Linkwise** — runtime settings that editorial teams can adjust without a deploy (suggestion thresholds, language, custom stopwords, etc.).

::: tip Where should I put what?
**File-based** wins for: collection allow-lists, AI provider/keys, anything that should differ between staging and production.
**CP-based** wins for: suggestion thresholds, language, stopwords, anything an SEO manager might want to tune without bothering a developer.
:::

## Publishing the config file

```bash
php artisan vendor:publish --tag=linkwise-config
```

This drops `config/linkwise.php` in your project. Commit it to version control.

## Common file-based settings

```php
// config/linkwise.php
return [
    // Limit indexing to specific collections (empty = all collections)
    'collections' => ['blog', 'pages'],

    // Auto-apply enabled rules on every entry save (per-rule override possible)
    'auto_apply_on_save_enabled' => true,

    // Optional BYOK AI for smarter semantic matching (post-V1.0)
    'ai' => [
        'provider' => env('LINKWISE_AI_PROVIDER'),
        'api_key' => env('LINKWISE_AI_API_KEY'),
        'model' => env('LINKWISE_AI_MODEL'),
    ],
];
```

### `collections`

Whitelist of collection handles to index. Empty array = all collections. Useful when you have site-config collections (e.g. `redirects`, `globals`, `forms-data`) that shouldn't show up in the Links Report.

### `auto_apply_on_save_enabled`

Master switch for "apply enabled auto-link rules whenever an entry is saved". Per-rule overrides are configured in the Auto-Linking tab.

### `ai.*`

BYOK (bring-your-own-key) AI configuration for semantic suggestion matching. Linkwise calls your provider directly from your server — keys never leave your infrastructure.

## CP-based settings

The full settings UI lives at **Statamic CP → Settings → Linkwise**. Documented per-area below. All settings are saved to your Statamic preferences store and apply immediately (no deploy needed).

### Suggestion engine thresholds

| Setting | Default | Effect |
|---|---|---|
| `min_keyword_score` | `0.15` | TF-IDF cutoff for keyword suggestion candidates. Lower = more candidates, more noise. |
| `min_phrase_words` | `2` | Smallest title-phrase n-gram considered a match. Lowering to 1 surfaces single-word matches but explodes false positives. |
| `max_suggestions` | `10` | Cap on suggestions per entry per direction (inbound/outbound). |
| `min_similarity` | `0.6` | Threshold for unordered-stem cluster matching. Higher = stricter. |

::: info Re-scan required
These thresholds are **index-time** settings. Changing them re-runs the suggestion engine against the existing index, which is fast — but the index itself only updates on save or via **Settings → Linkwise → Re-scan content**.
:::

### Language

Drives stopword removal and Snowball stemming for keyword extraction.

- `en` — English-only
- `de` — German-only
- `en_de` (default) — combined stopwords for mixed-language sites

For other languages, add custom stopwords via the field below.

### Custom stopwords

Newline-separated list. Added on top of the language preset. Example: brand terms that appear in every title and shouldn't anchor a suggestion (`Acme`, `Acme Corp`, `Inc.`).

### Open links in new tab

Toggles `target="_blank"` on all newly-inserted links via the Bard mark extension. Existing links are untouched. Set per-link overrides via the link editor.

## Environment variables

| Variable | Purpose |
|---|---|
| `LINKWISE_AI_PROVIDER` | `openai` or `anthropic` (BYOK AI) |
| `LINKWISE_AI_API_KEY` | Your provider API key |
| `LINKWISE_AI_MODEL` | Model handle for your provider (e.g. `gpt-4o-mini`) |
| `CACHE_STORE` | Switch to `redis` or `database` for multi-server deployments. Bulk-operation state (heartbeats, progress, JobLock) is shared via cache, so single-server file cache works fine but load-balanced deployments need a centralised store. |

## Next steps

- Open the **Statamic CP → Linkwise** main nav to start using the dashboard
- Settings UI lives at **Settings → Linkwise**
- Bug or question? [Open an issue on GitHub](https://github.com/arturrossbach/statamic-linkwise/issues)

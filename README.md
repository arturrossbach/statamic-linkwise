# Linkwise

**The internal linking assistant Statamic editors actually use.** Find broken links, surface internal-link opportunities, manage `rel`-attribute governance per domain, and bulk-replace URLs across your entire site — without leaving the Control Panel.

Built for **Statamic 6** with Inertia + native UI components. No external services. No telemetry. Your link data stays on your server.

📖 **Documentation:** [linkwise.arturrossbach.de](https://linkwise.arturrossbach.de) *(deploying)*
🛒 **Marketplace:** [statamic.com/addons/arturrossbach/linkwise](https://statamic.com/addons/arturrossbach/linkwise) *(submitting)*

---

## Features

- **🔗 Inbound + Outbound Suggestions** — Multi-tier matching (title phrase → custom keywords → unordered stem cluster → TF-IDF keyword overlap) finds linking opportunities you'd miss manually. Anchor text editing built in. Long-titled news-style entries surface 2-word matches; descriptive blog titles get tight cluster matches.
- **🧱 Works with Peak + any Replicator setup** — content nested in Peak Cards, Bard custom sets (pull-quote, buttons, captions), accordions, or any addon's text/markdown fields is indexed end-to-end. Read and write paths walk every nested string-shaped leaf, with built-in filters for UUIDs, asset filenames, and config enums so noise stays out of anchor candidates.
- **⚡ Auto-Linking** — Keyword → URL rules with case-sensitivity, collection scoping, once-per-post enforcement, and auto-apply-on-save (per-rule or global).
- **🔍 Broken Link Finder** — HTTP HEAD/GET scan with retries, error-type classification (404, SSL, timeout, connection-failed). Inline edit, ignore, or unlink right from the table.
- **🔄 URL Changer** — Bulk replace any URL across all entries with smart-match or exact-match modes. Optimistic locking prevents conflicts with concurrent editors.
- **🌐 Domain Manager** — Set `rel="nofollow"`, `"sponsored"`, `"ugc"` per external domain. Applied automatically to every link via Bard mark extension — no content rewriting.
- **📊 Target Keywords** — TF-IDF auto-extracted content keywords + custom keywords per entry boost suggestion ranking.
- **📋 Dashboard** — 7 tabs: Overview, Links Report, Broken Links, Domains, Auto-Linking, Target Keywords, URL Changer. CSV export everywhere.

## Installation

```bash
composer require arturrossbach/linkwise
```

Auto-registers via `AddonServiceProvider`. Open Control Panel → **Linkwise** in the nav. First visit triggers a content scan.

## Requirements

- Statamic 6.x
- Laravel 12+
- PHP 8.2+
- `exec()` enabled (for detached background jobs)

---

## Try it before you buy it

Linkwise follows Statamic's standard licensing model: **free in
development, paid in production**. You don't need a license to install
Linkwise locally, point it at your actual content, and verify that

- the NLP pipeline handles your language correctly,
- auto-link rules find the matches you'd expect,
- suggestions feel relevant for your editorial workflow,
- performance is acceptable on your corpus size.

Only when you're ready to deploy on a public site does the per-site
license activate. Test locally first; the language compatibility matrix
below tells you which kind of behaviour to expect.

---

## Language support

Linkwise's NLP pipeline (stemming, stop-words, keyword extraction) is
language-aware. Three tiers reflect what Linkwise actually does for each
language out of the box, derived from objective code-level capability —
not marketing claims. If your language is not in this list, the runtime
falls back to English defaults; you can verify behaviour with a local
scan before licensing.

### Confident (14 languages)

Snowball stemmer + curated [stopwords-iso](https://github.com/stopwords-iso/stopwords-iso)
list + Western sentence punctuation. Auto-link rules canonicalize
inflected forms (`Datenbank` matches `Datenbanken`, `bibliothèque`
matches `bibliothèques`). Equivalent quality to English content.

| Language   | Stemmer       | Stop-words |
|------------|---------------|------------|
| English    | Snowball EN   | 1298       |
| German     | Snowball DE   |  620       |
| French     | Snowball FR   |  691       |
| Spanish    | Snowball ES   |  732       |
| Italian    | Snowball IT   |  632       |
| Dutch      | Snowball NL   |  413       |
| Portuguese | Snowball PT   |  560       |
| Swedish    | Snowball SV   |  418       |
| Danish     | Snowball DA   |  170       |
| Norwegian  | Snowball NO   |  221       |
| Finnish    | Snowball FI   |  847       |
| Romanian   | Snowball RO   |  434       |
| Russian    | Snowball RU   |  559       |
| Catalan    | Snowball CA   | (curated)  |

### Limited (14 languages)

Stop-words list available, but **no Snowball stemmer** (or a known
edge case Linkwise doesn't yet handle). Auto-link is exact-match —
plural and conjugated forms won't match a base-form rule.

| Language        | Reason                                                        |
|-----------------|---------------------------------------------------------------|
| Hungarian       | No Snowball stemmer                                           |
| Polish          | No Snowball stemmer                                           |
| Czech / Slovak  | No Snowball stemmer                                           |
| Slovenian       | No Snowball stemmer                                           |
| Croatian        | No Snowball stemmer                                           |
| Bulgarian       | No Snowball stemmer                                           |
| Ukrainian       | No Snowball stemmer (Cyrillic)                                |
| Latvian / Lithuanian / Estonian / Irish | No Snowball stemmer            |
| Greek           | Greek `;` not yet recognised as a sentence boundary           |
| Turkish         | Dotted/dotless-i lowercase rules not handled                  |

### Not supported

| Language   | Reason                                                                    |
|------------|---------------------------------------------------------------------------|
| Arabic / Hebrew | RTL + tokenization not implemented (V1.1 candidates)                |
| Chinese    | No space-based word boundaries — needs jieba/ICU tokenizer                |
| Japanese   | No space-based word boundaries — needs MeCab                              |
| Korean     | No space-based word boundaries                                            |
| Thai       | No space-based word boundaries                                            |
| Vietnamese | Diacritic-based syllable structure complicates tokenization               |

The Settings UI hard-blocks selecting a "not supported" language so you
can't silently configure something that won't work.

---

## Recommended setup for production

### Cache driver (multi-server only)

Single-server setups work fine on the default file cache. For load-balanced deployments switch to Redis or Database — bulk-operation state (heartbeats, progress, JobLock) is shared via cache:

```env
CACHE_STORE=redis
```

### `exec()` permission

Linkwise dispatches background bulks via `exec()`. Some shared hosts disable it — check `php.ini`:

```ini
disable_functions =   ; ensure 'exec' is NOT listed
```

### Long-running bulks

Linkwise commands set `set_time_limit(0)`. If your stack enforces a global timeout (NGINX `fastcgi_read_timeout`, Apache `Timeout`), raise it to 600s on Linkwise CP routes — or split large bulks.

---

## Configuration

Publish the config:

```bash
php artisan vendor:publish --tag=linkwise-config
```

Common settings (`config/linkwise.php`):

```php
return [
    // Limit indexing to specific collections (empty = all)
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

All other settings are configurable via the Statamic CP under **Settings → Linkwise**.

---

## Bulk operations — what to expect

All bulks (Apply Rule, Apply Selected, URL Changer Apply/Unlink, Broken-Link Bulk-Unlink, Content Scan, Broken-Link Check) run as **detached background processes**:

1. Click triggers a `php artisan linkwise:...` process — UI stays responsive
2. A sticky banner shows live progress on every Linkwise tab
3. Cancel button stops the job at the next item boundary
4. Banner persists across tab switches + reloads
5. Toast confirms completion

### When something seems stuck

If a bulk has no progress for >2 minutes, the banner switches to a warning state with a **Force-clear** button. This wipes the JobLock — safe to use whenever.

To reset all Linkwise state manually:

```bash
php artisan cache:clear
```

### Data integrity

Each bulk writes per-entry atomically with a SHA hash check (`SafeEntrySaver`). If another editor modifies an entry mid-bulk, that entry is skipped (counted as "skipped" in the toast) — concurrent edits never get overwritten.

### Recovery

Every bulk leaves a forensic snapshot in the **Activity Log** tab — kind, who started it, when, which entries were touched, and the per-item operation data. For most bulk kinds (Apply Rule, Inbound/Outbound Insert, internal Detail-Unlink, URL Changer) Linkwise can dispatch a one-click reverse via the same heavy-bulk pipeline. Entries you've edited since the original bulk are skipped automatically.

When the auto-revert isn't applicable, three manual paths cover every Statamic setup: **Statamic Revisions** (Pro feature, per-collection), **Git** (Stache + content/ in version control), or your **hosting provider's backup**. See the [FAQ](./docs/guide/faq.md) for the full recovery playbook.

---

## Privacy & GDPR

- All link data lives in `storage/linkwise/` on **your** server. Never transmitted.
- No telemetry. No analytics. No SaaS callbacks.
- Optional BYOK AI uses **your** OpenAI/Anthropic API key directly from your server — Linkwise never sees the key or the embeddings.
- Frontend errors (Vue render, JS exceptions) are captured **locally** to `storage/linkwise/frontend-errors.log`. Never sent anywhere.

---

## Reporting issues

The Linkwise Control Panel has a built-in **Help → Download diagnostic ZIP** action that bundles:

- PHP / Laravel / Statamic / Linkwise versions
- Runtime info (memory limits, opcache, server software)
- Aggregate counts and stats (no entry content, no URLs, no API keys)
- *(opt-in)* Stack traces from `laravel.log` filtered to Linkwise mentions
- *(opt-in)* Frontend error log (Vue + window + promise rejections)
- *(opt-in)* State JSON snapshots (broken-links, autolink-rules, etc.)

The default download is privacy-safe (counts only). The "with logs" variant requires confirmation and clearly warns about URL/PII content. Attach the ZIP to your support ticket — that's all we need.

For commercial support: see [LICENSE.md](./LICENSE.md).

---

## License

Commercial license — see [LICENSE.md](./LICENSE.md).
Purchase via the [Statamic Marketplace](https://statamic.com/addons).

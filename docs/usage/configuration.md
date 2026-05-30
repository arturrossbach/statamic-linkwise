# Configuration

Linkwise works out of the box — you only configure it to tune behaviour. There
are **two ways**, and you can use either:

- **Control Panel settings** — almost every option has a field on Linkwise's
  settings page in the Control Panel, at `/cp/addons/linkwise/settings`. This is
  the quickest way to change behaviour; nothing to deploy.
- **Config file** — publish `config/linkwise.php` to keep settings in your
  codebase (version-controlled and reviewable):

  ```bash
  php artisan vendor:publish --tag=linkwise-config
  ```

When the same option is set in both places, **the Control Panel value wins** —
CP settings are merged on top of the file at runtime. Only a couple of advanced
options (the broken-link checker's `timeout` and `retries`) live in the config
file only.

This page is a guided tour of the settings that matter most, shown as config
keys — each option that has a Control Panel field uses the **same name** there.
The [Configuration Options](/reference/config-options) reference lists every key.

## In the Control Panel

Open Linkwise's settings at `/cp/addons/linkwise/settings`. Every option below
also lives here, grouped into sections, with inline guidance on each field:

- **General** — content language, which collections to index, target
  collections, entry status, max outbound suggestions per entry, and
  open-in-new-tab.
- **Matching** — how strict title matching is (minimum phrase words, minimum
  match score) and two-way-link prevention.
- **Exclusions** — entries, collections, and titles to keep out of suggestions;
  entries to ignore in the Orphaned report; and URL patterns the broken-link
  checker should skip.
- **Stopwords** — extra filler words to ignore on top of the language defaults.
- **Auto-Linking** — the master switch that lets active rules apply on entry
  save (see [Auto-Linking](/usage/auto-linking)).

Hit **Save** and the change applies immediately. Fields that influence indexing
or matching say so in their instructions — re-run **Scan Content** afterwards so
the report counts catch up. Anything you set here overrides the matching key in
`config/linkwise.php`.

## Language

```php
'language' => null,
```

Drives stop-word lists, stemming, and keyword extraction. Leave it `null` and
Linkwise auto-detects from your Statamic site locale (`de_DE` → `de`). On a
multilingual site, language is detected **per entry** from each site's `lang` —
you don't set it globally. See [Multilingual](/usage/multilingual).

## What gets indexed

```php
'collections' => [],          // empty = all collections
'target_collections' => [],   // empty = same as `collections`
'entry_status' => 'published',// or 'all' to include drafts
```

- `collections` limits which collections Linkwise reads.
- `target_collections` limits which collections suggestions may point **to**.
- `entry_status` keeps drafts out of the index by default.

You can also exclude individual entries or collections, and ignore entries by
title:

```php
'excluded_entries' => [],
'excluded_collections' => [],
'title_blacklist' => '',   // newline-separated titles to skip
```

## Suggestions

```php
'max_suggestions' => 10,
'min_phrase_words' => 2,
'min_score' => 0.4,
'prevent_two_way' => false,
```

These shape the [Suggestions](/usage/links-report) engine: `min_phrase_words` and
`min_score` set how strict title matching is, `max_suggestions` caps how many
candidates appear per entry, and `prevent_two_way` stops Linkwise suggesting
B → A when A already links to B.

## Broken-link checking

```php
'broken_links' => [
    'timeout' => 10,   // seconds per request
    'retries' => 2,    // retries on transient failures
],
'ignored_links' => '', // newline-separated URL patterns to skip
```

`ignored_links` entries are matched as full-URL patterns; use `*` as a wildcard.
To ignore every link to a domain, write `*example.com*` (a bare `example.com`
will not match `https://example.com/...`). See [Broken Links](/usage/broken-links).

## Inserted links

```php
'open_in_new_tab' => false,   // add target="_blank" to inserted links
```

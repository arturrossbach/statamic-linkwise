# Configuration

Linkwise works out of the box with no configuration. When you want to tune it,
publish the config file:

```bash
php artisan vendor:publish --tag=linkwise-config
```

That writes `config/linkwise.php`. This page is a guided tour of the settings
that matter most; the [Configuration Options](/reference/config-options)
reference lists every key with its default.

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

## Where to change settings

Everything above lives in `config/linkwise.php` and is committed with your
codebase. A few behavioural settings are also exposed in the Control Panel under
the Linkwise settings — those are merged on top of the file at runtime.

# FAQ

## What does Linkwise do?

It helps you manage internal and external links across a Statamic site:
[suggests internal links](/usage/links-report) in both directions,
[auto-links keywords](/usage/auto-linking) by rule, [finds broken external
links](/usage/broken-links), rewrites URLs site-wide, and governs `rel`
attributes per domain — across Bard, Replicator, and Markdown fields.

## Does it require Statamic Pro?

No, for a single-site install. Linkwise runs on standard Statamic 6. The
multilingual features rely on Statamic's multisite, which is a Pro feature — so
you only need Pro if you run multiple sites/locales.

## Is the license per site or per installation?

Per **production installation** — one `composer.json`-bound codebase — regardless
of how many Statamic Sites, locales, or domains it serves. Development and
staging are free. See [Editions](/getting-started/editions).

## Will Linkwise overwrite or break my existing links?

No. Linkwise **never modifies a link that already exists** — every insert and
auto-link path skips text that's already inside a link. Bulk operations also
record a snapshot for reference. You stay in control of what changes.

## Does it insert links automatically?

Only if you ask it to. [Suggestions](/usage/links-report) are proposals you
review and insert by hand. [Auto-Linking](/usage/auto-linking) applies rules you
define — and even then, applying on save is off by default and opt-in on two
levels.

## Why doesn't Linkwise suggest "related" content or match by topic similarity?

By design. Linkwise only suggests a link when there's a **concrete reason** for
it — the target's title appears in your text, or a keyword you set matches. It
deliberately does **not** guess links from fuzzy topical or keyword overlap.

We tried broader keyword-similarity matching earlier and it produced too much
low-quality noise — suggestions that looked plausible but weren't the link an
editor actually wanted. High precision beats high volume for internal linking,
so that approach was dropped. If you want a link Linkwise doesn't propose,
[add a custom keyword](/usage/links-report) to the target entry — that's the
intended, reliable way to widen its matches.

## Is there AI / semantic matching?

Not in the 1.x line. AI-assisted semantic matching — understanding that two
entries are *about* the same thing even without shared words — is **planned for
a future major release**. The intent is bring-your-own-key: it would call your
own AI provider directly from your server, with no Linkwise-hosted service in
between. Until then, suggestions stay title- and keyword-based.

## Does Linkwise send my content or data anywhere?

No. Everything stays in `storage/linkwise/` on your server. There's no
telemetry and no external service in the loop — broken-link checking is the only
outbound traffic, and that only requests the external URLs already in your
content.

## How often should I re-scan?

Re-run **Scan Content** after adding or substantially editing content. The
Overview tab flags when the index is getting stale. Day-to-day edits don't
require a manual scan.

## How is the content language detected?

From your Statamic site locale automatically (`de_DE` → German). On a
multilingual site, language is detected **per entry** from each site's `lang`,
so stemming and stop-words apply correctly per language. You can override the
global default in [configuration](/usage/configuration).

## Where do I get support?

Open an issue on [GitHub](https://github.com/arturrossbach/statamic-linkwise),
ask in the Statamic Discord (#addons), or email
[linkwise.support@gmail.com](mailto:linkwise.support@gmail.com). A diagnostic ZIP
(Overview → Help) speeds up bug reports considerably.

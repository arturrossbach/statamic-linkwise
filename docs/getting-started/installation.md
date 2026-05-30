# Installation

Linkwise installs like any Statamic addon — one Composer command, then a first
content scan. No build step, no external service to configure.

::: tip Evaluate it for free first
Development and staging use needs no license. We strongly recommend installing
Linkwise on a staging or local copy and trying it on your real content before
using it in production — see [Editions](/getting-started/editions).
:::

## Requirements

| | |
|---|---|
| **Statamic** | 6.0+ |
| **PHP** | 8.2+ |
| **Statamic Pro** | Not required for single-site use. Multilingual features rely on Statamic's multisite, which is a Pro feature. |
| **Shell functions** | `exec()` and `proc_open()` must be enabled (not blocked by `disable_functions`). Used to run background jobs — see below. |
| **Writable storage** | Linkwise writes its index, reports, and operation snapshots to `storage/linkwise/`; that path must be writable by the web user. |
| **Outbound HTTP** | Only the [broken-link checker](/usage/broken-links) makes outbound requests (to the URLs in your content). Everything else is local. |

### Background operations need `exec()`

Linkwise runs its longer jobs — content scan, link check, and bulk apply/unlink
— by launching a PHP CLI process in the background. For that, PHP's `exec()` and
`proc_open()` functions must be available and **not blocked** by the
`disable_functions` directive.

Most managed and VPS hosting allows them. Some restrictive shared-hosting plans
disable them — in which case a job would start but never finish. Linkwise
**detects this and shows a banner in the Control Panel**, so you'll know
immediately rather than hitting a silent failure. If you see it, see
[Troubleshooting](/troubleshooting). No queue worker or cron is required (both
work fine if you have them).

## Install

```bash
composer require arturrossbach/statamic-linkwise
```

The addon registers itself automatically. After install you'll find **Linkwise**
in the Control Panel under **Tools**.

## First scan

Linkwise needs to index your content once before it can suggest links or find
broken ones. Either:

- Open **Linkwise → Overview** in the Control Panel and click **Scan Content**, or
- Run it from the CLI:

```bash
php artisan linkwise:index
```

The scan reads every entry's content fields, builds the internal-link map, and
extracts keywords. Re-run it whenever you've added or changed a lot of content
(the Overview tab nudges you when the index is stale).

## Permissions

Linkwise registers a single Control Panel permission, **Manage Linkwise**. Grant
it (under **Users → Roles**) to the roles that should see and use the addon.
Roles without it won't see the Linkwise nav item.

## Configuration (optional)

Linkwise runs with sensible defaults out of the box. To customise behaviour,
publish the config file:

```bash
php artisan vendor:publish --tag=linkwise-config
```

This writes `config/linkwise.php`. See [Configuration](/usage/configuration) for
a guided tour and the [Configuration Options](/reference/config-options)
reference for every setting.

## Verify

You're set up when:

1. **Linkwise** appears in the CP under **Tools**.
2. A content scan finishes and the **Overview** tab shows your entry count.

## Uninstall

```bash
composer remove arturrossbach/statamic-linkwise
```

Linkwise stores all its data in `storage/linkwise/`. Delete that directory to
remove the index, reports, and operation snapshots. Your entry content is never
modified by uninstalling — only links you explicitly inserted or changed remain.

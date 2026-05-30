# Commands

Linkwise registers Artisan commands. In day-to-day use you don't need any of
them — the Control Panel buttons do the same work — but two are useful for
automation and CI.

## User-facing commands

### `linkwise:index`

Rebuilds the content index — the same job as the **Scan Content** button. It
reads your entries, builds the internal-link map, and extracts the data the
[suggestion engine](/usage/links-report) needs.

```bash
php artisan linkwise:index
```

Run it after a deploy that adds or changes a lot of content, or on a schedule.

### `linkwise:check-links`

Scans external links for [broken URLs](/usage/broken-links) — the same job as the
**Check Links** button.

```bash
php artisan linkwise:check-links
```

Good to run periodically (e.g. weekly via the scheduler) so dead links surface
without a manual check.

## Scheduling (optional)

You can wire either command into Laravel's scheduler, for example:

```php
// routes/console.php
Schedule::command('linkwise:index')->daily();
Schedule::command('linkwise:check-links')->weekly();
```

## Internal commands

The other `linkwise:*` commands (applying rules, bulk unlink, URL-changer apply,
link insert, and similar) are **dispatched automatically by the Control Panel**
when you run those operations. They read a queued payload and aren't meant to be
invoked by hand — use the corresponding CP tool instead.

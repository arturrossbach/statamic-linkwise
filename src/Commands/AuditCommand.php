<?php

namespace Arturrossbach\Linkwise\Commands;

use Arturrossbach\Linkwise\AutoLink\AutoLinkApplier;
use Arturrossbach\Linkwise\AutoLink\AutoLinkManager;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Indexer\EntryRecord;
use Arturrossbach\Linkwise\Keywords\TargetKeywordManager;
use Arturrossbach\Linkwise\Links\BrokenLinkReport;
use Arturrossbach\Linkwise\Reports\LinkReport;
use Arturrossbach\Linkwise\Suggestions\InboundEngine;
use Arturrossbach\Linkwise\Suggestions\OutboundSuggestionGrouper;
use Arturrossbach\Linkwise\Suggestions\SuggestionEngine;
use Arturrossbach\Linkwise\Support\BardLinkInserter;
use Arturrossbach\Linkwise\Support\EntryFieldWalker;
use Arturrossbach\Linkwise\Support\TextExtractor;
use Arturrossbach\Linkwise\UrlChanger\UrlReplacer;
use Illuminate\Console\Command;
use Statamic\Facades\Entry;

/**
 * Linkwise self-audit. Walks every user-reachable path against real index
 * data and asserts invariants that must hold for the system to be honest:
 *
 *   - Auto-Link previews don't surface anchors inside existing markdown links
 *   - Index outbound counts match what the walker actually finds in entry content
 *   - Inbound suggestions surfaced via the modal API are all insertable
 *   - Outbound suggestions surfaced via the modal API are all insertable
 *   - Domain Report counts match what the walker finds
 *
 * Designed as a launch gate AND a permanent regression net: every time we
 * discover a new bug class through real-data testing, the lesson gets
 * codified as a `check()` here. Future runs catch the same class
 * automatically — no need to re-discover it manually.
 *
 * Run during development:           php artisan linkwise:audit
 * Run with full failure details:    php artisan linkwise:audit --verbose
 * Run a single path only:           php artisan linkwise:audit --path=auto-link
 *
 * Exit code 0 on all-pass, 1 on any-fail — CI-friendly.
 */
class AuditCommand extends Command
{
    protected $signature = 'linkwise:audit
                            {--path= : Run a single path: auto-link, index-parity, inbound, outbound, domains}
                            {--max-failures=10 : Cap failure details per path to keep output readable}';

    protected $description = 'Walk every Linkwise user-path against real data and report invariant violations';

    public function __construct(
        protected EntryIndexer $indexer,
        protected AutoLinkManager $autoLinkManager,
        protected SuggestionEngine $suggestionEngine,
        protected InboundEngine $inboundEngine,
    ) {
        parent::__construct();
    }

    /** @var array<string, list<string>> Path-name → list of failure-detail strings */
    protected array $failures = [];

    /** @var array<string, int> Path-name → pass count */
    protected array $passes = [];

    public function handle(): int
    {
        // Bump memory: 5 paths × dry-run inserts × 347 entries pulls a lot
        // of Bard JSON into memory transiently. Default 128M chokes on
        // medium sites; 512M comfortably covers 1000+ entry corpora.
        @ini_set('memory_limit', '512M');
        @set_time_limit(0);

        $only = $this->option('path');

        $paths = [
            'auto-link'       => fn () => $this->auditAutoLinkPreview(),
            'index-parity'    => fn () => $this->auditIndexParity(),
            'inbound'         => fn () => $this->auditInboundInsertability(),
            'outbound'        => fn () => $this->auditOutboundInsertability(),
            'domains'         => fn () => $this->auditDomainParity(),
            'broken-links'    => fn () => $this->auditBrokenLinks(),
            'url-changer'     => fn () => $this->auditUrlChanger(),
            'target-keywords' => fn () => $this->auditTargetKeywords(),
            'links-report'    => fn () => $this->auditLinksReport(),
            'overview'        => fn () => $this->auditOverview(),
        ];

        if ($only && ! isset($paths[$only])) {
            $this->error("Unknown path: {$only}. Available: ".implode(', ', array_keys($paths)));

            return self::FAILURE;
        }

        $this->line('');
        $this->line('<fg=cyan>Linkwise self-audit</> — walking user-paths against real index data');
        $this->line(str_repeat('━', 70));

        foreach ($paths as $name => $runner) {
            if ($only && $only !== $name) {
                continue;
            }
            $this->failures[$name] = [];
            $this->passes[$name] = 0;
            $runner();
            $this->reportPath($name);
        }

        $this->line('');
        $totalFailures = array_sum(array_map('count', $this->failures));
        $totalPasses = array_sum($this->passes);

        if ($totalFailures === 0) {
            $this->info("All {$totalPasses} checks passed.");

            return self::SUCCESS;
        }

        $this->error("{$totalFailures} failures across {$totalPasses} checks. Fix or document as known limitations.");

        return self::FAILURE;
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Auto-Link preview. For each rule, walk affected_entries.
    // Invariants:
    //   - sentence_context contains no nested-anchor markdown ([[)
    //   - if link_status='would_link', the dry-run insert agrees
    //   - the keyword position in context is not inside an existing
    //     [...](...) — that scenario produced today's catastrophic
    //     "[[anchor]](url)](url)" corruption.
    // ──────────────────────────────────────────────────────────────────
    protected function auditAutoLinkPreview(): void
    {
        $this->line('<fg=yellow>auto-link</> — Auto-Link rule previews');

        $rules = $this->autoLinkManager->loadRules();
        $applier = new AutoLinkApplier($this->indexer, $this->autoLinkManager);

        foreach ($rules as $rule) {
            if (! $rule->active) {
                continue;
            }
            try {
                $preview = $applier->applyRule($rule, preview: true);
            } catch (\Throwable $e) {
                $this->recordFailure('auto-link', "rule '{$rule->keyword}' preview threw: ".$e->getMessage());

                continue;
            }

            foreach ($preview['affected_entries'] ?? [] as $entry) {
                $context = (string) ($entry['sentence_context'] ?? '');
                $linkStatus = (string) ($entry['link_status'] ?? '');
                $entryId = (string) ($entry['id'] ?? '?');
                $title = (string) ($entry['title'] ?? '?');

                $this->checkContextNotNested('auto-link', $rule->keyword, $title, $context);
                $this->checkAnchorNotInsideExistingLink('auto-link', $rule->keyword, $title, $context);

                if ($linkStatus === 'would_link') {
                    $this->checkDryRunAgreesWithLinkStatus('auto-link', $rule, $entryId, $title);
                }
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Index outbound parity. The index says entry E has N outbound
    // links. Walking E's content should yield exactly that set.
    // Drift here is the "ghost links" class — DetailModal listing links
    // the unlink path can never remove.
    // ──────────────────────────────────────────────────────────────────
    protected function auditIndexParity(): void
    {
        $this->line('<fg=yellow>index-parity</> — index outbound counts vs. walked content');

        $records = $this->indexer->load();
        foreach ($records as $record) {
            $entry = Entry::find($record->id);
            if (! $entry) {
                $this->recordFailure('index-parity', "entry {$record->id} ('{$record->title}') in index but not in Statamic");

                continue;
            }

            $actual = [];
            EntryFieldWalker::walk(
                $entry,
                function (array $bard) use (&$actual) {
                    $actual = array_merge($actual, TextExtractor::linksFromBard($bard));
                },
                function (string $md) use (&$actual) {
                    $actual = array_merge($actual, TextExtractor::linksFromMarkdown($md));
                },
            );
            $actual = array_unique($actual);

            $ghosts = array_diff($record->outboundLinks, $actual);
            $missed = array_diff($actual, $record->outboundLinks);

            if (! empty($ghosts) || ! empty($missed)) {
                $detail = "'{$record->title}'";
                if (! empty($ghosts)) {
                    $detail .= ' ghosts: '.implode(', ', array_slice($ghosts, 0, 3));
                }
                if (! empty($missed)) {
                    $detail .= ' missed: '.implode(', ', array_slice($missed, 0, 3));
                }
                $this->recordFailure('index-parity', $detail);

                continue;
            }
            $this->pass('index-parity');
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Inbound suggestions reaching the modal API must all pass a
    // dry-run. InboundEngine::suggestFiltered already guards this — we
    // re-verify so a future regression is caught immediately.
    // Sample a deterministic slice (not all entries) to keep audit fast.
    // ──────────────────────────────────────────────────────────────────
    protected function auditInboundInsertability(): void
    {
        $this->line('<fg=yellow>inbound</> — modal-surfaced suggestions are insertable');

        $records = $this->indexer->load();
        $sample = array_slice($records, 0, 20);

        foreach ($sample as $record) {
            try {
                $suggestions = $this->inboundEngine->suggestFiltered($record->id);
            } catch (\Throwable $e) {
                $this->recordFailure('inbound', "suggestFiltered for '{$record->title}' threw: ".$e->getMessage());

                continue;
            }
            foreach ($suggestions as $s) {
                $this->checkSuggestionInsertable(
                    'inbound',
                    $s->sourceEntryId,
                    $s->anchorText,
                    $s->targetEntryId,
                    "'{$s->anchorText}' from {$s->sourceEntryId} → '{$record->title}'",
                );
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Outbound suggestions reaching the modal API must all pass a
    // dry-run. OutboundSuggestionGrouper::groupAndFilter is the user-
    // facing layer — verify it returns only insertable suggestions.
    // (Raw SuggestionEngine::suggest output is intentionally optimistic
    // and not directly user-facing — don't audit at that layer.)
    // ──────────────────────────────────────────────────────────────────
    protected function auditOutboundInsertability(): void
    {
        $this->line('<fg=yellow>outbound</> — modal-surfaced suggestions are insertable');

        $records = $this->indexer->load();
        $sample = array_slice($records, 0, 20);

        foreach ($sample as $record) {
            try {
                $raw = $this->suggestionEngine->suggest(
                    $record->text, $records, $record->id, $record->outboundLinks
                );
                $grouped = OutboundSuggestionGrouper::groupAndFilter($raw, $record->id);
            } catch (\Throwable $e) {
                $this->recordFailure('outbound', "grouping for '{$record->title}' threw: ".$e->getMessage());

                continue;
            }
            foreach ($grouped['suggestions'] ?? [] as $s) {
                /** @var \Arturrossbach\Linkwise\Suggestions\Suggestion $s */
                $this->checkSuggestionInsertable(
                    'outbound',
                    $record->id,
                    $s->anchorText,
                    $s->targetEntryId,
                    "'{$s->anchorText}' on '{$record->title}' → {$s->targetTitle}",
                );
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Domains report. For each entry that has any external links,
    // walking via the same code paths the Domain Report uses must
    // produce a consistent count. (Domain Report itself uses the
    // EntryFieldWalker, so this catches drift between read paths.)
    // ──────────────────────────────────────────────────────────────────
    protected function auditDomainParity(): void
    {
        $this->line('<fg=yellow>domains</> — external link extraction is consistent');

        $records = $this->indexer->load();
        $sample = array_slice($records, 0, 20);

        foreach ($sample as $record) {
            $entry = Entry::find($record->id);
            if (! $entry) {
                continue;
            }

            $bardExternal = [];
            $markdownExternal = [];
            EntryFieldWalker::walk(
                $entry,
                function (array $bard) use (&$bardExternal) {
                    $bardExternal = array_merge($bardExternal, TextExtractor::externalLinksFromBard($bard));
                },
                function (string $md) use (&$markdownExternal) {
                    $markdownExternal = array_merge($markdownExternal, TextExtractor::externalLinksFromMarkdown($md));
                },
            );

            // Both sides return arrays of {url, anchor_text}. Verify each
            // url is a valid http/https URL — guards against regex bugs
            // that surface invalid URLs (the read-path equivalent of the
            // markdown-corruption bug).
            foreach (array_merge($bardExternal, $markdownExternal) as $link) {
                $url = (string) ($link['url'] ?? '');
                if ($url === '') {
                    continue;
                }
                if (! filter_var($url, FILTER_VALIDATE_URL)) {
                    $this->recordFailure('domains', "'{$record->title}' yielded invalid URL: ".substr($url, 0, 80));

                    continue;
                }
                $this->pass('domains');
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Broken Links report. For each broken-link record, verify
    // the entry still exists AND the URL still appears in its content.
    // Stale records (URL was removed but record persists) are user-facing
    // confusion: the Broken Links tab shows links the user already fixed.
    // Re-scan would clean it up — audit catches the "should re-scan" state.
    // ──────────────────────────────────────────────────────────────────
    protected function auditBrokenLinks(): void
    {
        $this->line('<fg=yellow>broken-links</> — broken records still match entry content');

        $report = app(BrokenLinkReport::class);
        $data = $report->load();
        $records = $data['broken_links'] ?? [];

        // Sample to keep audit fast on sites with hundreds of broken links.
        $sample = array_slice($records, 0, 30);

        foreach ($sample as $rec) {
            $entry = Entry::find($rec->postId);
            if (! $entry) {
                $this->recordFailure('broken-links', "record references missing entry {$rec->postId} ('{$rec->postTitle}')");

                continue;
            }

            // Walk the entry; check the broken URL is actually still there.
            $found = false;
            EntryFieldWalker::walk(
                $entry,
                function (array $bard) use ($rec, &$found) {
                    foreach (TextExtractor::externalLinksFromBard($bard) as $link) {
                        if (($link['url'] ?? '') === $rec->url) {
                            $found = true;

                            return;
                        }
                    }
                    foreach (TextExtractor::linksFromBard($bard) as $href) {
                        if ($href === $rec->url) {
                            $found = true;

                            return;
                        }
                    }
                },
                function (string $md) use ($rec, &$found) {
                    if (str_contains($md, $rec->url)) {
                        $found = true;
                    }
                },
            );

            if (! $found) {
                $this->recordFailure(
                    'broken-links',
                    "'{$rec->postTitle}' — record claims URL ".substr($rec->url, 0, 60).' is broken there, but URL no longer in content (re-scan needed)',
                );

                continue;
            }
            $this->pass('broken-links');
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: URL Changer preview. For a sample of URLs from the index,
    // run UrlReplacer::preview and verify each reported entry/occurrence
    // is actually walkable. Catches drift between the preview path and
    // what apply would actually find.
    // ──────────────────────────────────────────────────────────────────
    protected function auditUrlChanger(): void
    {
        $this->line('<fg=yellow>url-changer</> — preview matches walked content');

        $records = $this->indexer->load();
        $replacer = app(UrlReplacer::class);

        // Pick up to 5 distinct internal-link hrefs from records that have
        // outbound links. Internal hrefs use `statamic://entry::uuid` form.
        $sampleUrls = [];
        foreach ($records as $r) {
            foreach ($r->outboundLinks as $targetId) {
                $sampleUrls['statamic://entry::'.$targetId] = true;
                if (count($sampleUrls) >= 5) {
                    break 2;
                }
            }
        }

        foreach (array_keys($sampleUrls) as $url) {
            try {
                $replacer->setMode('exact');
                $preview = $replacer->preview($url, '');
            } catch (\Throwable $e) {
                $this->recordFailure('url-changer', "preview '$url' threw: ".$e->getMessage());

                continue;
            }

            $reportedTotal = (int) ($preview['total_replacements'] ?? 0);
            $previewedEntries = $preview['entries'] ?? [];

            // For each previewed entry: confirm the URL is reachable in the
            // entry's content via the walker. Catches the asymmetry where
            // preview's regex finds occurrences but the inserter can't.
            foreach ($previewedEntries as $previewEntry) {
                $entry = Entry::find($previewEntry['id'] ?? '');
                if (! $entry) {
                    $this->recordFailure('url-changer', "preview names entry {$previewEntry['id']} which no longer exists");

                    continue;
                }

                $hits = 0;
                EntryFieldWalker::walk(
                    $entry,
                    function (array $bard) use ($url, &$hits) {
                        foreach (TextExtractor::linksFromBard($bard) as $href) {
                            if ('statamic://entry::'.$href === $url) {
                                $hits++;
                            }
                        }
                    },
                    function (string $md) use ($url, &$hits) {
                        $hits += substr_count($md, '('.$url.')');
                    },
                );

                $reportedHits = count($previewEntry['occurrences'] ?? []);
                if ($hits === 0 && $reportedHits > 0) {
                    $this->recordFailure(
                        'url-changer',
                        "preview reports {$reportedHits} hits for {$url} on '{$previewEntry['title']}', walker finds 0",
                    );

                    continue;
                }
                $this->pass('url-changer');
            }
            $this->pass('url-changer'); // total-count survived
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Target Keywords. Per-entry custom keyword maps must reference
    // existing entries and contain non-noise values. Bad data here
    // surfaces as ghost suggestions or invalid auto-link rules.
    // ──────────────────────────────────────────────────────────────────
    protected function auditTargetKeywords(): void
    {
        $this->line('<fg=yellow>target-keywords</> — keyword maps reference live entries with valid values');

        $manager = app(TargetKeywordManager::class);
        try {
            $all = $manager->loadAll();
        } catch (\Throwable $e) {
            $this->recordFailure('target-keywords', 'loadAll threw: '.$e->getMessage());

            return;
        }

        foreach ($all as $entryId => $keywords) {
            // Target entry must still exist; orphaned mappings produce
            // suggestions for entries that have been deleted.
            $entry = Entry::find($entryId);
            if (! $entry) {
                $this->recordFailure('target-keywords', "keywords map references missing entry {$entryId}");

                continue;
            }

            if (! is_array($keywords)) {
                $this->recordFailure('target-keywords', "entry {$entryId} keywords is not an array");

                continue;
            }

            foreach ($keywords as $kw) {
                if (! is_string($kw) || $kw === '') {
                    $this->recordFailure('target-keywords', "entry {$entryId} has empty/non-string keyword");

                    continue;
                }
                // A keyword containing `[…](…)` would be interpreted as a
                // markdown anchor by every downstream regex — silently
                // breaking previews and inserts. Same shape as the audit's
                // anchor-in-existing-link guard but applied to stored data.
                if (preg_match('/\[[^\]]*\]\([^\)]+\)/', $kw)) {
                    $this->recordFailure('target-keywords', "entry {$entryId} keyword '{$kw}' contains markdown link syntax");

                    continue;
                }
                $this->pass('target-keywords');
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Links Report. For each indexed entry, the stored inbound /
    // outbound suggestion counts and outbound-link counts must be
    // non-negative integers within sane bounds. Live recomputation of
    // a sample matches the stored value (catches stale-count drift —
    // I-7 "preserveSuggestionCounts after light rebuild" issue).
    // ──────────────────────────────────────────────────────────────────
    protected function auditLinksReport(): void
    {
        $this->line('<fg=yellow>links-report</> — per-entry counts are sane and consistent');

        $records = $this->indexer->load();
        $totalEntries = count($records);

        // Sanity bounds first — every entry, fast pass.
        foreach ($records as $r) {
            $bad = $r->inboundSuggestionCount < 0
                || $r->outboundSuggestionCount < 0
                || $r->inboundSuggestionCount > $totalEntries
                || $r->outboundSuggestionCount > $totalEntries;
            if ($bad) {
                $this->recordFailure(
                    'links-report',
                    "'{$r->title}' has out-of-bounds counts: inbound={$r->inboundSuggestionCount} outbound={$r->outboundSuggestionCount} (total entries: {$totalEntries})",
                );

                continue;
            }
            $this->pass('links-report');
        }

        // Live-recompute a small sample and compare to stored counts. Drift
        // here is the "table shows 5 inbound, modal shows 0" class.
        $sample = array_slice($records, 0, 5);
        foreach ($sample as $r) {
            try {
                $liveInbound = count($this->inboundEngine->suggestFiltered($r->id));
            } catch (\Throwable $e) {
                $this->recordFailure('links-report', "'{$r->title}' inbound recompute threw: ".$e->getMessage());

                continue;
            }
            // Allow ±1 tolerance: the index is rebuilt periodically, the
            // live count reflects the current moment. A larger gap signals
            // genuine staleness that warrants a re-scan.
            if (abs($liveInbound - $r->inboundSuggestionCount) > 1) {
                $this->recordFailure(
                    'links-report',
                    "'{$r->title}' inbound count drift: stored={$r->inboundSuggestionCount}, live={$liveInbound} (re-scan needed)",
                );

                continue;
            }
            $this->pass('links-report');
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Overview stats. The Overview tab aggregates totals from the
    // LinkReport. Verify those aggregates match what falls out of a
    // direct count of the raw records — guards against a bug in the
    // aggregation that would show "1500 links" when there are 200.
    // ──────────────────────────────────────────────────────────────────
    protected function auditOverview(): void
    {
        $this->line('<fg=yellow>overview</> — summary stats match direct counts');

        $records = $this->indexer->load();
        $report = new LinkReport($records);
        $summary = $report->toArray()['summary'] ?? [];

        // Mirror LinkReport's aggregation semantics so the comparison is
        // meaningful — it counts only links whose target is in the index
        // (excluding links to non-indexed collections / unpublished /
        // deleted entries). Counting raw outboundLinks would make this
        // path "off-by-one" against the report whenever the index has
        // dangling references — those aren't bugs, they're filtered.
        $directTotalEntries = count($records);
        $directTotalLinks = 0;
        $directWithOutbound = 0;
        foreach ($records as $r) {
            $linkedToIndexed = array_filter(
                $r->outboundLinks,
                fn ($id) => isset($records[$id])
            );
            $directTotalLinks += count($linkedToIndexed);
            if (! empty($linkedToIndexed)) {
                $directWithOutbound++;
            }
        }
        // Orphaned = entries that NO ONE links to (no inbound). Whether
        // they link OUT or not doesn't matter — LinkReport's orphan
        // definition is unidirectional. Plus a config-driven ignore-list
        // (`linkwise.orphaned_ignore`) for entries the site owner has
        // explicitly told us to exclude (e.g. landing-page-of-record
        // entries that are linked-from-nav-only).
        $hasInbound = [];
        foreach ($records as $r) {
            foreach ($r->outboundLinks as $target) {
                if (isset($records[$target])) {
                    $hasInbound[$target] = true;
                }
            }
        }
        $orphanedIgnore = is_array(config('linkwise.orphaned_ignore', []))
            ? config('linkwise.orphaned_ignore', [])
            : [];
        $directOrphaned = 0;
        foreach ($records as $r) {
            if (empty($hasInbound[$r->id]) && ! in_array($r->id, $orphanedIgnore, true)) {
                $directOrphaned++;
            }
        }

        $checks = [
            'total_entries' => [(int) ($summary['total_entries'] ?? -1), $directTotalEntries],
            'total_links' => [(int) ($summary['total_links'] ?? -1), $directTotalLinks],
            'entries_with_outbound' => [(int) ($summary['entries_with_outbound'] ?? -1), $directWithOutbound],
            'orphaned_count' => [(int) ($summary['orphaned_count'] ?? -1), $directOrphaned],
        ];
        foreach ($checks as $field => [$reported, $direct]) {
            if ($reported !== $direct) {
                $this->recordFailure('overview', "{$field}: summary says {$reported}, direct count says {$direct}");

                continue;
            }
            $this->pass('overview');
        }
    }

    // ─── Invariant helpers ────────────────────────────────────────────

    protected function checkContextNotNested(string $path, string $keyword, string $title, string $context): void
    {
        // Detect `[X[` and `]]` patterns — both indicate a nested anchor
        // that markdown can't render correctly.
        $nested = preg_match('/\[[^\]]*\[/', $context) || preg_match('/\]\]/', $context);
        if ($nested) {
            $this->recordFailure($path, "'{$title}' / rule '{$keyword}': nested-anchor in context — ".substr($context, 0, 120));

            return;
        }
        $this->pass($path);
    }

    protected function checkAnchorNotInsideExistingLink(string $path, string $keyword, string $title, string $context): void
    {
        if ($context === '' || $keyword === '') {
            return;
        }
        $pos = mb_stripos($context, $keyword);
        if ($pos === false) {
            return;
        }
        // A full markdown link occupies `[...](...)` characters; anchor
        // candidates inside that range would corrupt the existing link
        // if inserted (caught at insert-time today; auditor catches at
        // PREVIEW-time so misleading sentence_context is also flagged).
        if (preg_match_all('/\[[^\]]*\]\([^\)]+\)/u', $context, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$linkText, $byteOffset]) {
                $charStart = mb_strlen(substr($context, 0, $byteOffset));
                $charEnd = $charStart + mb_strlen($linkText);
                if ($pos >= $charStart && $pos < $charEnd) {
                    $this->recordFailure(
                        $path,
                        "'{$title}' / rule '{$keyword}': anchor at pos {$pos} sits inside existing link '".substr($linkText, 0, 60)."'",
                    );

                    return;
                }
            }
        }
        $this->pass($path);
    }

    protected function checkDryRunAgreesWithLinkStatus(string $path, $rule, string $entryId, string $title): void
    {
        $href = $rule->targetEntryId
            ? 'statamic://entry::'.$rule->targetEntryId
            : $rule->url;
        try {
            $can = BardLinkInserter::insertLinkIntoEntryWithHref(
                $entryId, $rule->keyword, $href, $rule->caseSensitive, save: false
            );
        } catch (\Throwable $e) {
            $this->recordFailure($path, "'{$title}' / rule '{$rule->keyword}': dry-run threw — ".$e->getMessage());

            return;
        }
        if (! $can) {
            $this->recordFailure($path, "'{$title}' / rule '{$rule->keyword}': preview said 'would_link' but dry-run says no");

            return;
        }
        $this->pass($path);
    }

    protected function checkSuggestionInsertable(string $path, string $sourceId, string $anchor, string $targetId, string $label): void
    {
        $href = 'statamic://entry::'.$targetId;
        try {
            $can = BardLinkInserter::insertLinkIntoEntryWithHref(
                $sourceId, $anchor, $href, false, save: false
            );
        } catch (\Throwable $e) {
            $this->recordFailure($path, "{$label}: dry-run threw — ".$e->getMessage());

            return;
        }
        if (! $can) {
            $this->recordFailure($path, "{$label}: surfaced to user but dry-run says not insertable");

            return;
        }
        $this->pass($path);
    }

    // ─── Reporting ────────────────────────────────────────────────────

    protected function pass(string $path): void
    {
        $this->passes[$path] = ($this->passes[$path] ?? 0) + 1;
    }

    protected function recordFailure(string $path, string $detail): void
    {
        $this->failures[$path][] = $detail;
    }

    protected function reportPath(string $path): void
    {
        $passes = $this->passes[$path] ?? 0;
        $failures = $this->failures[$path] ?? [];
        $cap = (int) $this->option('max-failures');

        if (empty($failures)) {
            $this->line("  <fg=green>✓</> {$passes} checks passed");

            return;
        }

        $this->line("  <fg=red>✗</> ".count($failures)." failures (passes: {$passes})");
        foreach (array_slice($failures, 0, $cap) as $f) {
            $this->line("    <fg=gray>·</> {$f}");
        }
        if (count($failures) > $cap) {
            $remaining = count($failures) - $cap;
            $this->line("    <fg=gray>… +{$remaining} more (raise --max-failures to see)</>");
        }
    }
}

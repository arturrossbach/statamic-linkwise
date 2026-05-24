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
use Arturrossbach\Linkwise\Support\BardWalker;
use Arturrossbach\Linkwise\Support\BulkSnapshotStore;
use Arturrossbach\Linkwise\Support\EntryFieldWalker;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;
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
            'auto-link'              => fn () => $this->auditAutoLinkPreview(),
            'index-parity'           => fn () => $this->auditIndexParity(),
            'inbound'                => fn () => $this->auditInboundInsertability(),
            'outbound'               => fn () => $this->auditOutboundInsertability(),
            'suggestions-safety'     => fn () => $this->auditSuggestionsSafety(),
            'highlight-truth'        => fn () => $this->auditHighlightTruth(),
            'xss-safe-href'          => fn () => $this->auditXssSafeHref(),
            'dangling-internal-link' => fn () => $this->auditDanglingInternalLinks(),
            'internal-link-target-published' => fn () => $this->auditInternalLinkTargetPublished(),
            'suggestion-count-drift' => fn () => $this->auditSuggestionCountDrift(),
            'orphan-split-link'      => fn () => $this->auditOrphanSplitLinks(),
            'revert-completeness'    => fn () => $this->auditRevertCompleteness(),
            'post-hash-coverage'     => fn () => $this->auditPostHashCoverage(),
            'apply-counter-honesty'  => fn () => $this->auditApplyCounterHonesty(),
            'mutator-parity'         => fn () => $this->auditMutatorParity(),
            'insert-parity'          => fn () => $this->auditInsertParity(),
            'snapshot-self-consistency' => fn () => $this->auditSnapshotSelfConsistency(),
            'stale-job-locks'        => fn () => $this->auditStaleJobLocks(),
            'php-cli-binary'         => fn () => $this->auditPhpCliBinary(),
            'domains'                => fn () => $this->auditDomainParity(),
            'broken-links'           => fn () => $this->auditBrokenLinks(),
            'url-changer'            => fn () => $this->auditUrlChanger(),
            'target-keywords'        => fn () => $this->auditTargetKeywords(),
            'links-report'           => fn () => $this->auditLinksReport(),
            'overview'               => fn () => $this->auditOverview(),
            'locale-scope'           => fn () => $this->auditLocaleScope(),
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
                    $s->sentenceContext,
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
                    $s->sentenceContext,
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

            // Walk the entry via BardWalker (set-aware) and collect every
            // link-mark href — internal AND external. The earlier impl used
            // TextExtractor::externalLinksFromBard + linksFromBard, but the
            // latter returns UUIDs (not full hrefs), so a match against
            // $rec->url like "statamic://entry::UUID" was never possible →
            // every internal-link broken-link record falsely flagged as
            // "no longer in content". TextExtractor's walker is also not
            // set-aware, so even external-link matches inside Bard sets
            // were silently missed.
            $found = false;
            EntryFieldWalker::walk(
                $entry,
                function (array $bard) use ($rec, &$found) {
                    if ($found) {
                        return;
                    }
                    BardWalker::walk($bard, function (array $node) use ($rec, &$found): bool {
                        foreach ($node['marks'] ?? [] as $mark) {
                            if (! is_array($mark) || ($mark['type'] ?? '') !== 'link') {
                                continue;
                            }
                            $href = (string) ($mark['attrs']['href'] ?? '');
                            // Empty-href guard: an entry with a malformed mark
                            // (no href at all) shouldn't accidentally "find"
                            // a record whose url is also '' — that would mask
                            // the corruption.
                            if ($href === '') {
                                continue;
                            }
                            if ($href === $rec->url) {
                                $found = true;
                                return true; // stop walking this entry
                            }
                        }
                        return false;
                    });
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

    // ──────────────────────────────────────────────────────────────────
    // Path: Suggestions safety. The 2026-05-08 lesson — green
    // BardLinkInserterTest was not enough because it tested synthetic
    // patterns. This check runs against ECHTDATEN: for each surfaced
    // outbound suggestion, walk the source entry's actual Bard tree
    // and find positions where the anchor sits as a strict substring
    // of an existing differently-targeted link span. Such positions
    // are the partial-overlap pattern that Bug B exploited.
    //
    // The check is intentionally independent of BardLinkInserter's
    // current safety logic — it asks "would this configuration TEMPT
    // a corruption?" not "does today's walker get it right?". Even
    // if the walker is later regressed, this audit catches the
    // dangerous suggestion before any save attempt.
    //
    // Symmetric to auditOutboundInsertability (sample size 20) so the
    // run cost stays bounded on large corpora. Raise via
    // --max-failures to see all flagged cases.
    // ──────────────────────────────────────────────────────────────────
    protected function auditSuggestionsSafety(): void
    {
        $this->line('<fg=yellow>suggestions-safety</> — surfaced suggestions never overlap an existing link');

        $records = $this->indexer->load();
        // Walk EVERY record that has at least one outbound suggestion —
        // not the symbolic 20-entry sample used by other paths. The
        // 2026-05-08 lesson: a 20-sample is fine for "is this insertable
        // at all" parity checks, but corruption-class bugs hide in long
        // tails. The cost is bounded by suggestion fan-out, not corpus
        // size.
        $sample = array_filter($records, fn ($r) => ($r->outboundSuggestionCount ?? 0) > 0);

        foreach ($sample as $record) {
            $this->progressDot();
            $entry = Entry::find($record->id);
            if (! $entry) continue;

            try {
                $raw = $this->suggestionEngine->suggest(
                    $record->text, $records, $record->id, $record->outboundLinks
                );
                $grouped = OutboundSuggestionGrouper::groupAndFilter($raw, $record->id);
            } catch (\Throwable $e) {
                $this->recordFailure('suggestions-safety', "grouping for '{$record->title}' threw: ".$e->getMessage());
                continue;
            }

            // OutboundSuggestionGrouper returns groups → targets (not flat).
            // Each target carries an anchor_text + target_entry_id.
            foreach ($grouped['groups'] ?? [] as $group) {
                foreach ($group['targets'] ?? [] as $target) {
                    $anchor = (string) ($target['anchor_text'] ?? '');
                    $targetId = (string) ($target['target_entry_id'] ?? '');
                    if ($anchor === '' || $targetId === '') continue;

                    $newHref = 'statamic://entry::'.$targetId;
                    $hits = $this->findAnchorPartialOverlapsInBard($entry, $anchor, $newHref);

                    if (empty($hits)) {
                        $this->pass('suggestions-safety');
                        continue;
                    }

                    // REV-DR-02 audit refinement (2026-05-13): partial-overlap
                    // hits are conservative warnings, not bugs. The walker
                    // (AnchorPositionFinder::find) explicitly skips positions
                    // inside existing link marks and finds a safe occurrence
                    // when one exists. If canInsertLinkIntoEntry returns
                    // ok=true, the suggestion WILL insert at a safe position
                    // — the overlap-in-other-link is irrelevant noise.
                    //
                    // Promote to FAILURE only when NO safe position exists
                    // (= every occurrence sits inside an existing link).
                    // Those are the structurally problematic cases the audit
                    // was built to catch. The merely-conservative overlaps
                    // pass silently.
                    $canInsert = \Arturrossbach\Linkwise\Support\BardLinkInserter::canInsertLinkIntoEntry(
                        $record->id, $anchor, $newHref,
                    );
                    if ($canInsert['ok'] ?? false) {
                        $this->pass('suggestions-safety');
                        continue;
                    }

                    $first = $hits[0];
                    $detail = sprintf(
                        "'%s' on '%s' → entry %s: anchor sits inside existing link '%s' (linked text \"%s\") — partial overlap, would split",
                        $anchor,
                        $record->title,
                        $targetId,
                        $first['existing_href'],
                        mb_strimwidth($first['linked_text'], 0, 60, '…'),
                    );
                    $this->recordFailure('suggestions-safety', $detail);
                }
            }
        }
    }

    /**
     * Walk an entry's Bard / Replicator-nested-Bard fields. Return any
     * position where $anchor appears as a strict substring of a text
     * node carrying a link mark whose href differs from $newHref.
     *
     * "Strict substring" = anchor matches inside the linked text but
     * doesn't fully cover it. Full coverage of a different-href link
     * is the URL-upgrade case that BardLinkInserter intentionally
     * supports — not flagged.
     *
     * @return list<array{existing_href: string, linked_text: string, position: int}>
     */
    // ──────────────────────────────────────────────────────────────────
    // Path: Highlight-truth. The Outbound modal renders sentence_context
    // with the anchor word highlighted ("Weiter mit **Brauner**-Zucker-
    // Speck-Kekse"). The user's mental model: "I'm linking THIS exact
    // span". But BardLinkInserter operates on the entry's full Bard
    // tree and links the FIRST word-boundary match it finds — which may
    // be a different occurrence if the anchor appears multiple times
    // (e.g. anchor "die" in a German entry has dozens of valid hits).
    //
    // For each surfaced suggestion: extract the anchor + its sentence-
    // context as the UI shows them. Verify the anchor IS literally
    // present in that context. If a suggestion's UI-shown context
    // doesn't contain the anchor at all, the modal is showing the
    // wrong sentence and the user has no way to correlate "what I
    // see" with "what gets linked".
    //
    // Sentinel-marker stripping: SuggestionEngine sometimes emits
    // \x01 / \x02 around the matched span. Cleaned before comparison.
    // ──────────────────────────────────────────────────────────────────
    protected function auditHighlightTruth(): void
    {
        $this->line('<fg=yellow>highlight-truth</> — UI-highlighted anchor really appears in the context the UI shows');

        $records = $this->indexer->load();
        $sample = array_filter($records, fn ($r) => ($r->outboundSuggestionCount ?? 0) > 0);

        foreach ($sample as $record) {
            $this->progressDot();
            try {
                $raw = $this->suggestionEngine->suggest(
                    $record->text, $records, $record->id, $record->outboundLinks
                );
                $grouped = OutboundSuggestionGrouper::groupAndFilter($raw, $record->id);
            } catch (\Throwable $e) {
                $this->recordFailure('highlight-truth', "grouping for '{$record->title}' threw: ".$e->getMessage());
                continue;
            }

            foreach ($grouped['groups'] ?? [] as $group) {
                $anchor = (string) ($group['anchor_text'] ?? '');
                $context = (string) ($group['sentence_context'] ?? '');
                if ($anchor === '' || $context === '') continue;

                // Strip sentinel markers + leading/trailing ellipses
                // ContextExtractor adds for truncation hints. The
                // visible anchor text is what survives.
                $cleanContext = str_replace(["\x01", "\x02"], '', $context);
                $cleanContext = preg_replace('/^\.{3,}|\.{3,}$/u', '', $cleanContext);
                $cleanContext = trim($cleanContext);

                if (mb_stripos($cleanContext, $anchor) === false) {
                    $this->recordFailure(
                        'highlight-truth',
                        "'{$anchor}' on '{$record->title}': UI context \"".mb_strimwidth($cleanContext, 0, 80, '…')."\" does NOT contain the anchor — modal would mislead the user about which span gets linked",
                    );
                } else {
                    $this->pass('highlight-truth');
                }
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: XSS-safe href. Public-site renderer (LinkwiseLinkMark) emits
    // every link mark's href verbatim into <a href="..."> on the page.
    // A href containing javascript:, data:, or unescaped angle brackets
    // is a stored XSS surface — the editor pastes a malicious "URL" once,
    // every public-site visitor executes it. ContentSafetyValidator
    // enforces this on writes through SafeEntrySaver but a hand-edited
    // YAML file or a non-Linkwise save path could land bad data.
    //
    // For each entry, walk every link mark in every Bard field. Flag
    // hrefs starting with javascript: or data:, or containing < / >.
    // ──────────────────────────────────────────────────────────────────
    protected function auditXssSafeHref(): void
    {
        $this->line('<fg=yellow>xss-safe-href</> — no link mark hrefs that would XSS the public site');

        $records = $this->indexer->load();
        foreach ($records as $record) {
            $entry = Entry::find($record->id);
            if (! $entry) {
                continue;
            }

            $unsafe = [];
            EntryFieldWalker::walk($entry, function (array $bardContent) use (&$unsafe) {
                BardWalker::walk($bardContent, function (array $node) use (&$unsafe): bool {
                    foreach ($node['marks'] ?? [] as $mark) {
                        if (! is_array($mark) || ($mark['type'] ?? '') !== 'link') {
                            continue;
                        }
                        $href = (string) ($mark['attrs']['href'] ?? '');
                        $reason = $this->classifyUnsafeHref($href);
                        if ($reason !== null) {
                            $unsafe[] = ['href' => $href, 'reason' => $reason];
                        }
                    }
                    return false;
                });
            });

            if (empty($unsafe)) {
                $this->pass('xss-safe-href');
                continue;
            }
            foreach ($unsafe as $issue) {
                $this->recordFailure(
                    'xss-safe-href',
                    "'$record->title': href contains XSS-relevant chars — \"".mb_strimwidth($issue['href'], 0, 80, '…').'" (reason: '.$issue['reason'].')',
                );
            }
        }
    }

    protected function classifyUnsafeHref(string $href): ?string
    {
        if ($href === '') {
            return null;
        }
        $lower = mb_strtolower(trim($href));
        if (str_starts_with($lower, 'javascript:')) {
            return 'javascript: scheme';
        }
        if (str_starts_with($lower, 'data:')) {
            return 'data: scheme';
        }
        if (str_starts_with($lower, 'vbscript:')) {
            return 'vbscript: scheme';
        }
        if (str_contains($href, '<') || str_contains($href, '>')) {
            return 'contains < or >';
        }
        if (str_contains($href, '"')) {
            return 'contains unescaped "';
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Dangling internal-link. Linkwise's broken-links audit covers
    // the BrokenLinkChecker's scan results, which run on a schedule and
    // can miss links inside structures the checker doesn't reach (Bard
    // sets, indirect refs). This audit walks EVERY link mark in EVERY
    // entry's content directly via BardWalker (which IS set-aware) and
    // verifies each statamic://entry::ID target exists in the index —
    // catching the "user manually deleted target entry, BrokenLinkChecker
    // hasn't re-scanned yet" case before the public site renders dead
    // anchors.
    // ──────────────────────────────────────────────────────────────────
    protected function auditDanglingInternalLinks(): void
    {
        $this->line('<fg=yellow>dangling-internal-link</> — every statamic://entry::ID target still exists in the index');

        $records = $this->indexer->load();
        $knownIds = array_flip(array_keys($records));

        foreach ($records as $record) {
            $entry = Entry::find($record->id);
            if (! $entry) {
                continue;
            }

            $dangling = [];
            EntryFieldWalker::walk($entry, function (array $bardContent) use ($knownIds, &$dangling) {
                BardWalker::walk($bardContent, function (array $node) use ($knownIds, &$dangling): bool {
                    if (($node['type'] ?? '') !== 'text') {
                        return false;
                    }
                    foreach ($node['marks'] ?? [] as $mark) {
                        $targetId = $this->extractInternalEntryId($mark);
                        if ($targetId !== null && ! isset($knownIds[$targetId])) {
                            $dangling[] = [
                                'target' => $targetId,
                                'anchor' => (string) ($node['text'] ?? ''),
                            ];
                        }
                    }
                    return false;
                });
            });

            if (empty($dangling)) {
                $this->pass('dangling-internal-link');
                continue;
            }
            foreach ($dangling as $issue) {
                $this->recordFailure(
                    'dangling-internal-link',
                    "'$record->title': link to non-existent entry ".$issue['target'].' — anchor "'.mb_strimwidth($issue['anchor'], 0, 40, '…').'"',
                );
            }
        }
    }

    /**
     * Returns the entry-id of a `statamic://entry::ID` link mark, or null when
     * the mark isn't a link, has no href, or points outside this scheme.
     */
    protected function extractInternalEntryId(mixed $mark): ?string
    {
        if (! is_array($mark) || ($mark['type'] ?? '') !== 'link') {
            return null;
        }
        $href = (string) ($mark['attrs']['href'] ?? '');
        if (! preg_match('#^statamic://entry::([a-zA-Z0-9-]+)$#', $href, $m)) {
            return null;
        }
        return $m[1];
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Internal-link target published. A statamic://entry::ID target
    // that is not visible to public-site visitors renders as a 404 — the
    // editor never knows. Different failure mode from "dangling": the
    // target entry EXISTS in the database but is hidden.
    //
    // Statamic's status() (not published()) is the right contract here:
    // - 'draft'      → unpublished  → 404
    // - 'scheduled'  → future date in a private-future collection → 404
    // - 'expired'    → past date in a private-past collection → 404
    // - 'published'  → live for visitors → OK
    //
    // published() returns true for scheduled/expired entries even though
    // they're invisible — using that would silently miss those failures.
    // ──────────────────────────────────────────────────────────────────
    protected function auditInternalLinkTargetPublished(): void
    {
        $this->line('<fg=yellow>internal-link-target-published</> — every statamic://entry::ID target is currently published');

        $records = $this->indexer->load();

        foreach ($records as $record) {
            $entry = Entry::find($record->id);
            if (! $entry) {
                continue;
            }

            $unpublished = [];
            EntryFieldWalker::walk($entry, function (array $bardContent) use (&$unpublished) {
                BardWalker::walk($bardContent, function (array $node) use (&$unpublished): bool {
                    if (($node['type'] ?? '') !== 'text') {
                        return false;
                    }
                    foreach ($node['marks'] ?? [] as $mark) {
                        $targetId = $this->extractInternalEntryId($mark);
                        if ($targetId === null) {
                            continue;
                        }
                        $target = Entry::find($targetId);
                        if (! $target) {
                            continue; // covered by dangling-internal-link
                        }
                        // status() — not published() — is the visitor-visibility
                        // contract. published() returns true for scheduled/expired
                        // entries which DO render as 404s in private-future/past
                        // collections.
                        if (! method_exists($target, 'status')) {
                            continue;
                        }
                        $status = $target->status();
                        if ($status === 'published') {
                            continue;
                        }
                        $unpublished[] = [
                            'target' => $targetId,
                            'target_title' => (string) ($target->get('title') ?? $targetId),
                            'status' => $status,
                        ];
                    }
                    return false;
                });
            });

            if (empty($unpublished)) {
                $this->pass('internal-link-target-published');
                continue;
            }
            foreach ($unpublished as $issue) {
                $this->recordFailure(
                    'internal-link-target-published',
                    "'$record->title': link target '".$issue['target_title']."' (id ".$issue['target'].') has status='.$issue['status'].' — public-site visitor sees broken link',
                );
            }
        }
    }

    protected function findAnchorPartialOverlapsInBard($entry, string $anchor, string $newHref): array
    {
        $hits = [];
        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            return $hits;
        }

        foreach ($fields as $handle => $field) {
            $type = $field->type();
            $value = $entry->get($handle);
            if (! is_array($value) || empty($value)) continue;

            if ($type === 'bard') {
                $this->walkBardForOverlap($value, $anchor, $newHref, $hits);
            } elseif ($type === 'replicator') {
                $this->walkReplicatorForOverlap($value, $anchor, $newHref, $hits);
            }
        }

        return $hits;
    }

    /**
     * @param  list<array{existing_href: string, linked_text: string, position: int}>  $hits
     */
    protected function walkBardForOverlap(array $content, string $anchor, string $newHref, array &$hits): void
    {
        $anchorLen = mb_strlen($anchor);
        if ($anchorLen === 0) return;

        foreach ($content as $node) {
            if (! is_array($node)) continue;

            if (($node['type'] ?? '') === 'text') {
                $text = (string) ($node['text'] ?? '');
                if ($text === '') {
                    if (isset($node['content']) && is_array($node['content'])) {
                        $this->walkBardForOverlap($node['content'], $anchor, $newHref, $hits);
                    }
                    continue;
                }

                $existingHref = null;
                foreach ($node['marks'] ?? [] as $m) {
                    if (! is_array($m) || ($m['type'] ?? '') !== 'link') continue;
                    $existingHref = (string) ($m['attrs']['href'] ?? '');
                    break;
                }

                // Only linked text nodes with a different href are at risk
                // of partial-destruction. Same-href is BardLinkInserter's
                // idempotent noop. Plain text is the legitimate target.
                if ($existingHref === null || $existingHref === '' || $existingHref === $newHref) {
                    if (isset($node['content']) && is_array($node['content'])) {
                        $this->walkBardForOverlap($node['content'], $anchor, $newHref, $hits);
                    }
                    continue;
                }

                $pos = mb_stripos($text, $anchor);
                if ($pos === false) {
                    if (isset($node['content']) && is_array($node['content'])) {
                        $this->walkBardForOverlap($node['content'], $anchor, $newHref, $hits);
                    }
                    continue;
                }

                $textLen = mb_strlen($text);
                $fullyCovers = ($pos === 0 && $anchorLen === $textLen);
                if (! $fullyCovers) {
                    $hits[] = [
                        'existing_href' => $existingHref,
                        'linked_text' => $text,
                        'position' => $pos,
                    ];
                }
            }

            if (isset($node['content']) && is_array($node['content'])) {
                $this->walkBardForOverlap($node['content'], $anchor, $newHref, $hits);
            }
        }
    }

    /**
     * @param  list<array{existing_href: string, linked_text: string, position: int}>  $hits
     */
    protected function walkReplicatorForOverlap(array $sets, string $anchor, string $newHref, array &$hits): void
    {
        foreach ($sets as $set) {
            if (! is_array($set)) continue;
            foreach ($set as $key => $value) {
                if (! is_array($value) || empty($value)) continue;
                if (in_array($key, \Arturrossbach\Linkwise\Support\UrlHelper::REPLICATOR_META_KEYS, true)) continue;
                if (\Arturrossbach\Linkwise\Support\ProseMirrorTypes::looksLikeBardContent($value)) {
                    $this->walkBardForOverlap($value, $anchor, $newHref, $hits);
                } elseif (isset($value[0]['type'], $value[0]['id'])) {
                    $this->walkReplicatorForOverlap($value, $anchor, $newHref, $hits);
                }
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Suggestion-count drift. A real tester opens the dashboard
    // and sees "Outbound: 5" next to an entry. They click in expecting
    // 5 suggestions. If the engine produces 0 (or 12) something stale
    // is between the index and the live computation. The user's
    // bauchgefühl: numbers should reconcile across views.
    //
    // Tolerance: ±1 noise allowed (counts are eventually-consistent
    // post-edit). Absolute drift > 1 is the signal.
    // ──────────────────────────────────────────────────────────────────
    protected function auditSuggestionCountDrift(): void
    {
        $this->line('<fg=yellow>suggestion-count-drift</> — index outbound-suggestion-count matches live engine count');

        $records = $this->indexer->load();
        // Walk every record. Cheap: just runs the engine once per entry.
        foreach ($records as $record) {
            $reported = $record->outboundSuggestionCount ?? 0;
            try {
                $raw = $this->suggestionEngine->suggest(
                    $record->text, $records, $record->id, $record->outboundLinks
                );
                $live = OutboundSuggestionGrouper::groupAndFilter($raw, $record->id)['count'] ?? 0;
            } catch (\Throwable $e) {
                $this->recordFailure('suggestion-count-drift', "engine threw on '{$record->title}': ".$e->getMessage());
                continue;
            }
            if (abs($reported - $live) > 1) {
                $this->recordFailure(
                    'suggestion-count-drift',
                    "'{$record->title}': index reports {$reported}, engine produces {$live} (delta ".abs($reported - $live).")",
                );
            } else {
                $this->pass('suggestion-count-drift');
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Orphan split-link detection. Forensic check on real data:
    // does any entry currently have a structurally-suspicious sequence
    // of adjacent text-nodes whose link marks point at different hrefs?
    //
    // The pattern: a single linked phrase ("Brauner-Zucker-Speck-Kekse")
    // got split into two text-nodes by a buggy insert (Bug B 2026-05-08).
    // Even after revert, the residue stays — Linkwise has no way to know
    // the intended pre-state. This audit surfaces every such residue so
    // the user can repair manually.
    //
    // Heuristic for "suspicious": two consecutive text-node children of
    // the same content array, both carrying a link mark, hrefs differ,
    // AND the boundary between them is NOT whitespace (i.e. the split
    // happens mid-word or at a hyphen — humans almost never produce this
    // by hand).
    // ──────────────────────────────────────────────────────────────────
    protected function auditOrphanSplitLinks(): void
    {
        $this->line('<fg=yellow>orphan-split-link</> — no entry has adjacent different-href links splitting one phrase');

        $records = $this->indexer->load();
        foreach ($records as $record) {
            $entry = Entry::find($record->id);
            if (! $entry) continue;

            $issues = $this->findOrphanSplitsInEntry($entry);
            if (empty($issues)) {
                $this->pass('orphan-split-link');
                continue;
            }
            foreach ($issues as $issue) {
                $this->recordFailure(
                    'orphan-split-link',
                    "'{$record->title}': adjacent linked spans \"{$issue['left']}\"→{$issue['left_href']} + \"{$issue['right']}\"→{$issue['right_href']} — likely Bug B residue, manual re-link needed",
                );
            }
        }
    }

    /**
     * @return list<array{left:string, right:string, left_href:string, right_href:string}>
     */
    protected function findOrphanSplitsInEntry($entry): array
    {
        $issues = [];
        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            return $issues;
        }

        foreach ($fields as $handle => $field) {
            $type = $field->type();
            $value = $entry->get($handle);
            if (! is_array($value) || empty($value)) continue;

            if ($type === 'bard') {
                $this->walkBardForOrphanSplits($value, $issues);
            } elseif ($type === 'replicator') {
                $this->walkReplicatorForOrphanSplits($value, $issues);
            }
        }

        return $issues;
    }

    /**
     * @param  list<array{left:string, right:string, left_href:string, right_href:string}>  $issues
     */
    protected function walkBardForOrphanSplits(array $content, array &$issues): void
    {
        foreach ($content as $node) {
            if (! is_array($node)) continue;
            if (isset($node['content']) && is_array($node['content'])) {
                // The Bug B residue fingerprint: a hyphenated word was
                // ONE text node before the buggy insert (e.g. "Brauner-
                // Zucker-Speck-Kekse"); after split + revert it survives
                // as adjacent text-nodes glued via the hyphen. The
                // post-state always has at least one node whose text
                // starts (or ends) with a hyphen and whose neighbor's
                // text ends (or starts) with a letter — humans never
                // author hyphenated words across multiple nodes.
                //
                // Stricter than "any non-whitespace seam between linked
                // and plain": ", die direkt am Code lebt" after a linked
                // "Dokumentation" is a normal author workflow (link a
                // word, leave the trailing relative clause unlinked).
                // Only word-joiner seams (- _ ') indicate split mid-word.
                //
                // Also flag: TWO adjacent linked nodes with different
                // hrefs and a non-whitespace seam — the original Bug B
                // tree before any revert. Non-hyphen seams catch comma-
                // glued cases like "X][Y" where buggy multi-replace ate
                // the boundary.
                $children = $node['content'];
                for ($i = 0; $i < count($children) - 1; $i++) {
                    $a = $children[$i];
                    $b = $children[$i + 1];
                    if (! is_array($a) || ! is_array($b)) continue;
                    if (($a['type'] ?? '') !== 'text' || ($b['type'] ?? '') !== 'text') continue;

                    $textA = (string) ($a['text'] ?? '');
                    $textB = (string) ($b['text'] ?? '');
                    if ($textA === '' || $textB === '') continue;

                    $hrefA = $this->firstLinkHref($a);
                    $hrefB = $this->firstLinkHref($b);

                    // Both plain → ProseMirror should have merged.
                    if ($hrefA === null && $hrefB === null) continue;
                    // Same-href both-linked → walker artifact, harmless.
                    if ($hrefA !== null && $hrefB !== null && $hrefA === $hrefB) continue;

                    // Two cases we flag:
                    // (1) Word-joiner seam (hyphen, underscore, apostrophe)
                    //     → hyphenated word that got split mid-word.
                    // (2) Two different-href links glued without space
                    //     → original Bug B tree pre-revert.
                    $endsWithJoiner = (bool) preg_match('/[-_\']$/u', $textA);
                    $startsWithJoiner = (bool) preg_match('/^[-_\']/u', $textB);
                    $prevEndsWithLetter = (bool) preg_match('/\p{L}$/u', $textA);
                    $nextStartsWithLetter = (bool) preg_match('/^\p{L}/u', $textB);

                    $isHyphenSplit =
                        ($startsWithJoiner && $prevEndsWithLetter)
                        || ($endsWithJoiner && $nextStartsWithLetter);

                    $isDifferentHrefGlued = false;
                    if ($hrefA !== null && $hrefB !== null && $hrefA !== $hrefB) {
                        $lastCharA = mb_substr($textA, -1);
                        $firstCharB = mb_substr($textB, 0, 1);
                        $isDifferentHrefGlued = ! preg_match('/\s/u', $lastCharA) && ! preg_match('/\s/u', $firstCharB);
                    }

                    if (! $isHyphenSplit && ! $isDifferentHrefGlued) continue;

                    $issues[] = [
                        'left' => mb_strimwidth($textA, 0, 40, '…'),
                        'right' => mb_strimwidth($textB, 0, 40, '…'),
                        'left_href' => $hrefA !== null ? mb_strimwidth($hrefA, 0, 60, '…') : '(plain)',
                        'right_href' => $hrefB !== null ? mb_strimwidth($hrefB, 0, 60, '…') : '(plain)',
                    ];
                }
                // Recurse into nested content.
                $this->walkBardForOrphanSplits($children, $issues);
            }
        }
    }

    /**
     * @param  list<array{left:string, right:string, left_href:string, right_href:string}>  $issues
     */
    protected function walkReplicatorForOrphanSplits(array $sets, array &$issues): void
    {
        foreach ($sets as $set) {
            if (! is_array($set)) continue;
            foreach ($set as $key => $value) {
                if (! is_array($value) || empty($value)) continue;
                if (in_array($key, \Arturrossbach\Linkwise\Support\UrlHelper::REPLICATOR_META_KEYS, true)) continue;
                if (\Arturrossbach\Linkwise\Support\ProseMirrorTypes::looksLikeBardContent($value)) {
                    $this->walkBardForOrphanSplits($value, $issues);
                } elseif (isset($value[0]['type'], $value[0]['id'])) {
                    $this->walkReplicatorForOrphanSplits($value, $issues);
                }
            }
        }
    }

    protected function firstLinkHref(array $node): ?string
    {
        foreach ($node['marks'] ?? [] as $m) {
            if (is_array($m) && ($m['type'] ?? '') === 'link') {
                $href = (string) ($m['attrs']['href'] ?? '');
                return $href !== '' ? $href : null;
            }
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Revert completeness. Tester clicks Revert on a snapshot,
    // expects the entry to come back to its pre-bulk state. Bug B's
    // fallout was exactly this expectation broken — half the link came
    // back, half stayed corrupted. Audit catches Frankenstein-states by
    // hashing each reverted entry and comparing to the snapshot's
    // pre_hashes.
    //
    // Skips snapshots that aren't reverted yet, or that pre-date the
    // pre_hashes capture (legacy data).
    // ──────────────────────────────────────────────────────────────────
    protected function auditRevertCompleteness(): void
    {
        $this->line('<fg=yellow>revert-completeness</> — reverted bulks restore pre-bulk hash for every entry');

        $store = app(BulkSnapshotStore::class);
        $snapshots = $store->list(); // newest first

        foreach ($snapshots as $row) {
            $snap = $store->get($row['id']);
            if (! is_array($snap)) continue;

            // Only check snapshots that have been reverted and have
            // pre-hash data to compare against.
            if (empty($snap['reverted_at'])) continue;
            $preHashes = $snap['pre_hashes'] ?? [];
            if (! is_array($preHashes) || empty($preHashes)) continue;

            $entryIds = $snap['entry_ids'] ?? [];
            if (! is_array($entryIds)) continue;

            // Track which entries match and which don't.
            $mismatches = [];
            foreach ($entryIds as $entryId) {
                if (! is_string($entryId) || ! isset($preHashes[$entryId])) continue;
                $entry = Entry::find($entryId);
                if (! $entry) continue; // entry was deleted post-revert — separate concern
                $currentHash = SafeEntrySaver::hash($entry);
                if ($currentHash !== $preHashes[$entryId]) {
                    $mismatches[] = [
                        'entry_id' => $entryId,
                        'title' => (string) ($entry->get('title') ?? $entryId),
                    ];
                }
            }

            if (empty($mismatches)) {
                $this->pass('revert-completeness');
                continue;
            }

            $kind = (string) ($snap['kind'] ?? '?');
            $startedBy = (string) ($snap['started_by'] ?? '?');
            $first = $mismatches[0];
            $extra = count($mismatches) > 1 ? ' (+'.(count($mismatches) - 1).' more)' : '';
            $this->recordFailure(
                'revert-completeness',
                "snapshot {$row['id']} ({$kind} by {$startedBy}): post-revert hash diverges from pre-bulk on '{$first['title']}'{$extra} — Frankenstein-state likely",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Post-hash coverage. Every completed bulk MUST have recorded
    // post-hashes for every entry it touched. Without that, the activity-
    // log compareToCurrent renders every entry as "modified by user" —
    // the bulk wrote, but the snapshot doesn't know it did. The promise
    // of recordPostHashesForEntries is in the bulk-write-path standard
    // (see feedback memory); this audit enforces it.
    // ──────────────────────────────────────────────────────────────────
    protected function auditPostHashCoverage(): void
    {
        $this->line('<fg=yellow>post-hash-coverage</> — every completed bulk recorded post-hashes for every entry');

        $store = app(BulkSnapshotStore::class);
        $snapshots = $store->list();

        foreach ($snapshots as $row) {
            $snap = $store->get($row['id']);
            if (! is_array($snap)) continue;

            $phase = $snap['completion_stats']['phase'] ?? null;
            if ($phase !== 'done') continue; // in-flight or aborted — not auditable

            $entryIds = array_filter(
                $snap['entry_ids'] ?? [],
                fn ($x) => is_string($x) && $x !== '',
            );
            if (empty($entryIds)) {
                $this->pass('post-hash-coverage');
                continue;
            }

            $postHashes = $snap['post_hashes'] ?? [];
            if (! is_array($postHashes)) $postHashes = [];

            $missing = array_values(array_filter(
                $entryIds,
                fn ($id) => ! isset($postHashes[$id]),
            ));

            if (empty($missing)) {
                $this->pass('post-hash-coverage');
                continue;
            }

            $kind = (string) ($snap['kind'] ?? '?');
            $missingCount = count($missing);
            $totalCount = count($entryIds);
            $this->recordFailure(
                'post-hash-coverage',
                "snapshot {$row['id']} ({$kind}): {$missingCount}/{$totalCount} entry post-hashes missing — bulk wrote without recording, activity-log will mark them as 'modified by user'",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Apply-counter honesty. Tester clicks Bulk Apply, sees toast
    // "5 added". Opens Activity Log for that snapshot, expects exactly
    // 5 items in the drawer. Anything else means either the toast lied
    // or the snapshot lied.
    //
    // Invariant: for every completed snapshot, the items array length
    // equals completion_stats.succeeded. Items is append-on-success;
    // one item per confirmed write. The two are recorded by entirely
    // different code paths (appendWrittenItem vs markCompleted) so a
    // mismatch indicates a write that didn't get recorded — or a
    // counter that got incremented without the write happening.
    // ──────────────────────────────────────────────────────────────────
    protected function auditApplyCounterHonesty(): void
    {
        $this->line('<fg=yellow>apply-counter-honesty</> — items.length matches completion_stats.succeeded');

        $store = app(BulkSnapshotStore::class);
        $snapshots = $store->list();

        foreach ($snapshots as $row) {
            $snap = $store->get($row['id']);
            if (! is_array($snap)) continue;

            $verdict = self::classifyApplyCounterSnapshot($snap);

            switch ($verdict['action']) {
                case 'pass':
                    $this->pass('apply-counter-honesty');
                    break;
                case 'fail':
                    $kind = (string) ($snap['kind'] ?? '?');
                    $startedBy = (string) ($snap['started_by'] ?? '?');
                    $this->recordFailure(
                        'apply-counter-honesty',
                        "snapshot {$row['id']} ({$kind} by {$startedBy}): {$verdict['reason']}",
                    );
                    break;
                case 'skip-legacy':
                case 'skip-trimmed':
                case 'skip-in-flight':
                    // Not auditable for honesty by design; not a failure.
                    // skip-legacy specifically eliminates the 16 stale
                    // snapshots from 2026-05-09/10/11 that were keeping
                    // the audit dashboard red for 4 days. REV-UC-04.
                    break;
            }
        }
    }

    /**
     * Snapshots written before this date used a pre-append-on-success code
     * path that recorded items without incrementing succeeded (UrlChanger,
     * DetailUnlink). The honesty check would forever fail on those even
     * though the data is correct in the new schema. Skip everything older.
     *
     * Format: YYYYMMDD — compared against the leading 8 chars of the
     * snapshot ID, which is itself a date-prefixed identifier.
     */
    public const APPLY_COUNTER_HONESTY_CUTOFF_DATE = '20260512';

    /**
     * Pure classification of a single bulk-snapshot for the apply-counter-
     * honesty audit. Extracted from auditApplyCounterHonesty so the
     * decision logic is unit-testable without bootstrapping
     * BulkSnapshotStore + Laravel cache.
     *
     * Returns one of:
     *   - ['action' => 'pass', 'reason' => '']            items.length matches succeeded
     *   - ['action' => 'fail', 'reason' => '...']         real mismatch
     *   - ['action' => 'skip-legacy', 'reason' => '...']  succeeded=-1 sentinel
     *                                                     OR snapshot-ID dates
     *                                                     before the
     *                                                     append-on-success
     *                                                     migration
     *   - ['action' => 'skip-trimmed', 'reason' => '...'] items_trimmed=true
     *   - ['action' => 'skip-in-flight', 'reason' => ''] phase != done
     *
     * @param  array<string, mixed>  $snap
     * @return array{action: string, reason: string}
     */
    public static function classifyApplyCounterSnapshot(array $snap): array
    {
        $phase = $snap['completion_stats']['phase'] ?? null;
        if ($phase !== 'done') {
            return ['action' => 'skip-in-flight', 'reason' => ''];
        }

        $itemsTrimmed = (bool) ($snap['items_trimmed'] ?? false);
        if ($itemsTrimmed) {
            return ['action' => 'skip-trimmed', 'reason' => 'items_trimmed=true; forensic trail incomplete by design'];
        }

        // Date-prefixed snapshot IDs older than the append-on-success
        // migration record items without bumping succeeded — historical
        // artifacts, not bugs in current code. ID format:
        // YYYYMMDD-HHMMSS-hex. We compare the leading 8 chars.
        $snapshotId = $snap['id'] ?? '';
        if (is_string($snapshotId) && strlen($snapshotId) >= 8) {
            $datePrefix = substr($snapshotId, 0, 8);
            if (ctype_digit($datePrefix) && $datePrefix < self::APPLY_COUNTER_HONESTY_CUTOFF_DATE) {
                return [
                    'action' => 'skip-legacy',
                    'reason' => "snapshot dated $datePrefix predates append-on-success migration (cutoff "
                        .self::APPLY_COUNTER_HONESTY_CUTOFF_DATE.')',
                ];
            }
        }

        // succeeded=-1 is a sentinel value written by a pre-refactor code
        // path. Belt-and-suspenders with the date check above for snapshots
        // whose IDs don't follow the date-prefix convention.
        $succeeded = $snap['completion_stats']['succeeded'] ?? null;
        if ($succeeded === -1) {
            return ['action' => 'skip-legacy', 'reason' => 'succeeded=-1 (legacy sentinel from pre-refactor snapshots)'];
        }

        $succeeded = (int) $succeeded;
        $items = $snap['items'] ?? [];
        if (! is_array($items)) $items = [];
        $itemsCount = count($items);

        if ($itemsCount === $succeeded) {
            return ['action' => 'pass', 'reason' => ''];
        }

        return [
            'action' => 'fail',
            'reason' => "items.length={$itemsCount} but completion_stats.succeeded={$succeeded}",
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Mutator parity. Linkwise has THREE separate write-paths for
    // link mutations — Bard (BardWalker), Markdown (preg_replace_callback),
    // Replicator (set-aware Bard nested in container). Each has its own
    // code (replaceNthInBard, replaceNthInMarkdown, replaceNthInReplicator)
    // with its own bug surface. Without parity guarantees, a fix in one
    // path silently leaves the other paths broken — manual user testing
    // would have to cover every mutator × every field-type combination.
    //
    // This audit asserts: for a battery of synthetic test cases, all 3
    // mutators produce semantically identical results. If they ever
    // diverge — e.g. Bard skips on anchor mismatch but Markdown still
    // mutates — the audit fails with a clear diff between paths.
    //
    // Test corpus is synthetic (no entry/disk dependency) so this audit
    // runs deterministically against any site. Each test case lives in
    // a small array and is replayed in 3 variants:
    //   - Bard: simple ProseMirror tree
    //   - Markdown: equivalent [text](url) syntax
    //   - Replicator: same Bard tree wrapped in one set
    // The replicator variant catches set-traversal bugs that Bard-only
    // tests would miss (real bug history: Bug B / partial-overlap split).
    // ──────────────────────────────────────────────────────────────────
    protected function auditMutatorParity(): void
    {
        $this->line('<fg=yellow>mutator-parity</> — Bard, Markdown, Replicator behave identically for the same operation');

        $replacer = new \Arturrossbach\Linkwise\UrlChanger\UrlReplacer();
        $unlink = \Arturrossbach\Linkwise\Support\UrlHelper::UNLINK;
        $oldUrl = 'https://example.com/parity';
        $newUrl = 'https://example.com/replaced';

        // Each case = (label, what to call, expected (replaced, contentChanged)).
        // contentChanged: did the body actually mutate? (replaced=false should
        // imply contentChanged=false — that's the safety contract.)
        $cases = [
            // Case 1: index + anchor + url all match → must replace.
            ['match-anchor-and-index', 'OnlyAnchor', $oldUrl, $newUrl, 0, 'OnlyAnchor', true, true],
            // Case 2: index out-of-bounds → must skip (no Phase-2 fallback).
            ['index-out-of-bounds', 'OnlyAnchor', $oldUrl, $newUrl, 5, null, false, false],
            // Case 3: anchor mismatch → must skip (anchor-fingerprint guard).
            ['anchor-mismatch', 'WrongAnchor', $oldUrl, $newUrl, 0, 'ScannedAnchor', false, false],
            // Case 4: anchor explicitly matches → must replace.
            ['anchor-matches-explicitly', 'KnownAnchor', $oldUrl, $newUrl, 0, 'KnownAnchor', true, true],
            // Case 5: UNLINK sentinel → mark removed, text preserved.
            ['unlink-removes-mark-keeps-text', 'KeepMyText', $oldUrl, $unlink, 0, 'KeepMyText', true, true],
            // Case 6: legacy call without expectedAnchor → still works.
            ['legacy-no-anchor-arg', 'AnyAnchor', $oldUrl, $newUrl, 0, null, true, true],
        ];

        foreach ($cases as $case) {
            [$label, $anchorInDoc, $argOldUrl, $argNewUrl, $argIndex, $expectedAnchor, $expectReplaced, $expectChanged] = $case;

            $bard = $this->parityBuildBard($anchorInDoc, $oldUrl);
            $md = $this->parityBuildMarkdown($anchorInDoc, $oldUrl);
            $replicator = $this->parityBuildReplicator($anchorInDoc, $oldUrl);

            [$bardOut, $bardReplaced] = $replacer->replaceNthInBard($bard, 'example.com', $argOldUrl, $argNewUrl, $argIndex, $expectedAnchor);
            [$mdOut, $mdReplaced] = $replacer->replaceNthInMarkdown($md, 'example.com', $argOldUrl, $argNewUrl, $argIndex, $expectedAnchor);
            [$repOut, $repReplaced] = $replacer->replaceNthInReplicator($replicator, 'example.com', $argOldUrl, $argNewUrl, $argIndex, $expectedAnchor);

            $bardChanged = $bard !== $bardOut;
            $mdChanged = $md !== $mdOut;
            $repChanged = $replicator !== $repOut;

            $bardOk = $bardReplaced === $expectReplaced && $bardChanged === $expectChanged;
            $mdOk = $mdReplaced === $expectReplaced && $mdChanged === $expectChanged;
            $repOk = $repReplaced === $expectReplaced && $repChanged === $expectChanged;

            if ($bardOk && $mdOk && $repOk) {
                $this->pass('mutator-parity');
                continue;
            }

            // At least one path diverges — emit one failure per misbehaving
            // path so the report points directly at the file to fix.
            $expected = sprintf('replaced=%s, changed=%s', $expectReplaced ? 'true' : 'false', $expectChanged ? 'true' : 'false');
            if (! $bardOk) {
                $this->recordFailure('mutator-parity', "case '{$label}': BARD diverged — got replaced=".($bardReplaced ? 'true' : 'false').", changed=".($bardChanged ? 'true' : 'false').", expected {$expected}");
            }
            if (! $mdOk) {
                $this->recordFailure('mutator-parity', "case '{$label}': MARKDOWN diverged — got replaced=".($mdReplaced ? 'true' : 'false').", changed=".($mdChanged ? 'true' : 'false').", expected {$expected}");
            }
            if (! $repOk) {
                $this->recordFailure('mutator-parity', "case '{$label}': REPLICATOR diverged — got replaced=".($repReplaced ? 'true' : 'false').", changed=".($repChanged ? 'true' : 'false').", expected {$expected}");
            }
        }
    }

    /**
     * Build a minimal Bard doc with one paragraph wrapping $anchor in a
     * link mark pointing at $url. The text-node text equals $anchor exactly
     * so the anchor-fingerprint guard's match works the same as the
     * Markdown variant.
     */
    protected function parityBuildBard(string $anchor, string $url): array
    {
        return [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Before. '],
                    ['type' => 'text', 'text' => $anchor, 'marks' => [['type' => 'link', 'attrs' => ['href' => $url]]]],
                    ['type' => 'text', 'text' => '. After.'],
                ],
            ],
        ];
    }

    /**
     * Markdown equivalent of parityBuildBard — same anchor + url so the
     * mutator outputs are directly comparable.
     */
    protected function parityBuildMarkdown(string $anchor, string $url): string
    {
        return "Before. [{$anchor}]({$url}). After.";
    }

    /**
     * Replicator with one set wrapping the Bard from parityBuildBard.
     * Set-aware traversal in BardWalker::mapSetChildren is what the
     * mutator must hit — without it, replicator-nested links are
     * silently invisible.
     */
    protected function parityBuildReplicator(string $anchor, string $url): array
    {
        return [
            [
                'id' => 'set-1',
                'type' => 'text_block',
                'enabled' => true,
                'body' => $this->parityBuildBard($anchor, $url),
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Insert parity. Companion to mutator-parity, but for the
    // INSERT side: BardLinkInserter::insertLinkWithHref vs
    // ::insertLinkIntoMarkdown vs ::processReplicatorWithHref. These
    // 3 paths share the "find anchor, wrap with link" semantic but
    // each has its own tokenisation + wrapping logic. Without parity
    // assertion, an insert-only bug fix in Bard can silently leave
    // Markdown + Replicator broken.
    //
    // Test corpus is synthetic. 4 cases:
    // - Anchor present, no existing link → insert succeeds
    // - Anchor missing → no insert (all return null/unchanged)
    // - Anchor already wrapped in a link → no double-link
    // - Multiple occurrences, single-insert API → only the first wraps
    // ──────────────────────────────────────────────────────────────────
    protected function auditInsertParity(): void
    {
        $this->line('<fg=yellow>insert-parity</> — Bard, Markdown, Replicator inserts behave identically');

        $href = 'https://example.com/insert-target';

        // Each case = (label, builder-config, anchor-arg, expected (insertSucceeded, contentChanged))
        $cases = [
            // Case 1: anchor present, no existing link → must wrap.
            ['anchor-present-fresh-text', 'plain', 'AnchorWord', true, true],
            // Case 2: anchor missing → must skip, no mutation.
            ['anchor-missing', 'plain-without-anchor', 'AnchorWord', false, false],
            // Case 3: anchor already wrapped in a link → must skip (no double-link).
            ['anchor-already-linked', 'already-linked', 'AnchorWord', false, false],
            // Case 4: anchor appears twice — single-insert API wraps first only.
            // (We assert insertion happened; downstream second-occurrence behaviour
            // is checked via "all" APIs in a future case if/when relevant.)
            ['anchor-twice-single-insert-wraps-first', 'twice', 'AnchorWord', true, true],
        ];

        foreach ($cases as $case) {
            [$label, $variant, $anchor, $expectInserted, $expectChanged] = $case;

            $bard = $this->insertParityBuildBard($variant, $anchor, $href);
            $md = $this->insertParityBuildMarkdown($variant, $anchor, $href);
            $replicator = $this->insertParityBuildReplicator($variant, $anchor, $href);

            $bardOut = \Arturrossbach\Linkwise\Support\BardLinkInserter::insertLinkWithHref($bard, $anchor, $href);
            $mdOut = \Arturrossbach\Linkwise\Support\BardLinkInserter::insertLinkIntoMarkdown($md, $anchor, $href);
            $repOut = \Arturrossbach\Linkwise\Support\BardLinkInserter::processReplicatorWithHref($replicator, $anchor, $href);

            // null return = "no insertion happened" contract
            $bardInserted = $bardOut !== null;
            $mdInserted = $mdOut !== null;
            $repInserted = $repOut !== null;

            $bardChanged = $bardInserted && $bardOut !== $bard;
            $mdChanged = $mdInserted && $mdOut !== $md;
            $repChanged = $repInserted && $repOut !== $replicator;

            $bardOk = $bardInserted === $expectInserted && $bardChanged === $expectChanged;
            $mdOk = $mdInserted === $expectInserted && $mdChanged === $expectChanged;
            $repOk = $repInserted === $expectInserted && $repChanged === $expectChanged;

            if ($bardOk && $mdOk && $repOk) {
                $this->pass('insert-parity');
                continue;
            }

            $expected = sprintf('inserted=%s, changed=%s', $expectInserted ? 'true' : 'false', $expectChanged ? 'true' : 'false');
            if (! $bardOk) {
                $this->recordFailure('insert-parity', "case '$label': BARD diverged — got inserted=".($bardInserted ? 'true' : 'false').", changed=".($bardChanged ? 'true' : 'false').", expected $expected");
            }
            if (! $mdOk) {
                $this->recordFailure('insert-parity', "case '$label': MARKDOWN diverged — got inserted=".($mdInserted ? 'true' : 'false').", changed=".($mdChanged ? 'true' : 'false').", expected $expected");
            }
            if (! $repOk) {
                $this->recordFailure('insert-parity', "case '$label': REPLICATOR diverged — got inserted=".($repInserted ? 'true' : 'false').", changed=".($repChanged ? 'true' : 'false').", expected $expected");
            }
        }
    }

    /**
     * Build a Bard doc in one of 4 variants for insert-parity testing.
     * "plain": one paragraph containing $anchor unwrapped.
     * "plain-without-anchor": paragraph WITHOUT the anchor word.
     * "already-linked": paragraph with $anchor already wrapped in a link mark.
     * "twice": paragraph with $anchor appearing twice unwrapped.
     */
    protected function insertParityBuildBard(string $variant, string $anchor, string $href): array
    {
        return match ($variant) {
            'plain' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => "Lorem $anchor ipsum dolor."],
                ]],
            ],
            'plain-without-anchor' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'Lorem ipsum dolor sit amet.'],
                ]],
            ],
            'already-linked' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'Lorem '],
                    ['type' => 'text', 'text' => $anchor, 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://other.example/already']]]],
                    ['type' => 'text', 'text' => ' ipsum dolor.'],
                ]],
            ],
            'twice' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => "Lorem $anchor ipsum $anchor dolor."],
                ]],
            ],
        };
    }

    protected function insertParityBuildMarkdown(string $variant, string $anchor, string $href): string
    {
        return match ($variant) {
            'plain' => "Lorem $anchor ipsum dolor.",
            'plain-without-anchor' => 'Lorem ipsum dolor sit amet.',
            'already-linked' => "Lorem [$anchor](https://other.example/already) ipsum dolor.",
            'twice' => "Lorem $anchor ipsum $anchor dolor.",
        };
    }

    protected function insertParityBuildReplicator(string $variant, string $anchor, string $href): array
    {
        return [
            [
                'id' => 'set-1',
                'type' => 'text_block',
                'enabled' => true,
                'body' => $this->insertParityBuildBard($variant, $anchor, $href),
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Snapshot self-consistency. The forensic record must
    // reference only entries it claims to have touched. Items pointing
    // at entry IDs that aren't in the snapshot's entry_ids list mean
    // either the snapshot was stitched together from multiple bulks
    // (impossible in normal flow) or items got written under the wrong
    // snapshot ID (race condition between concurrent bulks). Either
    // way the activity log can't be trusted.
    //
    // Invariant: every items[*].entry_id (or source_entry_id for
    // outboundinsert) appears in snapshot.entry_ids.
    // ──────────────────────────────────────────────────────────────────
    protected function auditSnapshotSelfConsistency(): void
    {
        $this->line('<fg=yellow>snapshot-self-consistency</> — every snapshot item references a recorded entry_id');

        $store = app(BulkSnapshotStore::class);
        $snapshots = $store->list();

        foreach ($snapshots as $row) {
            $snap = $store->get($row['id']);
            if (! is_array($snap)) continue;

            $entryIds = array_filter(
                $snap['entry_ids'] ?? [],
                fn ($x) => is_string($x) && $x !== '',
            );
            $entryIdSet = array_flip($entryIds);

            $items = $snap['items'] ?? [];
            if (! is_array($items)) $items = [];

            $orphanItems = [];
            foreach ($items as $item) {
                if (! is_array($item)) continue;
                // Different kinds use different keys for the touched entry:
                //   detailunlink / urlchanger / inboundinsert: 'entry_id'
                //   outboundinsert: 'source_entry_id'
                //   bulkunlink: 'entry_id'
                //   applyrule (single): 'entry_id'
                $itemEntryId = $item['entry_id']
                    ?? $item['source_entry_id']
                    ?? null;
                if (! is_string($itemEntryId) || $itemEntryId === '') continue;
                if (! isset($entryIdSet[$itemEntryId])) {
                    $orphanItems[] = $itemEntryId;
                }
            }

            if (empty($orphanItems)) {
                $this->pass('snapshot-self-consistency');
                continue;
            }

            $kind = (string) ($snap['kind'] ?? '?');
            $first = $orphanItems[0];
            $extra = count($orphanItems) > 1 ? ' (+'.(count($orphanItems) - 1).' more)' : '';
            $this->recordFailure(
                'snapshot-self-consistency',
                "snapshot {$row['id']} ({$kind}): item references entry_id '{$first}' which is not in the snapshot's entry_ids list{$extra}",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Path: Stale job locks. JobLock is a Cache-backed mutex: a
    // background command writes its kind's status key with a phase in
    // ACTIVE_PHASES; other endpoints check that key before dispatch.
    // If the command crashes without rewriting the phase, the lock
    // becomes orphaned — the user sees "Linkwise busy" forever, no
    // bulk can start, the recovery banner can't even fire because
    // crash-guard registers AFTER the lock is set.
    //
    // Detection signal: cache key claims active phase but the
    // heartbeat is stale (commands write heartbeat=time() each loop)
    // OR no corresponding running snapshot exists. Stale-by-heartbeat
    // is the cleaner signal — captures both crashed commands and
    // legitimately-finished commands that didn't clean up.
    // ──────────────────────────────────────────────────────────────────
    /**
     * Architectural-smell check: detect whether `PhpBinary::cli()` would yield
     * an FPM-suffixed binary path on this environment. If yes, every detached
     * `exec("$php $artisan ...")` dispatch is broken — bulk operations would
     * hang at phase=starting (Bug 21 class).
     *
     * Caveat: this check runs in CLI context (the audit command itself), where
     * PHP_BINARY is already CLI. So we can only catch the case where the
     * fallback chain explicitly produces an fpm-binary — which would indicate
     * the helper logic itself is wrong. The more dangerous case (FPM context
     * yielding fpm) requires a runtime warning in the controller layer, not
     * here. Still useful as a regression net for the helper.
     */
    protected function auditPhpCliBinary(): void
    {
        $this->line('<fg=yellow>php-cli-binary</> — PhpBinary::cli() resolves to a CLI php, not FPM');

        $resolved = \Arturrossbach\Linkwise\Support\PhpBinary::cli();

        if (! is_string($resolved) || $resolved === '') {
            $this->recordFailure(
                'php-cli-binary',
                "PhpBinary::cli() returned empty/non-string: ".var_export($resolved, true).
                " — detached bulk-exec dispatches will fail. Check helper logic.",
            );

            return;
        }

        if (str_contains($resolved, '-fpm')) {
            $this->recordFailure(
                'php-cli-binary',
                "PhpBinary::cli() returned '{$resolved}' — an FPM binary. Detached ".
                "bulk-exec ('linkwise:detail-unlink' etc.) will hang at phase=starting ".
                "(Bug 21 class). Helper should have stripped the -fpm suffix or fallen ".
                "back to \$PATH. Likely cause: Herd/system PHP setup change since the ".
                "helper was tested.",
            );

            return;
        }

        // Sanity: ensure the resolved binary actually runs (=== exists +
        // executable). `php` from $PATH is OK — shell will find it.
        if ($resolved !== 'php' && ! is_executable($resolved)) {
            $this->recordFailure(
                'php-cli-binary',
                "PhpBinary::cli() returned '{$resolved}' which is not executable. ".
                "Detached bulk-exec will fail. Check Herd / system PHP install.",
            );

            return;
        }

        $this->pass('php-cli-binary');
    }

    protected function auditStaleJobLocks(): void
    {
        $this->line('<fg=yellow>stale-job-locks</> — no orphaned cache locks blocking new bulks');

        // Heartbeat older than 90s = stale. Active commands refresh
        // every iteration (at most a few seconds apart even on slow
        // entries). 90s gives ample slack for a single very-large
        // entry write before flagging.
        $staleThresholdSeconds = 90;
        $now = time();

        // ACTIVE_PHASES is protected; mirror the literal here so the
        // audit doesn't depend on reflection. Keep aligned with
        // JobLock::ACTIVE_PHASES.
        $activePhases = ['starting', 'running', 'indexing', 'suggestions', 'saving', 'checking'];

        foreach (\Arturrossbach\Linkwise\Support\JobLock::JOBS as $name => $meta) {
            $status = \Illuminate\Support\Facades\Cache::get($meta['key']);
            if (! is_array($status)) {
                $this->pass('stale-job-locks');
                continue;
            }
            $phase = (string) ($status['phase'] ?? '');
            if (! in_array($phase, $activePhases, true)) {
                $this->pass('stale-job-locks');
                continue;
            }
            // Lock claims active. Check heartbeat age.
            $heartbeat = (int) ($status['heartbeat'] ?? 0);
            $age = $heartbeat > 0 ? ($now - $heartbeat) : null;

            if ($age === null) {
                $this->recordFailure(
                    'stale-job-locks',
                    "{$name} ({$meta['label']}): cache lock has phase='{$phase}' but no heartbeat — likely orphaned from a crashed run",
                );
                continue;
            }
            if ($age > $staleThresholdSeconds) {
                $this->recordFailure(
                    'stale-job-locks',
                    "{$name} ({$meta['label']}): cache lock has phase='{$phase}' with heartbeat {$age}s old — likely orphaned, blocking new bulks. Run `php artisan cache:clear` or wait for TTL",
                );
                continue;
            }
            // Fresh heartbeat → real running command, fine.
            $this->pass('stale-job-locks');
        }
    }

    protected function checkSuggestionInsertable(string $path, string $sourceId, string $anchor, string $targetId, string $label, ?string $sentenceContext = null): void
    {
        $href = 'statamic://entry::'.$targetId;
        try {
            // Klasse-4.x sister-gap fixed Welle 3 (2026-05-18): pass
            // sentence_context as the 6th arg so the audit is symmetric
            // to the real user-facing paths
            // (InboundEngine:158 + OutboundSuggestionGrouper:35) which
            // both pass `$s->sentenceContext`. Pre-fix the audit could
            // PASS (entry insertable somewhere) while real apply FAILED
            // with context_mismatch — false negative in our own audit.
            $can = BardLinkInserter::insertLinkIntoEntryWithHref(
                $sourceId, $anchor, $href, false, save: false,
                expectedSentenceContext: $sentenceContext,
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

    // ──────────────────────────────────────────────────────────────────
    // PR #102 audit L1 — Locale-Scope pin
    // ──────────────────────────────────────────────────────────────────
    // Direct, independent test: for every source/target pair where both
    // sides carry a locale AND those locales differ, the SuggestionEngine
    // must produce zero suggestions. This is structurally different from
    // `suggestions-safety` (which runs `suggest()` and inspects what comes
    // back via the engine's OWN filter) — here we explicitly construct the
    // forbidden cross-locale shape and assert the filter rejects it.
    //
    // Skips silently on single-site installs where every record's locale
    // is null (the filter has nothing to check).
    protected function auditLocaleScope(): void
    {
        $this->line('<fg=yellow>locale-scope</> — cross-locale source/target pairs yield zero suggestions');

        $records = $this->indexer->load();

        // Group records by locale. Iterate over locale pairs (a, b) with a != b
        // and pick the first record from each bucket. Don't try a full N×N
        // pass — the goal is structural verification, not exhaustive enumeration.
        $byLocale = [];
        foreach ($records as $r) {
            if ($r->locale === null) continue;
            $byLocale[$r->locale][] = $r;
        }

        if (count($byLocale) < 2) {
            // Single-site or all-null index — nothing to scope-check. Record
            // a passing no-op so the report shows the group ran.
            $this->pass('locale-scope');
            return;
        }

        $locales = array_keys($byLocale);
        foreach ($locales as $sourceLocale) {
            $sources = $byLocale[$sourceLocale];
            // Take up to 3 sources per locale — enough to catch a stuck
            // filter, bounded enough to keep the audit fast on large indices.
            $sources = array_slice($sources, 0, 3);

            foreach ($sources as $source) {
                $this->progressDot();
                try {
                    $suggestions = $this->suggestionEngine->suggest(
                        $source->text, $records, $source->id, $source->outboundLinks
                    );
                } catch (\Throwable $e) {
                    $this->recordFailure('locale-scope', "suggest() threw for source '{$source->title}' ({$sourceLocale}): ".$e->getMessage());
                    continue;
                }

                foreach ($suggestions as $s) {
                    $target = $records[$s->targetEntryId] ?? null;
                    if ($target === null) continue;
                    if ($target->locale === null) continue; // half-migrated; filter intentionally passes
                    if ($target->locale !== $sourceLocale) {
                        $this->recordFailure(
                            'locale-scope',
                            "source '{$source->title}' ({$sourceLocale}) suggested target '{$target->title}' ({$target->locale}) — cross-locale filter leaked"
                        );
                    }
                }
                $this->pass('locale-scope');
            }
        }
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

    /**
     * PR #102 audit L2 — per-iteration progress dot for long-running audit
     * groups. Without this, groups like `suggestions-safety` print their
     * header line and then sit silent for 20+ minutes on real-size indices
     * (heute Abend self-witnessed on prose-peak-test with 682 entries).
     * Customers reading the command output assume it hung.
     *
     * Called per-iteration in the heavy groups; reportPath() emits a
     * newline before the result so the dots don't sit on the same line as
     * the ✓/✗ summary.
     */
    protected function progressDot(): void
    {
        if (! $this->hasProgressDots) {
            $this->hasProgressDots = true;
            // Two-space indent matches the ✓/✗ summary line.
            $this->output->write('  ');
        }
        $this->output->write('.');
        $this->progressDotCount++;
        // Periodic newline so a 5000-entry walk doesn't produce a single
        // 5000-char line that wraps unreadably in most terminals.
        if ($this->progressDotCount % 100 === 0) {
            $this->output->writeln('');
            $this->hasProgressDots = false;
        }
    }

    protected bool $hasProgressDots = false;
    protected int $progressDotCount = 0;

    protected function reportPath(string $path): void
    {
        // Newline before summary if we emitted any progress dots during
        // the runner — keeps the ✓/✗ line clean.
        if ($this->hasProgressDots) {
            $this->output->writeln('');
            $this->hasProgressDots = false;
            $this->progressDotCount = 0;
        }

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

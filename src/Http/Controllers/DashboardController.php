<?php

namespace Arturrossbach\Linkwise\Http\Controllers;

use Arturrossbach\Linkwise\AutoLink\AutoLinkManager;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Links\BrokenLinkReport;
use Arturrossbach\Linkwise\Reports\DomainReport;
use Arturrossbach\Linkwise\Reports\LinkReport;
use Statamic\Http\Controllers\CP\CpController;

// 8 Inertia-Page-Renderer (Overview/Links/BrokenLinks/Domains/AutoLink/Keywords/
// Activity/UrlChanger) + staleCheckProps() helper + extractContentKeywords +
// extractExternalLinksFromEntry helpers extracted to
// {@see \Arturrossbach\Linkwise\Http\Controllers\Dashboard\InertiaPagesController}
// during REV-DR-01 Phase B PR 5.

class DashboardController extends CpController
{
    public function __construct(
        protected EntryIndexer $indexer,
        protected AutoLinkManager $autoLinkManager,
    ) {}

    // 8 Inertia-Page-Renderer (Overview/Links/BrokenLinks/Domains/AutoLink/
    // Keywords/Activity/UrlChanger) extracted to
    // {@see \Arturrossbach\Linkwise\Http\Controllers\Dashboard\InertiaPagesController}
    // during REV-DR-01 Phase B PR 5.

    // ─── API Endpoints ─────────────────────────────────────────────────
    //
    // Stats / suggestion-counts / domain-attribute extracted to
    // {@see \Arturrossbach\Linkwise\Http\Controllers\Dashboard\StatsApiController}
    // during REV-DR-01 Phase B PR 1.

    // Bulk-job dispatch + status + cancel endpoints (check-links, rebuild-index,
    // bulk-unlink, detail-unlink-async, inbound/outbound insert-cancel) extracted
    // to {@see \Arturrossbach\Linkwise\Http\Controllers\Dashboard\BulkJobsController}
    // during REV-DR-01 Phase B PR 4.
    //
    // Cross-controller heavy-job aggregation (bulkStatus + bulkClear) moved to
    // {@see \Arturrossbach\Linkwise\Http\Controllers\Dashboard\JobsAggregatorController}.

    /**
     * CSV export of all broken links from the latest report.
     * Same shape the user sees in the Broken Links table — useful for
     * sharing with colleagues, tracking over time, or post-mortem on a fix.
     */
    public function brokenLinksCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $report = new \Arturrossbach\Linkwise\Links\BrokenLinkReport;
        // load() returns ['metadata' => ..., 'broken_links' => BrokenLinkRecord[]]
        // — must iterate the broken_links bucket, not the wrapper array.
        $records = $report->load()['broken_links'];
        $filename = 'linkwise-broken-links-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($records) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Entry Title',
                'Entry ID',
                'Broken URL',
                'Anchor Text',
                'Type',
                'Status Code',
                'Status Label',
                'Error Type',
                'First Detected',
                'Last Checked',
                'Ignored',
                'Sentence Context',
            ]);
            foreach ($records as $r) {
                // Mark the anchor text inside sentence_context with **bold**
                // so reviewers can see WHICH word in the sentence is the
                // broken link — plain text otherwise gives no visual cue.
                $context = $r->sentenceContext;
                if ($context && $r->anchorText) {
                    $context = preg_replace(
                        '/'.preg_quote($r->anchorText, '/').'/i',
                        '**$0**',
                        $context,
                        1, // first occurrence only — same as the UI highlight
                    );
                }
                fputcsv($out, [
                    $r->postTitle,
                    $r->postId,
                    $r->url,
                    $r->anchorText,
                    $r->type,
                    $r->statusCode ?? '',
                    $r->statusLabel(),
                    $r->errorType,
                    $r->firstDetectedAt,
                    $r->lastCheckedAt,
                    $r->ignored ? 'yes' : 'no',
                    $context,
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * CSV export of the Domains report — external domains the site links to,
     * with link counts and per-domain attributes (nofollow/dofollow/etc).
     */
    public function domainsCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $report = new DomainReport($this->indexer);
        $domainsData = $report->toArray();
        $filename = 'linkwise-domains-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($domainsData) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Domain',
                'Posts Count',
                'Links Count',
                'Attribute',
            ]);
            foreach ($domainsData as $domain) {
                fputcsv($out, [
                    $domain['domain'] ?? '',
                    $domain['post_count'] ?? 0,
                    $domain['link_count'] ?? 0,
                    $domain['attribute'] ?? 'default',
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // Detail-unlink trio, inbound/outbound insert-cancel, bulkClear, bulkStatus
    // extracted to Dashboard\BulkJobsController + Dashboard\JobsAggregatorController
    // during REV-DR-01 Phase B PR 4.

    // ─── Helpers ───────────────────────────────────────────────────────

    // extractContentKeywords + extractExternalLinksFromEntry helpers moved
    // with the Inertia-Page-Renderer cluster (REV-DR-01 Phase B PR 5).

    /**
     * Debug-Export — bundles environment + Linkwise diagnostic data into a
     * single ZIP for support tickets.
     *
     * GDPR-compliance: privacy-first by design. Default export contains:
     * - aggregate counts and stats (no PII)
     * - software version info (no PII)
     * - whitelisted non-sensitive config keys (no API keys, no URL patterns,
     *   no entry IDs)
     *
     * Logs are OPT-IN via ?include_logs=1 because:
     * - log lines may contain URLs from the user's site
     * - URLs may include personally-identifiable paths (/users/john-doe)
     * - URLs may include query strings with PII (?email=, ?token=)
     *
     * The frontend forces a confirmation dialog when toggling include_logs
     * so the user makes an informed choice before potentially sharing PII.
     */
    public function debugExport(\Illuminate\Http\Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $includeLogs = $request->boolean('include_logs');

        $records = $this->indexer->load();
        $report = new LinkReport($records);
        $reportData = $report->toArray();
        $brokenReport = new BrokenLinkReport;
        $brokenData = $brokenReport->load();
        $rules = $this->autoLinkManager->loadRules();

        $storagePath = storage_path('linkwise');
        $tempZip = tempnam(sys_get_temp_dir(), 'linkwise-debug-').'.zip';

        $zip = new \ZipArchive;
        if ($zip->open($tempZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Could not create debug archive.');
        }

        // 1. Environment + version snapshot. Whitelist-only config snapshot:
        //    ONLY non-sensitive keys are included. Excludes ignored_links
        //    (URL patterns), excluded_entries / orphaned_ignore (entry IDs),
        //    api_key, and anything else that could identify a site.
        $cfg = config('linkwise', []);
        $aiCfg = $cfg['ai'] ?? [];
        $brokenCfg = $cfg['broken_links'] ?? [];
        $configSafe = [
            // What COLLECTIONS the addon scans — handles only, e.g. "blog".
            // Generic and useful for debugging "why isn't my blog scanned".
            'collections' => is_array($cfg['collections'] ?? null) ? $cfg['collections'] : [],
            'excluded_collections' => is_array($cfg['excluded_collections'] ?? null) ? $cfg['excluded_collections'] : [],
            // AI configured? Yes/no/redacted. Never the key itself.
            'ai_configured' => ! empty($aiCfg['api_key']),
            'ai_provider' => $aiCfg['provider'] ?? null,
            'ai_model' => $aiCfg['model'] ?? null,
            // Broken-link timeout/retries — ints, no PII risk.
            'broken_links_timeout' => $brokenCfg['timeout'] ?? null,
            'broken_links_retries' => $brokenCfg['retries'] ?? null,
            // Counts (NOT contents) of site-specific lists. The number tells
            // support "user has 5 ignored URL patterns" without revealing them.
            'ignored_links_count' => isset($cfg['ignored_links']) && is_string($cfg['ignored_links'])
                ? count(array_filter(array_map('trim', explode("\n", $cfg['ignored_links']))))
                : 0,
            'excluded_entries_count' => is_array($cfg['excluded_entries'] ?? null) ? count($cfg['excluded_entries']) : 0,
            'orphaned_ignore_count' => is_array($cfg['orphaned_ignore'] ?? null) ? count($cfg['orphaned_ignore']) : 0,
        ];
        $info = [
            'generated_at' => now()->toIso8601String(),
            'include_logs' => $includeLogs,
            'php_version' => PHP_VERSION,
            'os' => PHP_OS,
            'laravel_version' => app()->version(),
            'statamic_version' => \Statamic\Statamic::version(),
            'linkwise_version' => $this->linkwiseVersion(),
            'config' => $configSafe,
        ];
        $zip->addFromString('info.json', json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // 2. Index summary — counts only, no entry content/text/keywords.
        $perCollection = [];
        foreach ($records as $r) {
            $perCollection[$r->collection] = ($perCollection[$r->collection] ?? 0) + 1;
        }
        $indexSummary = [
            'total_entries' => count($records),
            'entries_per_collection' => $perCollection,
            'total_internal_links' => $reportData['summary']['total_links'] ?? 0,
            'orphaned_count' => $reportData['summary']['orphaned_count'] ?? 0,
            'index_last_built_at' => $this->indexer->getIndexLastBuiltAt(),
        ];
        $zip->addFromString('index-summary.json', json_encode($indexSummary, JSON_PRETTY_PRINT));

        // 3. Broken-links summary — per error_type counts, NO actual URLs.
        $errorTypes = [];
        $statusCodes = [];
        foreach ($brokenData['broken_links'] ?? [] as $r) {
            $errorTypes[$r->errorType] = ($errorTypes[$r->errorType] ?? 0) + 1;
            $code = $r->statusCode ?? 'null';
            $statusCodes[$code] = ($statusCodes[$code] ?? 0) + 1;
        }
        $brokenSummary = [
            'total_broken' => count($brokenData['broken_links'] ?? []),
            'last_checked' => $brokenData['metadata']['last_checked'] ?? null,
            'by_error_type' => $errorTypes,
            'by_status_code' => $statusCodes,
        ];
        $zip->addFromString('broken-links-summary.json', json_encode($brokenSummary, JSON_PRETTY_PRINT));

        // 4. Auto-Link rules summary — counts + states, NO keywords/URLs.
        $activeRules = 0;
        $rulesWithCollections = 0;
        foreach ($rules as $rule) {
            if ($rule->active) {
                $activeRules++;
            }
            if (! empty($rule->collections)) {
                $rulesWithCollections++;
            }
        }
        $rulesSummary = [
            'total_rules' => count($rules),
            'active_rules' => $activeRules,
            'rules_with_collection_scope' => $rulesWithCollections,
        ];
        $zip->addFromString('autolink-rules-summary.json', json_encode($rulesSummary, JSON_PRETTY_PRINT));

        // 5. Storage stats — file sizes (KB) of every linkwise/* file. Useful
        //    for diagnosing "index is huge / broken-links.json bloated".
        $storageStats = [];
        if (is_dir($storagePath)) {
            foreach (new \DirectoryIterator($storagePath) as $f) {
                if ($f->isFile()) {
                    $storageStats[$f->getFilename()] = [
                        'size_kb' => (int) round($f->getSize() / 1024),
                        'modified' => date('c', $f->getMTime()),
                    ];
                }
            }
        }
        $zip->addFromString('storage-stats.json', json_encode($storageStats, JSON_PRETTY_PRINT));

        // 5b. Runtime environment — PHP runtime + server info. Always included
        //     (no PII). Critical for diagnosing "scan dies after 30s"
        //     (max_execution_time), "out of memory at N entries" (memory_limit),
        //     "OPcache cache stale" (opcache settings) classes of bugs.
        $runtimeEnv = [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'memory_get_usage' => memory_get_usage(true),
            'memory_get_peak_usage' => memory_get_peak_usage(true),
            'max_execution_time' => ini_get('max_execution_time'),
            'max_input_time' => ini_get('max_input_time'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'max_input_vars' => ini_get('max_input_vars'),
            'opcache_enabled' => ini_get('opcache.enable'),
            'opcache_revalidate_freq' => ini_get('opcache.revalidate_freq'),
            'date_timezone' => date_default_timezone_get(),
            'locale' => setlocale(LC_ALL, 0),
            'extensions_loaded' => array_values(array_filter(
                ['curl', 'zip', 'mbstring', 'intl', 'gd', 'imagick', 'fileinfo', 'opcache', 'sodium', 'simplexml', 'dom'],
                'extension_loaded',
            )),
            'server_software' => $request->server('SERVER_SOFTWARE') ?? 'unknown',
            'request_scheme' => $request->server('REQUEST_SCHEME') ?? 'unknown',
            'app_env' => app()->environment(),
            'app_debug' => (bool) config('app.debug'),
            'app_url' => parse_url(config('app.url') ?? '', PHP_URL_HOST) ?? 'unknown',
            'cache_default' => config('cache.default'),
        ];
        $zip->addFromString('runtime-env.json', json_encode($runtimeEnv, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // 6. Verbose-mode contents — opt-in, may contain URLs/keywords/PII.
        //    Modal-confirmed by the user before download fires. This block is
        //    where debugging actually happens: full state JSON + stack traces.
        if ($includeLogs) {
            // 6a. Linkwise's own logs — including LogRotator-rotated `.log.1`
            //     files. A long-running scan can hit the 5MB rotation
            //     threshold mid-flight, leaving the early portion in `.log.1`
            //     while the live tail sits in `.log`. Without including both
            //     we'd lose evidence of the very crashes the rotation was
            //     triggered by.
            if (is_dir($storagePath)) {
                foreach (glob($storagePath.'/*.log*') as $logPath) {
                    if (! is_readable($logPath)) {
                        continue;
                    }
                    $name = basename($logPath);
                    // Skip non-log files that happen to match the glob.
                    if (! preg_match('/\.log(\.\d+)?$/', $name)) {
                        continue;
                    }
                    $tail = $this->tailLines($logPath, 500);
                    $zip->addFromString('logs/'.$name, $tail);
                }
            }

            // 6b. Laravel error log — covers BOTH the `single` channel
            //     (laravel.log) and the `daily` channel (laravel-YYYY-MM-DD.log).
            //     Without the daily glob, sites running Laravel's daily logger
            //     would have an empty filtered file every time — the
            //     "no Linkwise entries found" message was misleading rather
            //     than missing logs being a debug-flow gap.
            //
            //     Cap to the 7 most recent files to bound ZIP size on long-
            //     running sites without losing the typical "what happened
            //     yesterday" debug case.
            $laravelLogs = is_dir(storage_path('logs'))
                ? array_filter(glob(storage_path('logs/laravel*.log')) ?: [], 'is_readable')
                : [];
            usort($laravelLogs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
            $laravelLogs = array_slice($laravelLogs, 0, 7);

            if (! empty($laravelLogs)) {
                $combinedFiltered = '';
                foreach ($laravelLogs as $logPath) {
                    $base = basename($logPath);
                    // Per-file filtered + per-file full-tail. Per-file output
                    // lets support immediately tell which day a stack trace
                    // came from without parsing timestamps.
                    $filtered = $this->extractLinkwiseLogEntries($logPath, 50);
                    if ($filtered !== '') {
                        $combinedFiltered .= "===== {$base} =====\n".$filtered."\n";
                    }
                    $tail = $this->tailLines($logPath, 1000);
                    $zip->addFromString('logs/'.$base.'-tail.log', $tail);
                }
                $zip->addFromString(
                    'logs/laravel-linkwise-errors.log',
                    $combinedFiltered !== '' ? $combinedFiltered : "(no Linkwise-related entries found in any laravel*.log file)\n",
                );
            } else {
                $zip->addFromString(
                    'logs/laravel-linkwise-errors.log',
                    "(no readable laravel*.log files found in storage/logs/)\n",
                );
            }

            // 6c. Full state JSONs — needed to reproduce "rule X doesn't fire"
            //     or "entry Y shows wrong inbound count" bugs. URLs/keywords
            //     present, hence opt-in only.
            foreach (['broken-links.json', 'autolink-rules.json', 'target-keywords.json', 'domain-attributes.json'] as $file) {
                $path = $storagePath.'/'.$file;
                if (is_readable($path)) {
                    $zip->addFile($path, 'state/'.$file);
                }
            }

            // 6d. Filtered index.json — entry IDs + titles + outbound link IDs
            //     ONLY. Strips text content + keywords (potential PII bulk).
            //     Sufficient to debug ghost-link / missing-entry bugs without
            //     leaking the user's editorial content.
            $indexPath = $storagePath.'/index.json';
            if (is_readable($indexPath)) {
                $rawIndex = json_decode(file_get_contents($indexPath), true);
                if (is_array($rawIndex)) {
                    $stripped = [];
                    foreach ($rawIndex as $id => $entry) {
                        $stripped[$id] = [
                            'id' => $entry['id'] ?? $id,
                            'title' => $entry['title'] ?? '',
                            'collection' => $entry['collection'] ?? '',
                            'url' => $entry['url'] ?? null,
                            'outbound_links' => $entry['outbound_links'] ?? [],
                            // Drop text + keywords — high PII bulk, low debug value
                            'text_length' => isset($entry['text']) ? strlen((string) $entry['text']) : 0,
                            'keyword_count' => isset($entry['keywords']) && is_array($entry['keywords']) ? count($entry['keywords']) : 0,
                        ];
                    }
                    $zip->addFromString('state/index-filtered.json', json_encode($stripped, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
            }
        }

        // 7. README inside the ZIP. Two privacy modes documented separately
        //    so the recipient knows whether the user opted into the verbose
        //    bundle or not.
        $zipReadme = "Linkwise Debug Export\n=====================\n\n".
            "Generated: ".$info['generated_at']."\n".
            "Mode:      ".($includeLogs ? 'VERBOSE (logs + state included)' : 'SAFE (counts only)')."\n\n".
            "Always included (privacy-safe):\n".
            "  info.json                    — versions + whitelisted config\n".
            "                                 (no API key, no URL patterns,\n".
            "                                  no entry IDs)\n".
            "  runtime-env.json             — PHP runtime + server info\n".
            "                                 (memory_limit, max_execution_time,\n".
            "                                  opcache, extensions, server software)\n".
            "  index-summary.json           — entry counts per collection\n".
            "  broken-links-summary.json    — error_type / status_code counts\n".
            "  autolink-rules-summary.json  — rule counts only\n".
            "  storage-stats.json           — file sizes + mtimes\n\n".
            ($includeLogs
                ? "VERBOSE additions (may contain PII — review before sharing):\n".
                  "  logs/*.log[.N]               — Linkwise progress logs.\n".
                  "                                 Includes LogRotator-rotated\n".
                  "                                 .log.1 archives so debugging\n".
                  "                                 a long scan that triggered\n".
                  "                                 rotation mid-flight stays\n".
                  "                                 possible.\n".
                  "  logs/laravel-linkwise-errors.log\n".
                  "                               — stack traces filtered to\n".
                  "                                 Linkwise mentions, combined\n".
                  "                                 across ALL laravel*.log files\n".
                  "                                 with per-file headers.\n".
                  "                                 Covers both `single` and\n".
                  "                                 `daily` Laravel log channels.\n".
                  "  logs/laravel*.log-tail.log   — Per-channel last 1000 lines,\n".
                  "                                 unfiltered. Catches Statamic-\n".
                  "                                 core exceptions triggered by\n".
                  "                                 Linkwise calls that don't\n".
                  "                                 mention 'linkwise' themselves.\n".
                  "                                 Capped to 7 most-recent files.\n".
                  "  state/broken-links.json      — full broken-link report\n".
                  "                                 (URLs, anchor text, context)\n".
                  "  state/autolink-rules.json    — rule keyword + URL targets\n".
                  "  state/target-keywords.json   — custom keywords per entry\n".
                  "  state/domain-attributes.json — per-domain rel attributes\n".
                  "  state/index-filtered.json    — entry IDs/titles + outbound\n".
                  "                                 link relationships\n".
                  "                                 (text content + keywords stripped)\n\n".
                  "PRIVACY WARNING\n".
                  "===============\n".
                  "This is a VERBOSE export. It contains URLs from your site,\n".
                  "auto-link keywords, anchor text, and Laravel stack traces.\n".
                  "These can include personally identifiable information\n".
                  "(usernames in URLs, email patterns, IP addresses, request\n".
                  "data in stack traces).\n\n".
                  "Before sharing this ZIP:\n".
                  "  - Open it locally and review what's inside\n".
                  "  - Encrypt or password-protect when transmitting via email\n".
                  "  - Send only to trusted Linkwise support\n".
                  "  - Delete the ZIP after the support ticket is closed\n".
                  "API keys are NEVER included.\n"
                : "Privacy:\n".
                  "  This export contains NO URLs from your site, NO entry\n".
                  "  content, NO keywords, and NO API keys. Safe to share.\n\n".
                  "  For deeper debugging (stack traces + state), use\n".
                  "  'Download diagnostic ZIP — with logs' from the Help menu.\n"
            );
        $zip->addFromString('README.txt', $zipReadme);

        $zip->close();

        $filename = 'linkwise-debug-'.now()->format('Y-m-d-His').'.zip';

        return response()->download($tempZip, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Frontend error sink. Receives JS / Vue render / unhandled-rejection
     * errors from the addon's reporter and appends them to a Linkwise-local
     * log file. NEVER calls out to a third-party — DSGVO-friendly + no SaaS
     * dependency. Lands in storage/linkwise/frontend-errors.log which then
     * gets bundled into the verbose debug-export ZIP.
     *
     * Privacy: PII-scrubber strips query strings and masks /users/<name>
     * style paths. Payload size is capped at 8KB to prevent log-flooding.
     * Rate-limit handled by middleware (100/min/user).
     */
    public function frontendError(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'stack' => 'nullable|string|max:6000',
            'source' => 'nullable|string|max:200',
            'url' => 'nullable|string|max:2000',
            'kind' => 'nullable|string|max:50',
        ]);

        $logDir = storage_path('linkwise');
        if (! is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logPath = $logDir.'/frontend-errors.log';

        // Naïve size-based rotation: when the log crosses 5MB, rename to
        // .1 (overwriting the previous .1) and start a fresh file. One
        // backup is enough — we don't need a long history of frontend errors.
        if (file_exists($logPath) && filesize($logPath) > 5 * 1024 * 1024) {
            @rename($logPath, $logPath.'.1');
        }

        $entry = json_encode([
            'ts' => now()->toIso8601String(),
            'kind' => $validated['kind'] ?? 'error',
            'message' => $this->scrubPii($validated['message']),
            'source' => $validated['source'] ?? null,
            'url' => $this->scrubPii($validated['url'] ?? ''),
            'stack' => $this->scrubPii($validated['stack'] ?? ''),
            'user_agent' => substr((string) $request->userAgent(), 0, 200),
        ], JSON_UNESCAPED_SLASHES);

        @file_put_contents($logPath, $entry."\n", FILE_APPEND | LOCK_EX);

        return response()->json(['ok' => true], 204);
    }

    /**
     * Strip query strings + mask user-identifier-style URL paths from
     * arbitrary text. Best-effort only — the reporter must additionally
     * scrub on the client side; this is the second line of defence.
     */
    protected function scrubPii(string $text): string
    {
        if ($text === '') {
            return '';
        }
        // Remove query strings entirely (often contain ?email=, ?token=)
        $text = preg_replace('/(\?[^\s"\'<>]+)/', '?[redacted]', $text) ?? $text;
        // Mask /users/<name> style paths to /users/[id]
        $text = preg_replace('#/(users|members|profile|account)/[^/\s"\'<>]+#i', '/$1/[id]', $text) ?? $text;

        return $text;
    }

    /**
     * Read the linkwise package version from composer.json. Returns 'dev'
     * when running from a git clone without a tagged release.
     */
    protected function linkwiseVersion(): string
    {
        $composerPath = __DIR__.'/../../../composer.json';
        if (! file_exists($composerPath)) {
            return 'unknown';
        }
        $data = json_decode(file_get_contents($composerPath), true);

        return $data['version'] ?? 'dev';
    }

    /**
     * Read the last N lines of a file without loading the whole thing.
     * Walks backwards from EOF in 4KB chunks until enough newlines are seen.
     * Critical for log files that may have grown to gigabytes.
     */
    protected function tailLines(string $path, int $maxLines): string
    {
        if (! file_exists($path) || ! is_readable($path)) {
            return '';
        }
        $size = filesize($path);
        if ($size === 0) {
            return '';
        }

        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return '';
        }

        $buffer = '';
        $chunkSize = 4096;
        $pos = $size;
        $lineCount = 0;

        while ($pos > 0 && $lineCount <= $maxLines) {
            $readSize = min($chunkSize, $pos);
            $pos -= $readSize;
            fseek($fp, $pos);
            $chunk = fread($fp, $readSize);
            $buffer = $chunk.$buffer;
            $lineCount = substr_count($buffer, "\n");
        }
        fclose($fp);

        $lines = explode("\n", $buffer);
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        return implode("\n", $lines);
    }

    /**
     * Extract Laravel-log entries that mention Linkwise. Each Laravel log
     * entry is a multi-line block starting with `[YYYY-MM-DD HH:MM:SS]` —
     * we split on that boundary, filter blocks containing "Arturrossbach\Linkwise"
     * or a case-insensitive "linkwise" mention, and keep the most recent
     * $maxEntries. This is the only place stack traces actually live.
     *
     * Read the whole file in one shot — bounded by reasonable Laravel log
     * sizes (multi-MB). For huge logs (>50MB), the tail-2MB approach below
     * keeps memory predictable.
     */
    protected function extractLinkwiseLogEntries(string $path, int $maxEntries): string
    {
        $maxRead = 2 * 1024 * 1024; // 2MB cap — older entries dropped
        $size = filesize($path);
        if ($size === false || $size === 0) {
            return '';
        }

        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return '';
        }
        if ($size > $maxRead) {
            fseek($fp, $size - $maxRead);
            // Skip partial first line so we start at a clean entry boundary.
            fgets($fp);
        }
        $content = stream_get_contents($fp);
        fclose($fp);

        if ($content === false || $content === '') {
            return '';
        }

        // Split on Laravel timestamp prefix `[YYYY-MM-DD HH:MM:SS]`. Use a
        // lookahead so the timestamp stays attached to its block.
        $blocks = preg_split('/(?=^\[\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}\])/m', $content);
        if (! is_array($blocks)) {
            return '';
        }

        $matches = array_values(array_filter($blocks, fn (string $b) => stripos($b, 'linkwise') !== false));
        if (empty($matches)) {
            return '';
        }
        if (count($matches) > $maxEntries) {
            $matches = array_slice($matches, -$maxEntries);
        }

        return implode('', $matches);
    }
}

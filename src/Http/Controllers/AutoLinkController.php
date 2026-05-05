<?php

namespace Inkline\Linkwise\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inkline\Linkwise\AutoLink\AutoLinkApplier;
use Inkline\Linkwise\AutoLink\AutoLinkManager;
use Inkline\Linkwise\Exceptions\EntryConflictException;
use Inkline\Linkwise\Indexer\EntryIndexer;
use Inkline\Linkwise\Support\SafeEntrySaver;
use Statamic\Http\Controllers\CP\CpController;

class AutoLinkController extends CpController
{
    public function __construct(
        protected AutoLinkManager $manager,
        protected EntryIndexer $indexer,
    ) {}

    public function index(): JsonResponse
    {
        $rules = $this->manager->loadRules();

        return response()->json([
            'rules' => array_values(array_map(fn ($r) => $r->toArray(), $rules)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => 'required|string|min:1',
            'url' => 'required|string',
            'once_per_post' => 'boolean',
            'skip_if_exists' => 'boolean',
            'case_sensitive' => 'boolean',
            'auto_apply_on_save' => 'in:follow_global,always,never',
            'collections' => 'array',
        ]);

        $data['keyword'] = trim($data['keyword']);
        $incomingCs = (bool) ($data['case_sensitive'] ?? false);

        $conflict = AutoLinkManager::findDuplicate(
            $this->manager->loadRules(),
            $data['keyword'],
            $incomingCs,
        );

        if ($conflict) {
            $bothCs = $incomingCs && $conflict->caseSensitive;
            $reason = $bothCs
                ? "A case-sensitive rule for \"{$data['keyword']}\" already exists."
                : ($conflict->keyword === $data['keyword']
                    ? "A rule for \"{$data['keyword']}\" already exists."
                    : "A rule for \"{$conflict->keyword}\" already exists and is not case-sensitive — it already covers \"{$data['keyword']}\". Enable case-sensitive on both rules to keep them separate.");

            return response()->json([
                'success' => false,
                'error' => $reason,
            ], 422);
        }

        $rule = $this->manager->createRule($data);

        return response()->json([
            'success' => true,
            'rule' => array_merge($rule->toArray(), $this->computeRuleStats($rule)),
        ]);
    }

    /**
     * Run a preview on a rule and return match/linked/elsewhere/not_insertable counts.
     * Used so newly created or updated rules show real numbers in the Rules table
     * without requiring a page reload.
     */
    protected function computeRuleStats(\Inkline\Linkwise\AutoLink\AutoLinkRule $rule): array
    {
        $applier = new AutoLinkApplier($this->indexer, $this->manager);
        $preview = $applier->applyRule($rule, true);
        $entries = $preview['affected_entries'] ?? [];

        return [
            'match_count' => count($entries),
            'linked_count' => count(array_filter($entries, fn ($e) => ($e['link_status'] ?? '') === 'linked_to_target')),
            'linked_elsewhere_count' => count(array_filter($entries, fn ($e) => ($e['link_status'] ?? '') === 'linked_elsewhere')),
            'not_insertable_count' => count(array_filter($entries, fn ($e) => ($e['link_status'] ?? '') === 'not_insertable')),
        ];
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'keyword' => 'string|min:1',
            'url' => 'string',
            'once_per_post' => 'boolean',
            'skip_if_exists' => 'boolean',
            'case_sensitive' => 'boolean',
            'auto_apply_on_save' => 'in:follow_global,always,never',
            'collections' => 'array',
            'active' => 'boolean',
        ]);

        $rule = $this->manager->updateRule($id, $data);

        if (! $rule) {
            return response()->json(['error' => 'Rule not found'], 404);
        }

        return response()->json([
            'success' => true,
            'rule' => array_merge($rule->toArray(), $this->computeRuleStats($rule)),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $deleted = $this->manager->deleteRule($id);

        return response()->json(['success' => $deleted]);
    }

    public function destroyMany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'string',
        ]);

        $removed = $this->manager->deleteRules($data['ids']);

        return response()->json([
            'success' => true,
            'deleted' => $removed,
        ]);
    }

    /**
     * CSV export of all auto-link rules. Triggered by the "Export CSV" button
     * in the Auto-Linking tab — used for backup, team review, agency
     * migration between sites, or bulk-edit in Excel.
     */
    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $rules = $this->manager->loadRules();
        $filename = 'linkwise-autolink-rules-'.now()->format('Y-m-d').'.csv';

        // Pre-resolve target entry titles so the CSV is human-readable in Excel.
        // Statamic's Stache caches Entry::find within the same request, so the
        // simple per-rule lookup is fine even for hundreds of rules.
        $titlesById = [];
        foreach ($rules as $rule) {
            if (! $rule->targetEntryId || isset($titlesById[$rule->targetEntryId])) {
                continue;
            }
            $entry = \Statamic\Facades\Entry::find($rule->targetEntryId);
            // null sentinel for deleted entries so the export can render '[deleted]'
            $titlesById[$rule->targetEntryId] = $entry ? (string) ($entry->get('title') ?? '') : null;
        }

        return response()->streamDownload(function () use ($rules, $titlesById) {
            $out = fopen('php://output', 'w');
            // BOM for Excel UTF-8 compatibility
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Keyword',
                'URL',
                'Target Entry ID',
                'Target Entry Title',
                'Active',
                'Case Sensitive',
                'Once Per Post',
                'Skip If Exists',
                'Auto Apply On Save',
                'Collections',
                'Created At',
                'Last Applied At',
                'Last Applied Links Added',
            ]);
            foreach ($rules as $rule) {
                fputcsv($out, [
                    $rule->keyword,
                    $rule->url,
                    $rule->targetEntryId ?? '',
                    $rule->targetEntryId ? ($titlesById[$rule->targetEntryId] ?? '[deleted]') : '',
                    $rule->active ? 'yes' : 'no',
                    $rule->caseSensitive ? 'yes' : 'no',
                    $rule->oncePerPost ? 'yes' : 'no',
                    $rule->skipIfExists ? 'yes' : 'no',
                    $rule->autoApplyOnSave,
                    implode(';', $rule->collections),
                    $rule->createdAt,
                    $rule->lastAppliedAt ?? '',
                    $rule->lastAppliedLinksAdded,
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * CSV import. Round-trip-compatible with exportCsv: same column headers,
     * same value encoding (yes/no for booleans, semicolon-separated for
     * collections). Per-row validation; returns a summary so the user can
     * see exactly what was created vs. skipped vs. failed without having to
     * re-import to find out.
     */
    public function importCsv(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return response()->json(['error' => 'Could not read uploaded file.'], 422);
        }

        // Strip UTF-8 BOM from the first row if present (Excel writes one).
        $first = fgets($handle);
        if ($first === false) {
            fclose($handle);

            return response()->json(['error' => 'Empty CSV.'], 422);
        }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);

        // Detect delimiter from the header row. PHP's fputcsv writes commas,
        // but Excel in DE/AT/FR locales saves CSV with semicolons. TSV is rare
        // but supported. We pick whichever character appears most often in the
        // header — column names never contain , ; or tabs themselves.
        $delimiter = ',';
        $counts = [
            ',' => substr_count($first, ','),
            ';' => substr_count($first, ';'),
            "\t" => substr_count($first, "\t"),
        ];
        foreach ($counts as $char => $count) {
            if ($count > $counts[$delimiter]) {
                $delimiter = $char;
            }
        }

        // Re-read the first line as a CSV header row.
        $headerRow = str_getcsv($first, $delimiter);
        $headers = array_map(
            fn ($h) => mb_strtolower(trim((string) $h)),
            $headerRow,
        );

        $required = ['keyword', 'url'];
        foreach ($required as $col) {
            if (! in_array($col, $headers, true)) {
                fclose($handle);

                return response()->json([
                    'error' => "CSV is missing required column: '{$col}'.",
                ], 422);
            }
        }

        $idx = array_flip($headers);
        $existingRules = $this->manager->loadRules();
        $created = 0;
        $createdRules = []; // returned to frontend so the table refreshes without a full reload
        $skipped = 0;
        $errors = [];
        $rowNumber = 1; // header was row 1

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;
            // Tolerate empty trailing rows (Excel adds them).
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $get = fn (string $col, $default = '') => isset($idx[$col], $row[$idx[$col]])
                ? trim((string) $row[$idx[$col]])
                : $default;

            $keyword = $get('keyword');
            $url = $get('url');
            $targetEntryId = $get('target entry id', '') ?: null;

            if ($keyword === '' || $url === '') {
                $errors[] = "Row {$rowNumber}: missing keyword or URL";
                $skipped++;

                continue;
            }

            $caseSensitive = $this->boolFromCsv($get('case sensitive'));
            $data = [
                'keyword' => $keyword,
                'url' => $url,
                'once_per_post' => $this->boolFromCsv($get('once per post', 'yes')),
                'skip_if_exists' => $this->boolFromCsv($get('skip if exists')),
                'case_sensitive' => $caseSensitive,
                // Lowercase before normalize — Excel-users often type "Always" / "Never"
                'auto_apply_on_save' => \Inkline\Linkwise\AutoLink\AutoLinkRule::normalizeAutoApply(
                    mb_strtolower($get('auto apply on save', 'follow_global'))
                ),
                'active' => $this->boolFromCsv($get('active', 'yes')),
                'collections' => $this->collectionsFromCsv($get('collections')),
            ];

            $conflict = AutoLinkManager::findDuplicate($existingRules, $keyword, $caseSensitive);
            if ($conflict) {
                $errors[] = "Row {$rowNumber}: duplicate keyword \"{$keyword}\" (already exists)";
                $skipped++;

                continue;
            }

            try {
                $rule = $this->manager->createRule($data);
                // Track in our local snapshot so subsequent rows in the SAME
                // import detect duplicates against just-imported rules too.
                $existingRules[$rule->id] = $rule;
                // Compute stats inline so the table shows real Match/Will-Link/etc
                // counts immediately, not "0" until reload. This adds ~50–500ms
                // per rule depending on entry count, but typical imports are
                // small (<20 rules) and the trade-off is worth the better UX.
                $createdRules[] = array_merge($rule->toArray(), $this->computeRuleStats($rule));
                $created++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$rowNumber}: ".mb_substr($e->getMessage(), 0, 120);
                $skipped++;
            }
        }
        fclose($handle);

        return response()->json([
            'success' => true,
            'created' => $created,
            'rules' => $createdRules,  // frontend appends these so the table refreshes immediately
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 50), // cap so a 1000-row failed import doesn't bloat the response
            'errors_truncated' => count($errors) > 50,
        ]);
    }

    /**
     * "yes"/"true"/"1" → true; everything else → false.
     */
    protected function boolFromCsv(string $v): bool
    {
        return in_array(mb_strtolower(trim($v)), ['yes', 'true', '1', 'on'], true);
    }

    /**
     * "blog;news" → ['blog', 'news']; '' → []
     */
    protected function collectionsFromCsv(string $v): array
    {
        if ($v === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($s) => trim($s),
            explode(';', $v),
        ), fn ($s) => $s !== ''));
    }

    public function toggleMany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'string',
            'active' => 'required|boolean',
        ]);

        $changed = $this->manager->setRulesActive($data['ids'], $data['active']);

        return response()->json([
            'success' => true,
            'changed' => $changed,
            'active' => $data['active'],
        ]);
    }

    public function apply(Request $request, string $id): JsonResponse
    {
        $rule = $this->manager->getRule($id);

        if (! $rule) {
            return response()->json(['error' => 'Rule not found'], 404);
        }

        $preview = $request->boolean('preview', false);
        $conflictedEntries = ! $preview
            ? SafeEntrySaver::verifyHashes($request->input('entry_hashes', []))
            : [];

        // User-picked skip list from the Preview modal ("Include" checkbox unchecked).
        $userExcluded = $request->input('excluded_entry_ids', []);
        $userExcluded = is_array($userExcluded) ? array_values(array_filter($userExcluded, 'is_string')) : [];

        $applier = new AutoLinkApplier($this->indexer, $this->manager);
        $applier->setExcludedEntries(array_values(array_unique(
            array_merge(array_keys($conflictedEntries), $userExcluded)
        )));
        $result = $applier->applyRule($rule, $preview);

        if (! empty($conflictedEntries)) {
            $result['conflicts'] = array_values($conflictedEntries);
            $result['conflict_message'] = count($conflictedEntries).' entry/entries were modified by another user and skipped.';
        }

        // Preview returns fresh hashes so the next Apply sees the entries' current state.
        // Without this, reopening the modal after a 409 sends the page-load hash again
        // and the user can't recover without a full reload.
        if ($preview) {
            $hashes = [];
            foreach ($result['affected_entries'] ?? [] as $a) {
                $eid = $a['id'] ?? null;
                if (! $eid || isset($hashes[$eid])) {
                    continue;
                }
                $e = \Statamic\Facades\Entry::find($eid);
                if ($e) {
                    $hashes[$eid] = SafeEntrySaver::hash($e);
                }
            }
            $result['entry_hashes'] = $hashes;
        }

        if (! $preview && ($result['links_added'] ?? 0) > 0) {
            $this->indexer->clearCache();
            $records = $this->indexer->buildIndex();
            $this->indexer->save($records);

            // Return fresh hashes so frontend can update for sequential rule applies
            $result['updated_hashes'] = $this->computeCurrentHashes($request->input('entry_hashes', []));
        }

        // Stamp the rule with last-applied metadata after a real (non-preview) run.
        // Done outside the rebuild branch so a 0-links-added run still records that
        // the rule was attempted — useful for users to see "ran 5 minutes ago,
        // nothing new to link" instead of "Never".
        if (! $preview) {
            $this->manager->updateRule($rule->id, [
                'last_applied_at' => now()->toIso8601String(),
                'last_applied_links_added' => $result['links_added'] ?? 0,
            ]);
            $updatedRule = $this->manager->getRule($rule->id);
            if ($updatedRule) {
                $result['rule'] = $updatedRule->toArray();
            }
        }

        return response()->json($result);
    }

    /**
     * Async Apply: queue the rule via cache + dispatch a detached artisan command.
     * Returns immediately with success — frontend polls applyAsyncStatus to follow
     * progress. Survives tab switch / reload because the work runs in a separate
     * PHP process and progress lives in cache (not in the user's session).
     */
    public function applyAsync(Request $request, string $id): JsonResponse
    {
        $rule = $this->manager->getRule($id);

        if (! $rule) {
            return response()->json(['error' => 'Rule not found'], 404);
        }

        // Reject if ANY bulk job is running (apply / bulk-unlink / scan / check) —
        // they all touch the index and entry files; running two in parallel races.
        if ($active = \Inkline\Linkwise\Support\JobLock::activeJob('applyrule')) {
            return response()->json(\Inkline\Linkwise\Support\JobLock::busyResponseData($active), 409);
        }

        \Illuminate\Support\Facades\Cache::put('linkwise:applyrule:payload', [
            'rule_id' => $id,
            'entry_hashes' => $request->input('entry_hashes', []),
            'excluded_entry_ids' => $request->input('excluded_entry_ids', []),
        ], 600);
        \Illuminate\Support\Facades\Cache::put('linkwise:applyrule:status', [
            'phase' => 'starting',
            'rule_id' => $id,
            'rule_keyword' => $rule->keyword,
        ], 600);
        \Illuminate\Support\Facades\Cache::forget('linkwise:applyrule:cancel');

        $artisan = escapeshellarg(base_path('artisan'));
        $php = escapeshellarg(PHP_BINARY);
        $log = escapeshellarg(\Inkline\Linkwise\Support\LogRotator::prepare('apply-rule.log', 'Apply Rule (single)'));

        exec("$php $artisan linkwise:apply-rule >> $log 2>&1 &");

        return response()->json(['success' => true, 'message' => 'Apply started']);
    }

    /**
     * Trigger a Multi-Rule Apply as a single heavy job.
     *
     * Used by "Apply Selected" — analog to UrlChangerApplyCommand: one POST,
     * one JobLock, one banner with nested progress (rule X of Y), one cancel,
     * one terminal toast. Replaces the previous Frontend-Loop approach which
     * 409'd intermediate rules due to bulkState propagation lag.
     */
    public function applySelectedAsync(Request $request): JsonResponse
    {
        if ($active = \Inkline\Linkwise\Support\JobLock::activeJob('applyrule')) {
            return response()->json(\Inkline\Linkwise\Support\JobLock::busyResponseData($active), 409);
        }

        $validated = $request->validate([
            'rule_ids' => 'required|array|min:1',
            'rule_ids.*' => 'required|string',
            'entry_hashes' => 'sometimes|array',
            'excluded_entry_ids' => 'sometimes|array',
            'excluded_entry_ids.*' => 'string',
        ]);

        // Defensive: wipe any leftover terminal-status from a previous run so
        // the banner can't pick up stale "total_rules" / "links_added" values
        // before our 'starting' phase lands.
        \Illuminate\Support\Facades\Cache::forget('linkwise:applyrule:status');
        \Illuminate\Support\Facades\Cache::forget('linkwise:applyrule:cancel');

        \Illuminate\Support\Facades\Cache::put('linkwise:applyrule:payload', [
            'rule_ids' => $validated['rule_ids'],
            'entry_hashes' => $validated['entry_hashes'] ?? [],
            'excluded_entry_ids' => $validated['excluded_entry_ids'] ?? [],
        ], 600);
        \Illuminate\Support\Facades\Cache::put('linkwise:applyrule:status', [
            'phase' => 'starting',
            'total_rules' => count($validated['rule_ids']),
            'current_rule_index' => 0,
            'total_links_added' => 0,
            'rule_keyword' => '',
        ], 600);

        $artisan = escapeshellarg(base_path('artisan'));
        $php = escapeshellarg(PHP_BINARY);
        $log = escapeshellarg(\Inkline\Linkwise\Support\LogRotator::prepare('apply-rule.log', 'Apply Rule (selected)'));

        exec("$php $artisan linkwise:apply-rule >> $log 2>&1 &");

        return response()->json(['success' => true, 'message' => 'Apply selected started']);
    }

    public function applyAsyncStatus(Request $request): JsonResponse
    {
        return response()->json(
            \Illuminate\Support\Facades\Cache::get('linkwise:applyrule:status') ?? ['phase' => 'idle'],
        );
    }

    public function applyAsyncCancel(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\Cache::put('linkwise:applyrule:cancel', true, 60);

        return response()->json(['success' => true]);
    }

    public function applyAll(Request $request): JsonResponse
    {
        $preview = $request->boolean('preview', false);
        $conflictedEntries = ! $preview
            ? SafeEntrySaver::verifyHashes($request->input('entry_hashes', []))
            : [];

        $applier = new AutoLinkApplier($this->indexer, $this->manager);
        $applier->setExcludedEntries(array_keys($conflictedEntries));
        $result = $applier->applyAll($preview);

        if (! empty($conflictedEntries)) {
            $result['conflicts'] = array_values($conflictedEntries);
            $result['conflict_message'] = count($conflictedEntries).' entry/entries were modified by another user and skipped.';
        }

        if (! $preview && ($result['links_added'] ?? 0) > 0) {
            $this->indexer->clearCache();
            $records = $this->indexer->buildIndex();
            $this->indexer->save($records);
        }

        return response()->json($result);
    }

    /**
     * Compute fresh hashes for entries that had hashes sent in the request.
     */
    protected function computeCurrentHashes(array $originalHashes): array
    {
        $hashes = [];
        foreach (array_keys($originalHashes) as $entryId) {
            $entry = \Statamic\Facades\Entry::find($entryId);
            if ($entry) {
                $hashes[$entryId] = SafeEntrySaver::hash($entry);
            }
        }

        return $hashes;
    }
}

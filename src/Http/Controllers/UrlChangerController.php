<?php

namespace Arturrossbach\Linkwise\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Arturrossbach\Linkwise\Exceptions\EntryConflictException;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Links\BrokenLinkChecker;
use Arturrossbach\Linkwise\Links\BrokenLinkReport;
use Arturrossbach\Linkwise\Support\BulkSnapshotStore;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Arturrossbach\Linkwise\UrlChanger\UrlReplacer;
use Statamic\Http\Controllers\CP\CpController;

class UrlChangerController extends CpController
{
    public function __construct(
        protected UrlReplacer $replacer,
        protected EntryIndexer $indexer,
        protected BrokenLinkReport $brokenReport,
        protected BrokenLinkChecker $brokenChecker,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string|max:2048',
            'mode' => 'string|in:smart,exact',
        ]);

        $this->replacer->setMode($request->input('mode', 'smart'));
        // Laravel's ConvertEmptyStringsToNull middleware turns "" into null
        // before validation. The replacer signature requires string, so coalesce.
        $search = $request->input('search') ?? '';
        $result = $this->replacer->preview($search, '');

        // Add edit_url and content hash for each entry
        foreach ($result['entries'] as &$entry) {
            $entry['edit_url'] = cp_route('collections.entries.edit', [
                $entry['collection'],
                $entry['id'],
            ]);

            // Hash for optimistic locking — frontend sends this back on apply
            $statamicEntry = \Statamic\Facades\Entry::find($entry['id']);
            $entry['content_hash'] = $statamicEntry ? SafeEntrySaver::hash($statamicEntry) : '';
        }

        return response()->json($result);
    }

    /**
     * Apply replacements for selected occurrences (sync, per-row).
     * Accepts an array of replacements: [{entry_id, matched_url, new_url}]
     *
     * Live caller: BrokenLinksTab "Unlink" per row. The async batch endpoint
     * (applyAsync) is for multi-replacement runs — this one is a fast,
     * UI-feedback-driven single op.
     *
     * Concurrency: refuse when ANY other heavy job is running. Without this
     * guard, an editor could click per-row Unlink while a Scan / Apply Rule /
     * URL-Changer batch is in flight — both writers race on the same entry
     * file and on the index. SafeEntrySaver still defends each entry, but
     * the user-facing failure is a confusing 409 toast for an action they
     * shouldn't have been allowed to trigger in the first place.
     */
    public function apply(Request $request): JsonResponse
    {
        if ($active = \Arturrossbach\Linkwise\Support\JobLock::activeJob('urlchanger')) {
            return response()->json(\Arturrossbach\Linkwise\Support\JobLock::busyResponseData($active), 409);
        }

        $request->validate([
            'search' => 'nullable|string|max:2048',
            'entry_hashes' => 'sometimes|array|max:50000',
            'mode' => 'sometimes|in:smart,exact',
            'replacements' => 'required|array|min:1|max:5000',
            'replacements.*.entry_id' => 'required|string|max:64',
            'replacements.*.field' => 'nullable|string|max:80',
            'replacements.*.field_type' => 'nullable|string|max:40',
            'replacements.*.matched_url' => 'required|string|max:2048',
            'replacements.*.occurrence_index' => 'required|numeric|min:0',
            'replacements.*.new_url' => 'required|string|min:3|max:2048', // Use UrlHelper::UNLINK to unlink
            'replacements.*.search' => 'nullable|string|max:2048', // Per-item search override for batch unlink
            // Anchor + sentence carried from preview so the activity-log
            // drawer can show what was changed AND so an Unlink-revert can
            // re-add the link mark on the original anchor (outbound-insert).
            'replacements.*.anchor_text' => 'nullable|string|max:512',
            'replacements.*.sentence_context' => 'nullable|string|max:1024',
        ]);

        // Mode controls URL matching semantics:
        // - 'smart' (default): domain-aware, used by URL Changer for "replace all links on domain X"
        // - 'exact': literal string match, used by Broken Links to target one specific URL only
        $this->replacer->setMode($request->input('mode', 'smart'));

        // Verify entry hashes before applying — detect concurrent modifications.
        // Only check hashes for entries we're about to modify; unrelated entries'
        // hashes may drift during bulk operations (index rebuilds, Statamic caching)
        // and would produce false-positive conflicts otherwise.
        $allHashes = $request->input('entry_hashes', []);
        $replacementEntryIds = array_flip(array_unique(array_column($request->replacements, 'entry_id')));
        $relevantHashes = array_intersect_key($allHashes, $replacementEntryIds);
        $conflicts = SafeEntrySaver::verifyHashes($relevantHashes);
        if (! empty($conflicts)) {
            $title = reset($conflicts);

            return response()->json([
                'error' => 'conflict',
                'message' => 'Entry "'.$title.'" was modified by another editor.',
                'entry_id' => array_key_first($conflicts),
            ], 409);
        }

        // Forensic snapshot — recorded BEFORE the write for entry-id forensics
        // (we know what the bulk INTENDED to touch even if applySelected
        // throws mid-flight). items=[] starts empty; we append each
        // confirmed replacement after applySelected returns. See the
        // commands' append-on-success pattern.
        $snapshotEntryIds = array_values(array_unique(array_filter(
            array_column($request->replacements, 'entry_id'),
            'is_string',
        )));
        $snapshotId = app(BulkSnapshotStore::class)->record(
            kind: 'urlchanger',
            entryIds: $snapshotEntryIds,
            preHashes: array_intersect_key($allHashes, array_flip($snapshotEntryIds)),
            summary: [
                'mode' => $request->input('mode', 'smart'),
                'search' => $request->input('search') ?? '',
                'replacement_count' => count($request->replacements),
                'caller' => 'sync', // distinguishes per-row Broken-Links unlink from the async batch
            ],
            items: [],
        );

        try {
            // Coalesce — see preview() comment about ConvertEmptyStringsToNull.
            $result = $this->replacer->applySelected($request->input('search') ?? '', $request->replacements);
        } catch (EntryConflictException $e) {
            return response()->json([
                'error' => 'conflict',
                'message' => $e->getMessage(),
                'entry_id' => $e->entryId,
            ], 409);
        }

        // Append-on-success: only confirmed replacements end up in items.
        // The sync path can't do per-item granularity (UrlReplacer batches
        // internally and only reports total_replacements), so when at
        // least one landed we record all replacements for this batch as a
        // best-effort. Zero-replacement runs leave items empty — honest.
        if (($result['total_replacements'] ?? 0) > 0) {
            foreach ($request->replacements as $r) {
                app(BulkSnapshotStore::class)->appendWrittenItem($snapshotId, [
                    'entry_id' => $r['entry_id'] ?? '',
                    'matched_url' => $r['matched_url'] ?? '',
                    'new_url' => $r['new_url'] ?? '',
                    'anchor_text' => $r['anchor_text'] ?? '',
                    'sentence_context' => $r['sentence_context'] ?? '',
                ]);
            }
        }

        // Rebuild index (skip on intermediate sequential requests for per-item UI feedback)
        $skipRebuild = $request->boolean('skip_rebuild', false);
        if (! $skipRebuild) {
            $this->indexer->clearCache();
            $records = $this->indexer->buildIndex();
            $this->indexer->save($records);
        }

        // Update broken links report: remove old URLs, check new ones.
        // Skip the "add new broken record" step if no actual replacement happened
        // (applySelected returned 0) — we didn't write anything, so there's no
        // new URL in any entry to track.
        $stillBroken = [];
        $actualReplacementHappened = ($result['total_replacements'] ?? 0) > 0;

        foreach ($request->replacements as $r) {
            $this->brokenReport->removeLink($r['entry_id'], $r['matched_url']);

            if ($actualReplacementHappened && $r['new_url'] !== \Arturrossbach\Linkwise\Support\UrlHelper::UNLINK) {
                $checkResult = $this->brokenChecker->checkUrl($r['new_url']);
                if ($checkResult !== null) {
                    $report = $this->brokenReport->load();
                    $entryForTitle = \Statamic\Facades\Entry::find($r['entry_id']);
                    $now = now()->toIso8601String();
                    $newRecord = new \Arturrossbach\Linkwise\Links\BrokenLinkRecord(
                        postId: $r['entry_id'],
                        postTitle: $entryForTitle?->get('title') ?? $r['entry_id'],
                        url: $r['new_url'],
                        anchorText: '',
                        type: 'external',
                        statusCode: $checkResult['status_code'],
                        errorType: $checkResult['error_type'],
                        firstDetectedAt: $now,
                        lastCheckedAt: $now,
                    );
                    $report['broken_links'][] = $newRecord;
                    $this->brokenReport->save($report['broken_links'], $report['metadata']['duration_seconds'] ?? 0);

                    $stillBroken[] = [
                        'entry_id' => $r['entry_id'],
                        'new_url' => $r['new_url'],
                        'status_label' => $newRecord->statusLabel(),
                        'error_type' => $checkResult['error_type'],
                    ];
                }
            }
        }

        $result['still_broken'] = $stillBroken;

        // Return fresh hashes for all affected entries so frontend can update
        $updatedHashes = [];
        $affectedEntryIds = [];
        foreach ($request->replacements as $r) {
            $entryId = $r['entry_id'];
            if (! isset($updatedHashes[$entryId])) {
                $entry = \Statamic\Facades\Entry::find($entryId);
                $updatedHashes[$entryId] = $entry ? SafeEntrySaver::hash($entry) : '';
                $affectedEntryIds[] = $entryId;
            }
        }
        $result['updated_hashes'] = $updatedHashes;

        // Compute live suggestion counts for affected entries
        $result['suggestion_counts'] = $this->indexer->computeSuggestionCountsForEntries($affectedEntryIds);

        // Post-bulk hashes — same as in async commands. Without this, the
        // activity-log compareToCurrent treats the bulk's own writes as
        // "modified by user since the bulk".
        app(BulkSnapshotStore::class)->recordPostHashesForEntries($snapshotId, $snapshotEntryIds);
        app(BulkSnapshotStore::class)->markCompleted($snapshotId, [
            'phase' => 'done',
            'total_replacements' => $result['total_replacements'] ?? 0,
        ]);

        return response()->json($result);
    }

    /**
     * Trigger an async URL Changer batch (heavy job).
     *
     * Why heavy: domain migrations can hit 500+ replacements. A frontend loop
     * dies on browser tab close / reload / nav-away — losing progress.
     * The detached artisan command survives all of those, the LinkwiseLayout
     * poller picks up state on every Linkwise tab.
     *
     * Concurrency: refuses to start while ANY heavy job is already running
     * (scan / check / bulkunlink / applyrule / urlchanger). Returns 409 with
     * a busy-payload that the frontend can show as a friendly toast.
     */
    public function applyAsync(Request $request): JsonResponse
    {
        if ($active = \Arturrossbach\Linkwise\Support\JobLock::activeJob('urlchanger')) {
            return response()->json(\Arturrossbach\Linkwise\Support\JobLock::busyResponseData($active), 409);
        }

        $validated = $request->validate([
            'search' => 'nullable|string|max:2048',
            'mode' => 'sometimes|in:smart,exact',
            'action' => 'required|in:apply,unlink',
            'entry_hashes' => 'sometimes|array|max:50000',
            'replacements' => 'required|array|min:1|max:5000',
            'replacements.*.entry_id' => 'required|string|max:64',
            'replacements.*.field' => 'nullable|string|max:80',
            'replacements.*.field_type' => 'nullable|string|max:40',
            'replacements.*.matched_url' => 'required|string|max:2048',
            'replacements.*.occurrence_index' => 'required|numeric|min:0',
            'replacements.*.new_url' => 'required|string|min:3|max:2048',
            'replacements.*.search' => 'nullable|string|max:2048',
            // Anchor + context per item — needed by Activity-log drawer
            // (always-visible Context column) AND by Unlink-revert routing
            // (outbound-insert needs anchor to find the right plain-text run).
            'replacements.*.anchor_text' => 'nullable|string|max:512',
            'replacements.*.sentence_context' => 'nullable|string|max:1024',
            // Activity-log Revert flow — marks the new snapshot as a reverse-of-X.
            'reverts' => 'sometimes|nullable|string|max:64',
        ]);

        // Hash conflicts: DON'T fail-fast 409 (Bug 9 2026-05-11). Dispatch
        // anyway and let UrlChangerApplyCommand's per-record verifyHashes
        // (line 164) skip the modified entries with a clean "X applied,
        // Y skipped (entry modified)" toast. Consistent with the other
        // async bulk endpoints. The 169-individual-conflicts concern from
        // the old comment is moot — errors are aggregated by reason in
        // status.errors and the toast shows the top reason.
        $allHashes = $validated['entry_hashes'] ?? [];

        // Owner-Tracking: surface WHO started this job so other editors who
        // see the cross-tab banner / 409-conflict toast know it's a colleague's
        // run, not a stuck job. Falls back to email/id when the user has no
        // display name set.
        $user = auth()->user();
        $startedBy = $user?->name() ?? $user?->email() ?? null;
        $startedById = $user?->id() ?? null;

        \Illuminate\Support\Facades\Cache::put('linkwise:urlchanger:payload', [
            'replacements' => $validated['replacements'],
            'search' => $validated['search'] ?? '',
            'mode' => $validated['mode'] ?? 'smart',
            'action' => $validated['action'],
            'entry_hashes' => $allHashes,
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
            'reverts' => $validated['reverts'] ?? null,
        ], 600);

        \Illuminate\Support\Facades\Cache::put('linkwise:urlchanger:status', [
            'phase' => 'starting',
            'total' => count($validated['replacements']),
            'current' => 0,
            'action' => $validated['action'],
            'search' => $validated['search'] ?? '',
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
        ], 600);

        \Illuminate\Support\Facades\Cache::forget('linkwise:urlchanger:cancel');

        $artisan = escapeshellarg(base_path('artisan'));
        $php = escapeshellarg(PHP_BINARY);
        $log = escapeshellarg(\Arturrossbach\Linkwise\Support\LogRotator::prepare('url-changer-apply.log', 'URL Changer Apply'));

        exec("$php $artisan linkwise:url-changer:apply >> $log 2>&1 &");

        return response()->json(['success' => true, 'message' => 'URL Changer batch started']);
    }

    public function applyStatus(Request $request): JsonResponse
    {
        return response()->json(
            \Illuminate\Support\Facades\Cache::get('linkwise:urlchanger:status') ?? ['phase' => 'idle'],
        );
    }

    public function applyCancel(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\Cache::put('linkwise:urlchanger:cancel', true, 60);

        return response()->json(['success' => true]);
    }
}

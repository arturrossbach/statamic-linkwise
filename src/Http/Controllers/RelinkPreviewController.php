<?php

namespace Arturrossbach\Linkwise\Http\Controllers;

use Arturrossbach\Linkwise\Support\BardLinkInserter;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\Entry;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Pre-flight check for the 2-step re-link flow.
 *
 * Bug 17 (2026-05-11): the DetailModal re-link runs Step 1 (sync URL-Changer
 * unlink) and Step 2 (async link-insert) in sequence. When the user expands
 * an anchor across an existing link, Step 1 commits but Step 2 fails at the
 * already-linked guard — partial state with no rollback.
 *
 * This endpoint asks BardLinkInserter::canInsertLinkIntoEntry whether Step 2
 * WOULD succeed, before Step 1 ever runs. The frontend calls it on the source
 * entry; on ok:false it shows an actionable error toast naming the blocking
 * link and DOES NOT touch the entry. On ok:true it proceeds with the existing
 * 2-step flow.
 */
class RelinkPreviewController extends CpController
{
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entry_id' => ['required', 'string', 'max:64'],
            'content_hash' => ['nullable', 'string', 'max:128'],
            // target_entry_id (internal) OR href (external) — same convention
            // as the insert endpoints.
            'target_entry_id' => ['nullable', 'string', 'max:64'],
            'href' => ['nullable', 'string', 'max:2048'],
            'anchor_text' => ['required', 'string', 'max:500'],
            'sentence_context' => ['nullable', 'string', 'max:1024'],
            // The link Step 1 will unlink before Step 2 inserts. Marks
            // with this href are treated as post-Step-1 (= ignored) by
            // the dry-run, so simple anchor-expansion within a same-
            // target link does NOT trigger a false already-linked
            // refusal. Different-target marks remain genuine blockers.
            'original_href' => ['nullable', 'string', 'max:2048'],
        ]);

        if (empty($validated['target_entry_id']) && empty($validated['href'])) {
            return response()->json([
                'ok' => false,
                'reason' => 'invalid_request',
                'message' => 'target_entry_id or href required',
            ], 422);
        }

        $entry = Entry::find($validated['entry_id']);
        if (! $entry) {
            return response()->json([
                'ok' => false,
                'reason' => 'entry_not_found',
                'message' => 'Eintrag wurde inzwischen gelöscht.',
            ]);
        }

        // Stale-modal guard — if the entry changed since the modal opened,
        // any preview answer is for the old version. Surface that directly
        // so the user refreshes instead of running Step 1 against stale
        // assumptions. SafeEntrySaver::verifyHashes in Step 1 would catch
        // this too, but we want the friendlier message before the unlink.
        if (! empty($validated['content_hash'])) {
            $currentHash = SafeEntrySaver::hash($entry);
            if ($currentHash !== $validated['content_hash']) {
                return response()->json([
                    'ok' => false,
                    'reason' => 'entry_changed',
                    'message' => 'Eintrag wurde inzwischen geändert. Bitte Modal schließen und neu öffnen.',
                ]);
            }
        }

        $href = $validated['href']
            ?? 'statamic://entry::'.$validated['target_entry_id'];

        $result = BardLinkInserter::canInsertLinkIntoEntry(
            $validated['entry_id'],
            $validated['anchor_text'],
            $href,
            false,
            $validated['sentence_context'] ?? null,
            $validated['original_href'] ?? null,
        );

        // Friendly German message per reason — the modal renders this
        // verbatim in the toast. Keep wording action-oriented (what the
        // user can DO about it) rather than just describing the failure.
        if (! ($result['ok'] ?? false)) {
            $reason = $result['reason'] ?? 'anchor_not_found';
            $blocking = $result['blocking_href'] ?? null;
            $blockingLabel = $blocking ? $this->formatHref($blocking) : null;

            $messages = [
                'anchor_not_found' => 'Der Anker-Text ist nicht (mehr) im Eintrag enthalten.',
                'context_mismatch' => 'Der ursprüngliche Satz-Kontext ist nicht mehr im Eintrag — bitte Modal neu öffnen.',
                'crosses_existing_link' => $blockingLabel !== null
                    ? "Der Anker überlappt mit einem bestehenden Link auf {$blockingLabel} im selben Eintrag. Bitte den Link zuerst entfernen und erneut versuchen."
                    : 'Der Anker überlappt mit einem bestehenden Link im selben Eintrag. Bitte den Link zuerst entfernen.',
                'already_linked_to_target' => 'Dieser Text ist bereits mit dem Ziel verlinkt.',
            ];

            return response()->json([
                'ok' => false,
                'reason' => $reason,
                'blocking_href' => $blocking,
                'message' => $messages[$reason] ?? 'Re-Link würde fehlschlagen.',
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Render a `statamic://entry::uuid` href as the entry's title where
     * possible, falling back to the raw URL. Keeps the toast readable —
     * "Link auf 'Erdnuss-Soba-Nudeln Rezept'" beats a UUID.
     */
    protected function formatHref(string $href): string
    {
        if (str_starts_with($href, 'statamic://entry::')) {
            $id = substr($href, strlen('statamic://entry::'));
            $entry = Entry::find($id);
            if ($entry) {
                return "'".$entry->get('title', $id)."'";
            }
        }

        return $href;
    }
}

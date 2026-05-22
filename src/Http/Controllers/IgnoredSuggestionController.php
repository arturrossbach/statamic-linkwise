<?php

namespace Arturrossbach\Linkwise\Http\Controllers;

use Arturrossbach\Linkwise\Suggestions\IgnoredSuggestionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Manage per-pair ignored suggestions.
 *
 * Mirror-shape to {@see TargetKeywordController} and
 * {@see ExcludedContentKeywordController} — simple POST/DELETE with
 * sourceEntryId + targetEntryId. The store normalises the pair so
 * caller-direction is irrelevant.
 *
 * Frontend uses these from the Suggestion Modal's ✕ (ignore) and ↩
 * (un-ignore) buttons. Both return the fresh ignored-count for the
 * source so the modal can update its "Show ignored (N)" badge
 * without a roundtrip; the broader counts in Links Report refresh
 * via `inertiaRouter.reload({only: ['entries']})` initiated by the
 * frontend after the mutation succeeds — see Klasse-10
 * guarantee-stack from the 2026-05-22 launch-eve session.
 */
class IgnoredSuggestionController extends CpController
{
    public function __construct(
        protected IgnoredSuggestionStore $store,
    ) {}

    /**
     * Mark a suggestion pair as ignored.
     * POST /cp/linkwise/ignored-suggestions
     * Body: { source_entry_id, target_entry_id }
     */
    public function ignore(Request $request): JsonResponse
    {
        $source = (string) $request->input('source_entry_id', '');
        $target = (string) $request->input('target_entry_id', '');

        if ($source === '' || $target === '' || $source === $target) {
            return response()->json(['error' => 'source_entry_id and target_entry_id required and distinct'], 422);
        }

        $this->store->ignore($source, $target);

        return response()->json([
            'success' => true,
            'ignored' => true,
            // Echo back the canonical state so the frontend can
            // optimistically update without re-fetching.
            'ignored_count_for_source' => $this->store->ignoredCountFor($source),
            'ignored_count_for_target' => $this->store->ignoredCountFor($target),
        ]);
    }

    /**
     * Un-ignore a previously-ignored suggestion pair.
     * DELETE /cp/linkwise/ignored-suggestions
     * Body: { source_entry_id, target_entry_id }
     */
    public function unignore(Request $request): JsonResponse
    {
        $source = (string) $request->input('source_entry_id', '');
        $target = (string) $request->input('target_entry_id', '');

        if ($source === '' || $target === '' || $source === $target) {
            return response()->json(['error' => 'source_entry_id and target_entry_id required and distinct'], 422);
        }

        $this->store->unignore($source, $target);

        return response()->json([
            'success' => true,
            'ignored' => false,
            'ignored_count_for_source' => $this->store->ignoredCountFor($source),
            'ignored_count_for_target' => $this->store->ignoredCountFor($target),
        ]);
    }
}

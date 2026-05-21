<?php

namespace Arturrossbach\Linkwise\Http\Controllers;

use Arturrossbach\Linkwise\Keywords\ExcludedContentKeywordManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Per-entry block-list management for content keywords surfaced by
 * the Target Keywords tab. Mirror-pattern to
 * {@see TargetKeywordController} — full-array replace on every PUT,
 * no per-keyword add/remove endpoints (simpler API, smaller surface).
 *
 * Frontend calls this whenever the user clicks an ✕ on a content-
 * keyword badge: it sends the new full excluded-list (current
 * excluded + the just-clicked keyword).
 */
class ExcludedContentKeywordController extends CpController
{
    public const MAX_EXCLUDED_PER_ENTRY = 100;

    public const MAX_KEYWORD_LENGTH = 100;

    public function __construct(
        protected ExcludedContentKeywordManager $manager,
    ) {}

    public function update(Request $request, string $entryId): JsonResponse
    {
        $keywords = $request->input('keywords', []);

        if (! is_array($keywords)) {
            return response()->json(['error' => 'keywords must be an array'], 422);
        }

        $keywords = array_values(array_filter(array_map(
            fn ($k) => is_string($k) ? trim($k) : '',
            $keywords,
        )));

        if (count($keywords) > self::MAX_EXCLUDED_PER_ENTRY) {
            return response()->json([
                'error' => 'too_many_excluded',
                'message' => 'Too many excluded keywords: '.count($keywords).' / '.self::MAX_EXCLUDED_PER_ENTRY.' max.',
            ], 422);
        }

        foreach ($keywords as $k) {
            if (mb_strlen($k) > self::MAX_KEYWORD_LENGTH) {
                return response()->json([
                    'error' => 'keyword_too_long',
                    'message' => 'Keyword exceeds '.self::MAX_KEYWORD_LENGTH.' chars.',
                ], 422);
            }
        }

        $this->manager->setExcluded($entryId, $keywords);

        return response()->json([
            'success' => true,
            'excluded' => $this->manager->getExcluded($entryId),
        ]);
    }
}

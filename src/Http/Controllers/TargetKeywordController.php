<?php

namespace Arturrossbach\Linkwise\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Arturrossbach\Linkwise\Keywords\TargetKeywordManager;
use Statamic\Http\Controllers\CP\CpController;

class TargetKeywordController extends CpController
{
    /**
     * Validation limits — kept in sync with the frontend constants in
     * TargetKeywordsTab.vue. Defense-in-depth: client-side gating gives
     * immediate feedback; server-side enforces the actual contract so a
     * direct API call can't bypass.
     */
    public const MAX_KEYWORDS_PER_ENTRY = 50;

    public const MAX_KEYWORD_LENGTH = 50;

    public function __construct(
        protected TargetKeywordManager $manager,
    ) {}

    public function update(Request $request, string $entryId): JsonResponse
    {
        $keywords = $request->input('keywords', []);

        // Accept either a comma-separated string (legacy/manual API call)
        // or an array (frontend default). Normalize to array of trimmed
        // non-empty strings.
        if (is_string($keywords)) {
            $keywords = array_filter(array_map('trim', explode(',', $keywords)));
        }
        if (! is_array($keywords)) {
            return response()->json(['error' => 'keywords must be array or string'], 422);
        }

        $keywords = array_values(array_filter(array_map(
            fn ($k) => is_string($k) ? trim($k) : '',
            $keywords,
        )));

        if (count($keywords) > self::MAX_KEYWORDS_PER_ENTRY) {
            return response()->json([
                'error' => 'too_many_keywords',
                'message' => 'Too many keywords: '.count($keywords).' / '.self::MAX_KEYWORDS_PER_ENTRY.' max.',
            ], 422);
        }

        foreach ($keywords as $k) {
            if (mb_strlen($k) > self::MAX_KEYWORD_LENGTH) {
                return response()->json([
                    'error' => 'keyword_too_long',
                    'message' => 'Keyword exceeds '.self::MAX_KEYWORD_LENGTH.' chars: "'.mb_substr($k, 0, 30).'…"',
                ], 422);
            }
        }

        $this->manager->setKeywords($entryId, $keywords);

        return response()->json([
            'success' => true,
            'keywords' => $this->manager->getKeywords($entryId),
        ]);
    }
}

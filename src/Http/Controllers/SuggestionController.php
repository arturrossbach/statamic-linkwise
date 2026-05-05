<?php

namespace Inkline\Linkwise\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inkline\Linkwise\Indexer\EntryIndexer;
use Inkline\Linkwise\Suggestions\SuggestionEngine;
use Statamic\Http\Controllers\CP\CpController;

class SuggestionController extends CpController
{
    public function __construct(
        protected EntryIndexer $indexer,
        protected SuggestionEngine $engine,
    ) {}

    public function suggest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:200000'],
            'entry_id' => ['sometimes', 'nullable', 'string'],
        ]);

        $index = $this->indexer->load();

        $indexAge = $this->indexer->getIndexAge();

        if (empty($index)) {
            return response()->json([
                'suggestions' => [],
                'index_age_seconds' => null,
            ]);
        }

        $suggestions = $this->engine->suggest(
            $validated['text'],
            $index,
            $validated['entry_id'] ?? null,
        );

        return response()->json([
            'suggestions' => array_map(fn ($s) => $s->toArray(), $suggestions),
            'index_age_seconds' => $indexAge,
        ]);
    }
}

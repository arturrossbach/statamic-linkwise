<?php

namespace Arturrossbach\Linkwise\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Arturrossbach\Linkwise\Links\BrokenLinkReport;
use Statamic\Http\Controllers\CP\CpController;

class IgnoredLinkController extends CpController
{
    public function __construct(
        protected BrokenLinkReport $report,
    ) {}

    /**
     * Mark a broken link as ignored (false positive). The flag lives on the record
     * in broken-links.json — no separate list. The `ignored` column is filterable
     * in the UI Status multiselect.
     */
    public function ignore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'url' => ['required', 'string'],
        ]);

        $found = $this->report->setIgnored($validated['post_id'], $validated['url'], true);

        return response()->json(['success' => $found]);
    }

    /**
     * Un-ignore — clears the flag on the record. Row stays in the list until the
     * next scan; if the URL is now OK, scan will drop it automatically.
     */
    public function unignore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'url' => ['required', 'string'],
        ]);

        $found = $this->report->setIgnored($validated['post_id'], $validated['url'], false);

        return response()->json(['success' => $found]);
    }
}

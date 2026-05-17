<?php

use Illuminate\Support\Facades\Route;
use Arturrossbach\Linkwise\Http\Controllers\AutoLinkController;
use Arturrossbach\Linkwise\Http\Controllers\AutoLink\AutoLinkApplySyncController;
use Arturrossbach\Linkwise\Http\Controllers\TargetKeywordController;
use Arturrossbach\Linkwise\Http\Controllers\Dashboard\ActivityController;
use Arturrossbach\Linkwise\Http\Controllers\Dashboard\BulkJobsController;
use Arturrossbach\Linkwise\Http\Controllers\Dashboard\InertiaPagesController;
use Arturrossbach\Linkwise\Http\Controllers\Dashboard\JobsAggregatorController;
use Arturrossbach\Linkwise\Http\Controllers\Dashboard\StatsApiController;
use Arturrossbach\Linkwise\Http\Controllers\DashboardController;
use Arturrossbach\Linkwise\Http\Controllers\EntryHashesController;
use Arturrossbach\Linkwise\Http\Controllers\IgnoredLinkController;
use Arturrossbach\Linkwise\Http\Controllers\InboundController;
use Arturrossbach\Linkwise\Http\Controllers\OutboundController;
use Arturrossbach\Linkwise\Http\Controllers\RelinkController;
use Arturrossbach\Linkwise\Http\Controllers\UrlChangerController;

Route::middleware('can:manage linkwise')->group(function () {

    // ─── Inertia Pages (one per tab) ───────────────────────────────────

    Route::get('linkwise', [InertiaPagesController::class, 'index'])
        ->name('linkwise.dashboard');

    Route::get('linkwise/links', [InertiaPagesController::class, 'links'])
        ->name('linkwise.links');

    Route::get('linkwise/broken', [InertiaPagesController::class, 'broken'])
        ->name('linkwise.broken');

    Route::get('linkwise/domains', [InertiaPagesController::class, 'domains'])
        ->name('linkwise.domains');

    Route::get('linkwise/autolink', [InertiaPagesController::class, 'autolink'])
        ->name('linkwise.autolink');

    Route::get('linkwise/keywords', [InertiaPagesController::class, 'keywords'])
        ->name('linkwise.keywords');

    Route::get('linkwise/url-changer', [InertiaPagesController::class, 'urlChanger'])
        ->name('linkwise.urlchanger');

    Route::get('linkwise/activity', [InertiaPagesController::class, 'activity'])
        ->name('linkwise.activity');
    Route::get('linkwise/activity/{id}', [ActivityController::class, 'activityDetail'])
        ->name('linkwise.activity.detail');
    Route::post('linkwise/activity/{id}/mark-reverted', [ActivityController::class, 'markActivityReverted'])
        ->name('linkwise.activity.mark-reverted');

    // ─── Suggestion Insert ───────────────────────────────────────────

    Route::post('linkwise/outbound/insert', [OutboundController::class, 'insert'])
        ->name('linkwise.outbound.insert');

    // ─── Suggestion API (JSON for modals) ────────────────────────────

    Route::get('linkwise/inbound/{entryId}/suggestions', [InboundController::class, 'suggestions'])
        ->name('linkwise.inbound.suggestions');

    Route::get('linkwise/outbound/{entryId}/suggestions', [OutboundController::class, 'suggestions'])
        ->name('linkwise.outbound.suggestions');

    // ─── API Endpoints ─────────────────────────────────────────────────

    Route::get('linkwise/stats/{entryId}', [StatsApiController::class, 'entryStats'])
        ->name('linkwise.entry-stats');

    Route::get('linkwise/suggestion-counts', [StatsApiController::class, 'suggestionCounts'])
        ->name('linkwise.suggestion-counts');

    // Bulk content-hash fetch — Klasse-7 C-1 residual race closure
    // (docs/ARCHITECTURE_REVIEW.md). Frontend's `showDetail` async-
    // fetches fresh hashes before populating DetailModal so the next
    // bulk-unlink/url-change/apply uses current state instead of the
    // stale localEntries snapshot from before the last partial-reload.
    Route::get('linkwise/entry-hashes', [EntryHashesController::class, 'index'])
        ->name('linkwise.entry-hashes');

    Route::post('linkwise/rebuild-index', [BulkJobsController::class, 'rebuildIndex'])
        ->name('linkwise.rebuild-index');
    Route::get('linkwise/rebuild-index/status', [BulkJobsController::class, 'rebuildIndexStatus'])
        ->name('linkwise.rebuild-index.status');
    Route::post('linkwise/rebuild-index/cancel', [BulkJobsController::class, 'rebuildIndexCancel'])
        ->name('linkwise.rebuild-index.cancel');

    Route::post('linkwise/check-links', [BulkJobsController::class, 'checkLinks'])
        ->name('linkwise.check-links');
    Route::get('linkwise/check-links/status', [BulkJobsController::class, 'checkLinksStatus'])
        ->name('linkwise.check-links.status');
    Route::post('linkwise/check-links/cancel', [BulkJobsController::class, 'checkLinksCancel'])
        ->name('linkwise.check-links.cancel');

    Route::post('linkwise/bulk-unlink', [BulkJobsController::class, 'bulkUnlink'])
        ->name('linkwise.bulk-unlink');
    Route::get('linkwise/bulk-unlink/status', [BulkJobsController::class, 'bulkUnlinkStatus'])
        ->name('linkwise.bulk-unlink.status');
    Route::post('linkwise/bulk-unlink/cancel', [BulkJobsController::class, 'bulkUnlinkCancel'])
        ->name('linkwise.bulk-unlink.cancel');
    Route::get('linkwise/bulk-status', [JobsAggregatorController::class, 'bulkStatus'])
        ->name('linkwise.bulk-status');
    // Force-clear a stuck heavy-job (used by the "Operation seems stuck" UI
    // recovery button — for cases the crash-guard somehow missed, e.g. server
    // restart before shutdown_function had a chance to fire).
    Route::post('linkwise/bulk-clear/{kind}', [JobsAggregatorController::class, 'bulkClear'])
        ->name('linkwise.bulk-clear');

    // DetailModal Bulk-Unlink — heavy job (single POST, server iterates).
    Route::post('linkwise/detail-unlink-async', [BulkJobsController::class, 'detailUnlinkAsync'])
        ->name('linkwise.detail-unlink.async');
    Route::get('linkwise/detail-unlink-async/status', [BulkJobsController::class, 'detailUnlinkStatus'])
        ->name('linkwise.detail-unlink.status');
    Route::post('linkwise/detail-unlink-async/cancel', [BulkJobsController::class, 'detailUnlinkCancel'])
        ->name('linkwise.detail-unlink.cancel');
    Route::get('linkwise/broken-links/export', [DashboardController::class, 'brokenLinksCsv'])
        ->name('linkwise.broken-links.export');
    Route::get('linkwise/domains/export', [DashboardController::class, 'domainsCsv'])
        ->name('linkwise.domains.export');
    Route::get('linkwise/debug-export', [DashboardController::class, 'debugExport'])
        ->name('linkwise.debug-export');
    Route::post('linkwise/frontend-error', [DashboardController::class, 'frontendError'])
        ->middleware('throttle:100,1')
        ->name('linkwise.frontend-error');

    Route::post('linkwise/domain-attribute', [StatsApiController::class, 'saveDomainAttribute'])
        ->name('linkwise.save-domain-attribute');

    Route::post('linkwise/inbound/insert', [InboundController::class, 'insert'])
        ->name('linkwise.inbound.insert');

    // Bug 17 Phase C: atomic re-link. One POST replaces the previous
    // Step 1 (url-changer apply) + Step 2 (async insert) chain. Sync,
    // single save, hash-checked, no JobLock.
    Route::post('linkwise/relink', [RelinkController::class, 'relink'])
        ->name('linkwise.relink');

    Route::post('linkwise/inbound/insert/cancel', [BulkJobsController::class, 'inboundInsertCancel'])
        ->name('linkwise.inbound.insert.cancel');
    Route::post('linkwise/outbound/insert/cancel', [BulkJobsController::class, 'outboundInsertCancel'])
        ->name('linkwise.outbound.insert.cancel');

    // Auto-Linking
    Route::post('linkwise/autolink/rules', [AutoLinkController::class, 'store'])
        ->name('linkwise.autolink.store');
    Route::put('linkwise/autolink/rules/{id}', [AutoLinkController::class, 'update'])
        ->name('linkwise.autolink.update');
    Route::delete('linkwise/autolink/rules/{id}', [AutoLinkController::class, 'destroy'])
        ->name('linkwise.autolink.destroy');
    Route::post('linkwise/autolink/rules/bulk-delete', [AutoLinkController::class, 'destroyMany'])
        ->name('linkwise.autolink.bulk-delete');
    Route::post('linkwise/autolink/rules/bulk-toggle', [AutoLinkController::class, 'toggleMany'])
        ->name('linkwise.autolink.bulk-toggle');
    Route::get('linkwise/autolink/rules/export', [AutoLinkController::class, 'exportCsv'])
        ->name('linkwise.autolink.export');
    Route::post('linkwise/autolink/rules/import', [AutoLinkController::class, 'importCsv'])
        ->name('linkwise.autolink.import');
    Route::post('linkwise/autolink/apply/{id}', [AutoLinkApplySyncController::class, 'apply'])
        ->name('linkwise.autolink.apply');
    Route::post('linkwise/autolink/apply-async/{id}', [AutoLinkController::class, 'applyAsync'])
        ->name('linkwise.autolink.apply-async');
    // Multi-rule async (Apply Selected) — single heavy job, nested progress.
    Route::post('linkwise/autolink/apply-selected-async', [AutoLinkController::class, 'applySelectedAsync'])
        ->name('linkwise.autolink.apply-selected-async');
    Route::get('linkwise/autolink/apply-async/status', [AutoLinkController::class, 'applyAsyncStatus'])
        ->name('linkwise.autolink.apply-async.status');
    Route::post('linkwise/autolink/apply-async/cancel', [AutoLinkController::class, 'applyAsyncCancel'])
        ->name('linkwise.autolink.apply-async.cancel');

    // Target Keywords
    Route::post('linkwise/target-keywords/{entryId}', [TargetKeywordController::class, 'update'])
        ->name('linkwise.target-keywords.update');

    // Ignored Broken Links (user-marked false positives)
    Route::post('linkwise/ignored-links/ignore', [IgnoredLinkController::class, 'ignore'])
        ->name('linkwise.ignored-links.ignore');
    Route::post('linkwise/ignored-links/unignore', [IgnoredLinkController::class, 'unignore'])
        ->name('linkwise.ignored-links.unignore');

    // URL Changer
    Route::post('linkwise/url-changer/preview', [UrlChangerController::class, 'preview'])
        ->name('linkwise.url-changer.preview');
    Route::post('linkwise/url-changer/apply', [UrlChangerController::class, 'apply'])
        ->name('linkwise.url-changer.apply');
    // Async batch (heavy job — server-side, reload-resilient).
    Route::post('linkwise/url-changer/apply-async', [UrlChangerController::class, 'applyAsync'])
        ->name('linkwise.url-changer.apply-async');
    Route::get('linkwise/url-changer/apply-status', [UrlChangerController::class, 'applyStatus'])
        ->name('linkwise.url-changer.apply-status');
    Route::post('linkwise/url-changer/apply-cancel', [UrlChangerController::class, 'applyCancel'])
        ->name('linkwise.url-changer.apply-cancel');
});

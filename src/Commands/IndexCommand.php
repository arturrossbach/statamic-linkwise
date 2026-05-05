<?php

namespace Inkline\Linkwise\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Inkline\Linkwise\Indexer\EntryIndexer;
use Inkline\Linkwise\Suggestions\SuggestionEngine;
use Inkline\Linkwise\Support\JobLock;

class IndexCommand extends Command
{
    protected $signature = 'linkwise:index
                            {--suggest= : Show suggestions for this entry ID after indexing}
                            {--progress : Write progress updates to cache for UI polling}';

    protected $description = 'Rebuild the Linkwise entry index';

    public function __construct(
        protected EntryIndexer $indexer,
        protected SuggestionEngine $engine,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Building Linkwise index...');
        $reportProgress = (bool) $this->option('progress');

        if ($reportProgress) {
            // Detached background mode — protect against time-limit kills + crashes.
            @set_time_limit(0);
            JobLock::registerCrashGuard('linkwise:scan:status', 'linkwise:scan:cancel');

            Cache::put('linkwise:scan:status', ['phase' => 'starting', 'message' => 'Starting scan...'], 600);
            Cache::forget('linkwise:scan:cancel');
        }

        $start = microtime(true);

        try {
            // Phase 1: Index entries (fast)
            if ($reportProgress) {
                Cache::put('linkwise:scan:status', ['phase' => 'indexing', 'message' => 'Indexing entries...'], 600);
            }

            $records = $this->indexer->buildIndex(withSuggestions: false);
            $total = count($records);

            if ($reportProgress && Cache::get('linkwise:scan:cancel')) {
                throw new \RuntimeException('cancelled');
            }

            // Phase 2: Compute suggestions (slow)
            if ($reportProgress) {
                Cache::put('linkwise:scan:status', [
                    'phase' => 'suggestions',
                    'current' => 0,
                    'total' => $total,
                    'message' => 'Computing suggestions...',
                ], 600);
            }

            $records = $this->indexer->enrichWithSuggestionCountsStreamed(
                $records,
                function ($current, $total, $title) use ($reportProgress) {
                    if (! $reportProgress) {
                        return;
                    }
                    if (Cache::get('linkwise:scan:cancel')) {
                        throw new \RuntimeException('cancelled');
                    }
                    Cache::put('linkwise:scan:status', [
                        'phase' => 'suggestions',
                        'current' => $current,
                        'total' => $total,
                        'title' => mb_strlen($title) > 60 ? mb_substr($title, 0, 57).'...' : $title,
                    ], 600);
                },
            );

            // Phase 3: Save
            if ($reportProgress) {
                Cache::put('linkwise:scan:status', ['phase' => 'saving', 'message' => 'Saving index...'], 600);
            }
            $this->indexer->save($records);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'cancelled') {
                Cache::put('linkwise:scan:status', ['phase' => 'cancelled'], 60);
                $this->warn('Cancelled by user.');

                return self::SUCCESS;
            }
            throw $e;
        }

        $duration = microtime(true) - $start;

        $this->info(sprintf('Indexed %d entries in %.1fs.', count($records), $duration));

        $totalOutbound = 0;
        foreach ($records as $record) {
            $totalOutbound += count($record->outboundLinks);
        }

        $totalKeywords = 0;
        foreach ($records as $record) {
            $totalKeywords += count($record->keywords);
        }

        if ($reportProgress) {
            Cache::put('linkwise:scan:status', [
                'phase' => 'done',
                'entries_count' => count($records),
                'total_outbound' => $totalOutbound,
                'duration' => round($duration, 1),
            ], 300);
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Entries indexed', count($records)],
                ['Total outbound links', $totalOutbound],
                ['TF-IDF keywords extracted', $totalKeywords],
                ['Index path', storage_path('linkwise/index.json')],
            ],
        );

        $suggestEntryId = $this->option('suggest');

        if ($suggestEntryId && isset($records[$suggestEntryId])) {
            $this->newLine();
            $this->showSuggestions($records[$suggestEntryId], $records);
        }

        return self::SUCCESS;
    }

    protected function showSuggestions($record, array $index): void
    {
        $this->info(sprintf('Suggestions for: %s', $record->title));

        $suggestions = $this->engine->suggest($record->text, $index, $record->id);

        if (empty($suggestions)) {
            $this->warn('No suggestions found.');

            return;
        }

        $rows = [];

        foreach ($suggestions as $suggestion) {
            $rows[] = [
                $suggestion->anchorText,
                $suggestion->targetTitle,
                $suggestion->targetUrl,
                $suggestion->score,
            ];
        }

        $this->table(
            ['Anchor Text', 'Target Entry', 'URL', 'Score'],
            $rows,
        );
    }
}

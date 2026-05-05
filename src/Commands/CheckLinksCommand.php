<?php

namespace Inkline\Linkwise\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Inkline\Linkwise\Links\BrokenLinkChecker;
use Inkline\Linkwise\Links\BrokenLinkReport;
use Inkline\Linkwise\Support\JobLock;

class CheckLinksCommand extends Command
{
    protected $signature = 'linkwise:check-links {--progress : Write progress updates to cache for UI polling}';

    protected $description = 'Check all links for broken URLs';

    public function __construct(
        protected BrokenLinkChecker $checker,
        protected BrokenLinkReport $report,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Checking links...');
        $reportProgress = (bool) $this->option('progress');

        if ($reportProgress) {
            // Detached background mode — protect against time-limit kills + crashes.
            @set_time_limit(0);
            JobLock::registerCrashGuard('linkwise:check:status', 'linkwise:check:cancel');

            Cache::put('linkwise:check:status', ['phase' => 'starting', 'current' => 0, 'total' => 0], 600);
            Cache::forget('linkwise:check:cancel');
        }

        $start = microtime(true);

        try {
            $brokenLinks = $this->checker->checkAll(function ($current, $total, $url) use ($reportProgress) {
                if (! $reportProgress) {
                    return;
                }
                if (Cache::get('linkwise:check:cancel')) {
                    throw new \RuntimeException('cancelled');
                }
                Cache::put('linkwise:check:status', [
                    'phase' => 'checking',
                    'current' => $current,
                    'total' => $total,
                    'url' => mb_strlen($url) > 60 ? mb_substr($url, 0, 57).'...' : $url,
                ], 600);
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'cancelled') {
                Cache::put('linkwise:check:status', ['phase' => 'cancelled'], 60);
                $this->warn('Cancelled by user.');

                return self::SUCCESS;
            }
            throw $e;
        }

        $duration = microtime(true) - $start;

        $this->report->save($brokenLinks, $duration);

        if ($reportProgress) {
            Cache::put('linkwise:check:status', [
                'phase' => 'done',
                'broken_count' => count($brokenLinks),
                'duration' => round($duration, 1),
            ], 300);
        }

        if (empty($brokenLinks)) {
            $this->info('No broken links found.');
        } else {
            $this->warn(sprintf('Found %d broken link(s):', count($brokenLinks)));

            $rows = [];
            foreach ($brokenLinks as $record) {
                $rows[] = [
                    $record->postTitle,
                    mb_strlen($record->url) > 50 ? mb_substr($record->url, 0, 47).'...' : $record->url,
                    $record->type,
                    $record->statusLabel(),
                ];
            }

            $this->table(['Entry', 'URL', 'Type', 'Status'], $rows);
        }

        $this->info(sprintf('Done in %.1f seconds.', $duration));

        return self::SUCCESS;
    }
}

<?php

namespace Arturrossbach\Linkwise\Links;

class BrokenLinkReport
{
    protected string $storagePath;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? storage_path('linkwise');
    }

    /**
     * Save broken link check results to disk.
     *
     * @param  BrokenLinkRecord[]  $brokenLinks
     */
    public function save(array $brokenLinks, float $duration = 0): void
    {
        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        $data = [
            'metadata' => [
                'last_checked' => now()->toIso8601String(),
                'duration_seconds' => round($duration, 1),
                'broken_count' => count($brokenLinks),
            ],
            'broken_links' => array_map(fn (BrokenLinkRecord $r) => $r->toArray(), $brokenLinks),
        ];

        file_put_contents(
            $this->getPath(),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Load broken link report from disk.
     *
     * @return array{metadata: array|null, broken_links: BrokenLinkRecord[]}
     */
    public function load(): array
    {
        // Lazy one-time migration from pre-consolidated format (separate ignored-links.json)
        $this->migrateLegacyIgnoredFileIfExists();

        $path = $this->getPath();

        if (! file_exists($path)) {
            return [
                'metadata' => null,
                'broken_links' => [],
            ];
        }

        $data = json_decode(file_get_contents($path), true);

        if (! is_array($data)) {
            return [
                'metadata' => null,
                'broken_links' => [],
            ];
        }

        $records = [];
        $rawRecords = $data['broken_links'] ?? [];
        if (! is_array($rawRecords)) {
            $rawRecords = [];
        }
        // Skip-on-invalid: one corrupt record can't break the Broken Links tab.
        foreach ($rawRecords as $item) {
            if (! is_array($item)) {
                continue;
            }
            try {
                $records[] = BrokenLinkRecord::fromArray($item);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    '[Linkwise] BrokenLinkReport: skipping corrupt record — '.$e->getMessage(),
                );
            }
        }

        return [
            'metadata' => $data['metadata'] ?? null,
            'broken_links' => $records,
        ];
    }

    /**
     * Append a record to the persisted report without rewriting metadata.
     * Used when restoring a previously-ignored link — the original scan's
     * last_checked timestamp should stay intact.
     */
    public function addRecord(BrokenLinkRecord $record): void
    {
        $path = $this->getPath();

        if (! file_exists($path)) {
            $this->save([$record]);

            return;
        }

        $data = json_decode(file_get_contents($path), true);
        if (! is_array($data)) {
            $this->save([$record]);

            return;
        }

        $data['broken_links'][] = $record->toArray();
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata']['broken_count'] = count($data['broken_links']);
        }

        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Remove one occurrence of a broken link from the persisted report.
     */
    public function removeLink(string $postId, string $url): void
    {
        $report = $this->load();
        $removed = false;

        $filtered = [];
        foreach ($report['broken_links'] as $record) {
            if (! $removed && $record->postId === $postId && $record->url === $url) {
                $removed = true;

                continue; // Skip this one — only the first match
            }
            $filtered[] = $record;
        }

        if ($removed) {
            $this->save($filtered, $report['metadata']['duration_seconds'] ?? 0);
        }
    }

    /**
     * Toggle the `ignored` flag on the matching record.
     * Returns true if a record was found and updated, false otherwise.
     */
    public function setIgnored(string $postId, string $url, bool $ignored): bool
    {
        $report = $this->load();
        $found = false;
        $records = [];
        foreach ($report['broken_links'] as $r) {
            if (! $found && $r->postId === $postId && $r->url === $url) {
                $records[] = $r->withIgnored($ignored);
                $found = true;
            } else {
                $records[] = $r;
            }
        }

        if ($found) {
            $this->persistPreservingMetadata($records, $report['metadata']);
        }

        return $found;
    }

    /**
     * Convert loaded report to frontend-ready array. No merge any more — ignored
     * records live inline in `broken_links` with an `ignored: true` flag.
     */
    public function toArray(): array
    {
        $report = $this->load();

        return [
            'metadata' => $report['metadata'],
            'broken_links' => array_map(fn (BrokenLinkRecord $r) => array_merge(
                $r->toArray(),
                ['status_label' => $r->statusLabel()],
            ), $report['broken_links']),
        ];
    }

    protected function getPath(): string
    {
        return $this->storagePath.'/broken-links.json';
    }

    /**
     * Write records back while preserving the existing metadata (last_checked etc).
     * broken_count is updated to reflect the new record count.
     *
     * @param  BrokenLinkRecord[]  $records
     */
    protected function persistPreservingMetadata(array $records, ?array $metadata): void
    {
        $data = [
            'metadata' => is_array($metadata) ? array_merge($metadata, ['broken_count' => count($records)]) : null,
            'broken_links' => array_map(fn (BrokenLinkRecord $r) => $r->toArray(), $records),
        ];

        file_put_contents(
            $this->getPath(),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * One-time migration: if a legacy `ignored-links.json` exists (pre-consolidation),
     * merge its records into `broken-links.json` with `ignored: true` and delete the file.
     */
    protected function migrateLegacyIgnoredFileIfExists(): void
    {
        $ignoredPath = $this->storagePath.'/ignored-links.json';
        if (! file_exists($ignoredPath)) {
            return;
        }

        $ignoredData = json_decode(file_get_contents($ignoredPath), true);
        if (! is_array($ignoredData) || empty($ignoredData)) {
            @unlink($ignoredPath);

            return;
        }

        $brokenPath = $this->getPath();
        if (file_exists($brokenPath)) {
            $brokenData = json_decode(file_get_contents($brokenPath), true);
            if (! is_array($brokenData)) {
                $brokenData = ['metadata' => null, 'broken_links' => []];
            }
        } else {
            $brokenData = ['metadata' => null, 'broken_links' => []];
        }

        foreach ($ignoredData as $r) {
            if (! is_array($r) || empty($r['post_id']) || empty($r['url'])) {
                continue;
            }
            // Skip if already present in broken_links with same (post_id, url)
            $dup = false;
            foreach ($brokenData['broken_links'] as $existing) {
                if (($existing['post_id'] ?? null) === $r['post_id']
                    && ($existing['url'] ?? null) === $r['url']) {
                    $dup = true;
                    break;
                }
            }
            if ($dup) {
                continue;
            }

            $brokenData['broken_links'][] = [
                'post_id' => $r['post_id'],
                'post_title' => $r['post_title'] ?? '',
                'url' => $r['url'],
                'anchor_text' => $r['anchor_text'] ?? '',
                'type' => $r['type'] ?? 'external',
                'status_code' => $r['status_code'] ?? null,
                'error_type' => $r['error_type'] ?? 'unknown',
                'first_detected_at' => $r['first_detected_at'] ?? now()->toIso8601String(),
                'last_checked_at' => $r['last_checked_at'] ?? now()->toIso8601String(),
                'sentence_context' => $r['sentence_context'] ?? '',
                'ignored' => true,
            ];
        }

        if (isset($brokenData['metadata']) && is_array($brokenData['metadata'])) {
            $brokenData['metadata']['broken_count'] = count($brokenData['broken_links']);
        }

        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
        file_put_contents(
            $brokenPath,
            json_encode($brokenData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
        @unlink($ignoredPath);
    }
}

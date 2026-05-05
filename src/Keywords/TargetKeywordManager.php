<?php

namespace Inkline\Linkwise\Keywords;

class TargetKeywordManager
{
    protected string $storagePath;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? storage_path('linkwise');
    }

    /**
     * Get custom keywords for a specific entry.
     *
     * @return string[]
     */
    public function getKeywords(string $entryId): array
    {
        $all = $this->loadAll();

        return $all[$entryId] ?? [];
    }

    /**
     * Atomic single-key update with file-lock so two simultaneous saves from
     * different browser tabs / editors don't clobber each other. Same pattern
     * as DomainReport::setAttribute — stake out a flock(LOCK_EX), read+modify+
     * write, release.
     *
     * Without this, two users editing different entries' keywords concurrently
     * could each load the same baseline JSON, overwrite the other's change.
     *
     * @param  string[]  $keywords
     */
    public function setKeywords(string $entryId, array $keywords): void
    {
        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        $cleaned = array_values(array_filter(array_map('trim', $keywords)));
        $path = $this->getPath();

        $fp = fopen($path, 'c+');
        if ($fp === false) {
            return;
        }

        try {
            if (! flock($fp, LOCK_EX)) {
                return;
            }

            rewind($fp);
            $contents = stream_get_contents($fp);
            $data = $contents ? json_decode($contents, true) : [];
            if (! is_array($data)) {
                $data = [];
            }

            if (empty($cleaned)) {
                unset($data[$entryId]);
            } else {
                $data[$entryId] = $cleaned;
            }

            // Truncate before writing — JSON output may be shorter than the
            // existing file on disk after deletion.
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }

    /**
     * Get all custom keywords for all entries.
     *
     * @return array<string, string[]>
     */
    public function loadAll(): array
    {
        $path = $this->getPath();

        if (! file_exists($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    protected function getPath(): string
    {
        return $this->storagePath.'/target-keywords.json';
    }
}

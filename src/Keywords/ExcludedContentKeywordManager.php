<?php

namespace Arturrossbach\Linkwise\Keywords;

/**
 * Per-entry block-list of auto-extracted "Content Keywords" that the
 * user wants excluded from the Target Keywords tab's content-keyword
 * display.
 *
 * Why a block-list (User-Smoke 2026-05-21): the TF-IDF extraction
 * sometimes surfaces generic / noisy stems (e.g. "Mehr", "Tahini" on
 * an unrelated entry) that the user doesn't want as ranking signals
 * for link suggestions. Without a persisted exclude list, every
 * re-index would resurface them — manual removal would be Sisyphus
 * work.
 *
 * Storage parity with {@see TargetKeywordManager}: same atomic
 * flock-based JSON file pattern, same `[entryId => string[]]` shape.
 * Excluded keywords are stored as the ORIGINAL word (not the stem) so
 * the user sees what they entered if the file is inspected.
 */
class ExcludedContentKeywordManager
{
    protected string $storagePath;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? storage_path('linkwise');
    }

    /**
     * Excluded keywords for one entry. Lowercased + trimmed for
     * case-insensitive matching against the auto-extracted list.
     *
     * @return string[]
     */
    public function getExcluded(string $entryId): array
    {
        $all = $this->loadAll();

        return $all[$entryId] ?? [];
    }

    /**
     * Replace the full excluded list for an entry atomically.
     * Empty array deletes the entry's row (keeps the JSON small).
     *
     * @param  string[]  $keywords  full list, will be lowercased + deduped
     */
    public function setExcluded(string $entryId, array $keywords): void
    {
        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        $cleaned = array_values(array_unique(array_filter(array_map(
            fn ($k) => is_string($k) ? mb_strtolower(trim($k)) : null,
            $keywords,
        ))));
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
     * @return array<string, string[]>
     */
    public function loadAll(): array
    {
        $data = \Arturrossbach\Linkwise\Support\JsonFileStore::load(
            $this->getPath(),
            [],
            'ExcludedContentKeywordManager::loadAll',
        );

        return is_array($data) ? $data : [];
    }

    protected function getPath(): string
    {
        return $this->storagePath.'/excluded-content-keywords.json';
    }
}

<?php

namespace Arturrossbach\Linkwise\Reports;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Support\ContextExtractor;
use Arturrossbach\Linkwise\Support\EntryFieldWalker;
use Arturrossbach\Linkwise\Support\TextExtractor;
use Arturrossbach\Linkwise\Support\UrlHelper;
use Statamic\Facades\Entry;

class DomainReport
{
    protected string $storagePath;

    public function __construct(
        protected EntryIndexer $indexer,
        ?string $storagePath = null,
    ) {
        $this->storagePath = $storagePath ?? storage_path('linkwise');
    }

    /**
     * Scan all entries and build a domain report.
     *
     * @return array<string, array{domain: string, posts: array, links: array, link_count: int, post_count: int}>
     */
    public function scan(): array
    {
        $records = $this->indexer->load();
        $domains = [];

        // 2026-05-22: excluded_entries / excluded_collections used to be
        // re-applied here on top of the Indexer's own filter. User-bug
        // (Cloudways smoke): putting `Home` in excluded_entries hid the
        // entry from the Domains panel even though the blueprint copy
        // explicitly promised "neither suggested nor suggesting" — i.e.
        // Suggestion-scope only. Domains is a real-link report, not a
        // suggestion path, so the filter never belonged here.
        foreach ($records as $record) {
            $entry = Entry::find($record->id);

            if (! $entry) {
                continue;
            }

            // Walk once: offset-annotated external links + flat text in the
            // SAME pass. Replaces the naive occurrence counter that picked
            // the wrong position when the same anchor word appeared both
            // linked and unlinked in the entry (Bug 2026-05-11).
            $bundle = TextExtractor::extractFromEntry($entry);

            foreach ($bundle['external_links'] as $link) {
                $domain = $this->extractDomain($link['url']);

                if (! $domain) {
                    continue;
                }

                if (! isset($domains[$domain])) {
                    $domains[$domain] = [
                        'domain' => $domain,
                        'posts' => [],
                        'links' => [],
                        'link_count' => 0,
                        'post_count' => 0,
                    ];
                }

                // Display-only context — relax paragraph-clamp so very
                // short paragraphs (e.g. "mit einem gekühlten Weißwein.")
                // don't strangle the context to just the anchor.
                // User-Smoke 2026-05-21. ContextExtractor docblock has
                // the full rationale.
                $ctx = ContextExtractor::extractAtOffset(
                    $bundle['text'],
                    $link['offset'],
                    mb_strlen($link['anchor_text']),
                    240,
                    clampToParagraph: false,
                );

                $domains[$domain]['links'][] = [
                    'url' => $link['url'],
                    'anchor_text' => $link['anchor_text'],
                    'sentence_context' => $ctx['text'] ?? '',
                    'post_id' => $record->id,
                    'post_title' => $record->title,
                ];

                $domains[$domain]['link_count']++;

                if (! isset($domains[$domain]['posts'][$record->id])) {
                    $domains[$domain]['posts'][$record->id] = [
                        'id' => $record->id,
                        'title' => $record->title,
                    ];
                    $domains[$domain]['post_count']++;
                }
            }
        }

        // Convert posts from associative to indexed array
        foreach ($domains as &$domain) {
            $domain['posts'] = array_values($domain['posts']);
        }

        // Sort by link count descending
        uasort($domains, fn ($a, $b) => $b['link_count'] <=> $a['link_count']);

        return $domains;
    }

    /**
     * Load saved domain attributes (nofollow, dofollow, etc.).
     *
     * @return array<string, string>  Map of domain → attribute
     */
    public function loadAttributes(): array
    {
        $data = \Arturrossbach\Linkwise\Support\JsonFileStore::load(
            $this->storagePath.'/domain-attributes.json',
            [],
            'DomainReport::loadAttributes',
        );

        return is_array($data) ? $data : [];
    }

    /**
     * Save domain attributes (full overwrite). Used internally and for
     * migrations/imports. For single-key updates from the UI, prefer
     * setAttribute() which is concurrent-safe.
     *
     * @param  array<string, string>  $attributes  Map of domain → attribute
     */
    public function saveAttributes(array $attributes): void
    {
        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        // Atomic write via shared helper — direct file_put_contents
        // could leave domain-attributes.json truncated on kill -9 mid-
        // write, after which LinkwiseLinkMark would log "not valid JSON"
        // AND every external link on the public site would lose its rel
        // attribute until the file is restored. Low-frequency call
        // (only full-overwrite migrations / imports), uses the same
        // helper as EntryIndexer::save. setAttribute() below uses
        // flock+ftruncate instead because it does in-place mutation
        // under exclusive lock for per-keystroke updates.
        \Arturrossbach\Linkwise\Support\AtomicJsonWriter::write(
            $this->storagePath.'/domain-attributes.json',
            $attributes,
            'DomainReport::saveAttributes',
        );
    }

    /**
     * Atomic single-key update with file-lock so two simultaneous saves from
     * different browser tabs don't clobber each other. The CP UI calls this
     * when the user picks a different attribute from the inline dropdown.
     *
     * Passing 'default' (or null) deletes the entry — the implicit default
     * is "no rel attribute set", so storing that is wasteful.
     */
    public function setAttribute(string $domain, ?string $attribute): void
    {
        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        $path = $this->storagePath.'/domain-attributes.json';

        // c+ creates if missing; LOCK_EX serializes concurrent writers.
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

            if ($attribute === null || $attribute === 'default') {
                unset($data[$domain]);
            } else {
                $data[$domain] = $attribute;
            }

            // Truncate before writing — the new payload is usually shorter.
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
     * Build frontend-ready array combining scan results + saved attributes.
     */
    public function toArray(): array
    {
        $domains = $this->scan();
        $attributes = $this->loadAttributes();

        $result = [];

        foreach ($domains as $domain => $data) {
            $result[] = [
                'domain' => $domain,
                'attribute' => $attributes[$domain] ?? 'default',
                'post_count' => $data['post_count'],
                'link_count' => $data['link_count'],
                'posts' => $data['posts'],
                'links' => $data['links'],
            ];
        }

        return $result;
    }

    protected function extractDomain(string $url): ?string
    {
        return UrlHelper::extractDomain($url);
    }

    /**
     * Extract external links from a Statamic entry.
     *
     * @return array<array{url: string, anchor_text: string}>
     */
    /**
     * Extract external links from an entry, using the SAME walker that
     * BrokenLinkChecker and DashboardController::extractExternalLinksFromEntry
     * use. Single source of truth — the link counts shown in Domains, Links
     * Report (`external_count`), and Broken Links cannot drift.
     *
     * @return array<array{url: string, anchor_text: string}>
     */
    protected function extractExternalLinksFromEntry($entry): array
    {
        $links = [];

        EntryFieldWalker::walk(
            $entry,
            function (array $bard) use (&$links) {
                $links = array_merge($links, TextExtractor::externalLinksFromBard($bard));
            },
            function (string $markdown) use (&$links) {
                $links = array_merge($links, TextExtractor::externalLinksFromMarkdown($markdown));
            },
        );

        return $links;
    }
}

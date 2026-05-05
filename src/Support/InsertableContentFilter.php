<?php

namespace Inkline\Linkwise\Support;

/**
 * Single source of truth for "is this string a piece of user content vs
 * a config/asset/metadata token". Three walkers consult this filter:
 *
 *   - EntryFieldWalker::walkReplicator   (read side: indexing + extraction)
 *   - BardLinkInserter::processReplicator(WithHref) + top-level text/textarea
 *     (write side: link insertion)
 *   - TextExtractor::extractTextFromNode  (Bard custom-set walker)
 *
 * Keeping the filter symmetric prevents two failure modes:
 *
 *   1. Walker indexes a URL/path string as content -> suggestion engine
 *      surfaces an anchor pointing at it -> inserter rejects (asymmetry)
 *      or accepts and corrupts the URL field by wrapping it in markdown.
 *   2. Walker filters something the inserter still treats as content,
 *      so AutoLink/URL-Changer rules silently mangle URL fields the
 *      indexer never read in the first place.
 */
class InsertableContentFilter
{
    /**
     * Field/key handles whose values carry asset references (filenames,
     * paths, URLs) rather than user content. When the calling walker
     * knows the key, this lets us reject the field shape before content
     * checks run -- catches well-formed values like '/cover.jpg' or
     * 'https://example.com/page' that look like content otherwise.
     */
    public const ASSET_HANDLES = [
        'image', 'images', 'assets', 'file', 'files', 'media',
        'video', 'audio', 'cover', 'thumbnail', 'icon', 'asset',
        'src', 'source', 'url', 'href', 'link',
    ];

    /**
     * Resource-filename pattern. Catches '/photo.jpg' nested under a
     * non-asset key (e.g. 'thumbnail_path'), where the asset-handle
     * blacklist alone would miss it.
     */
    public const FILENAME_PATTERN = '/\.(jpe?g|png|gif|svg|webp|avif|pdf|mp4|mp3|webm|mov|zip|css|js|html?)$/i';

    /**
     * Whether $value is a piece of user content worth indexing / linking.
     *
     * @param  string|null  $key  Field handle or array key when known.
     *                            Lets us reject 'image: /photo.jpg' on
     *                            the handle alone, before the value-
     *                            shape checks run.
     */
    public static function isContent(string $value, ?string $key = null): bool
    {
        if ($key !== null && in_array($key, self::ASSET_HANDLES, true)) {
            return false;
        }

        $trimmed = trim($value);

        if (mb_strlen($trimmed) < 5) {
            return false;
        }
        if (is_numeric($trimmed)) {
            return false;
        }
        if (in_array(mb_strtolower($trimmed), ['true', 'false', 'yes', 'no', 'null'], true)) {
            return false;
        }
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $trimmed)) {
            return false;
        }
        if (preg_match(self::FILENAME_PATTERN, $trimmed)) {
            return false;
        }

        return true;
    }
}

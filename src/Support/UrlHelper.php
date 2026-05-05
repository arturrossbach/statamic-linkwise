<?php

namespace Arturrossbach\Linkwise\Support;

class UrlHelper
{
    /** Keys to skip when traversing Replicator sets. */
    public const REPLICATOR_META_KEYS = ['type', 'id', 'enabled'];

    /** Sentinel value for unlinking (removing link mark, keeping text). */
    public const UNLINK = '__LINKWISE_UNLINK__';

    /**
     * Extract the domain from a URL, stripping www. prefix.
     */
    public static function extractDomain(string $url): ?string
    {
        $parseable = $url;
        if (! preg_match('#^[a-z][a-z0-9+\-.]*://#i', $url)) {
            $parseable = 'https://'.$url;
        }

        $host = parse_url($parseable, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        return preg_replace('/^www\./', '', mb_strtolower($host));
    }
}

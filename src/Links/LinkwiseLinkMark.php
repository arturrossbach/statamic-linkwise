<?php

namespace Arturrossbach\Linkwise\Links;

use Arturrossbach\Linkwise\Reports\DomainReport;
use Statamic\Fieldtypes\Bard\LinkMark;

class LinkwiseLinkMark extends LinkMark
{
    protected static ?array $domainAttributes = null;

    public function renderHTML($mark, $HTMLAttributes = []): array|string
    {
        $result = parent::renderHTML($mark, $HTMLAttributes);

        // Domain-rel application MUST NEVER crash the public page. Any throw
        // here would crash a Bard-rendered article on the live site (Linkwise
        // replaces Bard's LinkMark via Augmentor::replaceExtension at boot).
        // We wrap ONLY our additive logic — parent's render is left as-is so
        // a Statamic-internal failure remains visible to its own error handlers.
        try {
            // Parent's renderHTML unsets attributes with null values from the stored content.
            // We re-apply our domain-based rel attribute AFTER that cleanup.
            if (is_array($result) && isset($result[1]) && is_array($result[1])) {
                $href = $result[1]['href'] ?? '';
                $domainRel = $this->getDomainRelAttribute($href);

                if ($domainRel) {
                    $existingRel = $result[1]['rel'] ?? '';
                    $result[1]['rel'] = trim($existingRel ? $existingRel.' '.$domainRel : $domainRel);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                '[Linkwise] LinkwiseLinkMark domain-rel skipped — '.$e->getMessage(),
            );
        }

        return $result;
    }

    protected function getDomainRelAttribute(string $href): ?string
    {
        // Only apply to external HTTP links
        if (! preg_match('#^https?://#i', $href)) {
            return null;
        }

        $host = parse_url($href, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        $domain = preg_replace('/^www\./', '', mb_strtolower($host));
        $attributes = static::loadDomainAttributes();
        $attribute = $attributes[$domain] ?? 'default';

        return match ($attribute) {
            'nofollow' => 'nofollow',
            'sponsored' => 'nofollow sponsored',
            'ugc' => 'nofollow ugc',
            default => null, // 'default' and 'dofollow' = no rel override
        };
    }

    protected static function loadDomainAttributes(): array
    {
        if (static::$domainAttributes !== null) {
            return static::$domainAttributes;
        }

        $path = storage_path('linkwise/domain-attributes.json');

        if (! file_exists($path)) {
            static::$domainAttributes = [];

            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            // Race: file disappeared between file_exists and read. Fall
            // back to empty, log so the user can investigate disk perms.
            \Illuminate\Support\Facades\Log::warning(
                '[Linkwise] LinkwiseLinkMark: could not read domain-attributes.json — domain-rel attributes (nofollow/sponsored/ugc) will not apply on the public site.',
            );
            static::$domainAttributes = [];
            return [];
        }
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            // Malformed JSON. Without this warning the public site silently
            // renders without nofollow/sponsored/ugc rel attributes — SEO
            // damage user only notices weeks later in Search Console. Cache
            // the empty result so we don't re-parse the broken file every
            // page render, but make the failure findable in logs.
            \Illuminate\Support\Facades\Log::warning(
                '[Linkwise] LinkwiseLinkMark: domain-attributes.json is not valid JSON — domain-rel attributes will not apply until the file is repaired or recreated via the CP.',
            );
            static::$domainAttributes = [];
            return [];
        }
        static::$domainAttributes = $data;

        return static::$domainAttributes;
    }

    /**
     * Clear the cached domain attributes (e.g. after saving new attributes).
     */
    public static function clearCache(): void
    {
        static::$domainAttributes = null;
    }
}

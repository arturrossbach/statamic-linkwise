<?php

namespace Arturrossbach\Linkwise\AutoLink;

use Illuminate\Support\Str;

class AutoLinkRule
{
    public function __construct(
        public readonly string $id,
        public readonly string $keyword,
        public readonly string $url,
        public readonly ?string $targetEntryId,
        public readonly bool $oncePerPost = true,
        public readonly bool $skipIfExists = false,
        public readonly bool $caseSensitive = false,
        public readonly array $collections = [],
        public readonly bool $active = true,
        public readonly string $createdAt = '',
        // Last successful apply — null means "never applied". Used by the
        // Rules table to show staleness ("Last applied: 3 days ago") and to
        // prevent the user from blind-re-applying a rule that just ran.
        public readonly ?string $lastAppliedAt = null,
        public readonly int $lastAppliedLinksAdded = 0,
        // Tri-state — controls how this rule behaves on entry save:
        //   'follow_global' (default): fires iff the global toggle is on
        //   'always':                  fires regardless of the global toggle
        //   'never':                   never fires on save (manual only)
        //
        // The 'always' state is the answer to "global is off but I want THIS
        // one rule to keep auto-applying". 'never' is the answer to "global
        // is on but THIS rule is experimental, leave it manual". 'follow_global'
        // is the 95% case — most rules just defer to the master switch.
        public readonly string $autoApplyOnSave = 'follow_global',
    ) {}

    public static function create(array $data): self
    {
        $url = $data['url'] ?? '';
        $targetEntryId = null;

        // Detect if URL is a Statamic entry reference
        if (preg_match('#^statamic://entry::(.+)$#', $url, $matches)) {
            $targetEntryId = $matches[1];
        }

        return new self(
            id: $data['id'] ?? Str::uuid()->toString(),
            keyword: $data['keyword'],
            url: $url,
            targetEntryId: $targetEntryId,
            oncePerPost: $data['once_per_post'] ?? true,
            skipIfExists: $data['skip_if_exists'] ?? false,
            caseSensitive: $data['case_sensitive'] ?? false,
            collections: $data['collections'] ?? [],
            active: $data['active'] ?? true,
            createdAt: $data['created_at'] ?? now()->toIso8601String(),
            lastAppliedAt: $data['last_applied_at'] ?? null,
            lastAppliedLinksAdded: (int) ($data['last_applied_links_added'] ?? 0),
            autoApplyOnSave: self::normalizeAutoApply($data['auto_apply_on_save'] ?? null),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'keyword' => $this->keyword,
            'url' => $this->url,
            'target_entry_id' => $this->targetEntryId,
            'once_per_post' => $this->oncePerPost,
            'skip_if_exists' => $this->skipIfExists,
            'case_sensitive' => $this->caseSensitive,
            'collections' => $this->collections,
            'active' => $this->active,
            'created_at' => $this->createdAt,
            'last_applied_at' => $this->lastAppliedAt,
            'last_applied_links_added' => $this->lastAppliedLinksAdded,
            'auto_apply_on_save' => $this->autoApplyOnSave,
        ];
    }

    /**
     * @throws \InvalidArgumentException when required fields are missing.
     *   Loaders MUST catch + skip — one corrupt rule can't break the tab.
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['id']) || ! is_string($data['id'])) {
            throw new \InvalidArgumentException('AutoLinkRule: missing required field "id"');
        }
        if (! isset($data['keyword']) || ! is_string($data['keyword'])) {
            throw new \InvalidArgumentException('AutoLinkRule: missing required field "keyword"');
        }
        if (! isset($data['url']) || ! is_string($data['url'])) {
            throw new \InvalidArgumentException('AutoLinkRule: missing required field "url"');
        }

        return new self(
            id: $data['id'],
            keyword: $data['keyword'],
            url: $data['url'],
            targetEntryId: $data['target_entry_id'] ?? null,
            oncePerPost: $data['once_per_post'] ?? true,
            skipIfExists: $data['skip_if_exists'] ?? false,
            caseSensitive: $data['case_sensitive'] ?? false,
            collections: $data['collections'] ?? [],
            active: $data['active'] ?? true,
            createdAt: $data['created_at'] ?? '',
            lastAppliedAt: $data['last_applied_at'] ?? null,
            lastAppliedLinksAdded: (int) ($data['last_applied_links_added'] ?? 0),
            autoApplyOnSave: self::normalizeAutoApply($data['auto_apply_on_save'] ?? null),
        );
    }

    public function isExternal(): bool
    {
        return $this->targetEntryId === null;
    }

    /**
     * Coerce legacy/incoming values into the tristate. Old rules stored
     * `auto_apply_on_save` as a bool — true → 'follow_global', false → 'never'.
     * Unknown / missing → 'follow_global' (the safe default).
     */
    public static function normalizeAutoApply(mixed $value): string
    {
        if ($value === true) {
            return 'follow_global';
        }
        if ($value === false) {
            return 'never';
        }
        if (in_array($value, ['follow_global', 'always', 'never'], true)) {
            return $value;
        }

        return 'follow_global';
    }
}

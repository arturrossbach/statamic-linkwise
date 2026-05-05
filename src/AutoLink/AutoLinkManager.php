<?php

namespace Arturrossbach\Linkwise\AutoLink;

class AutoLinkManager
{
    protected string $storagePath;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? storage_path('linkwise');
    }

    /**
     * @return AutoLinkRule[]
     */
    public function loadRules(): array
    {
        $path = $this->getPath();

        if (! file_exists($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);

        if (! is_array($data)) {
            return [];
        }

        $rules = [];
        foreach ($data as $item) {
            $rules[$item['id']] = AutoLinkRule::fromArray($item);
        }

        return $rules;
    }

    /**
     * @param  AutoLinkRule[]  $rules
     */
    public function saveRules(array $rules): void
    {
        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        $data = array_values(array_map(fn (AutoLinkRule $r) => $r->toArray(), $rules));

        file_put_contents(
            $this->getPath(),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    public function createRule(array $data): AutoLinkRule
    {
        $rules = $this->loadRules();
        $rule = AutoLinkRule::create($data);
        $rules[$rule->id] = $rule;
        $this->saveRules($rules);

        return $rule;
    }

    public function updateRule(string $id, array $data): ?AutoLinkRule
    {
        $rules = $this->loadRules();

        if (! isset($rules[$id])) {
            return null;
        }

        $existing = $rules[$id];
        $updated = AutoLinkRule::create(array_merge($existing->toArray(), $data, ['id' => $id]));
        $rules[$id] = $updated;
        $this->saveRules($rules);

        return $updated;
    }

    public function deleteRule(string $id): bool
    {
        $rules = $this->loadRules();

        if (! isset($rules[$id])) {
            return false;
        }

        unset($rules[$id]);
        $this->saveRules($rules);

        return true;
    }

    /**
     * Bulk delete. Returns the number of rules actually removed
     * (silently ignores ids that are not present).
     *
     * @param  string[]  $ids
     */
    public function deleteRules(array $ids): int
    {
        $rules = $this->loadRules();
        $removed = 0;
        foreach ($ids as $id) {
            if (isset($rules[$id])) {
                unset($rules[$id]);
                $removed++;
            }
        }
        if ($removed > 0) {
            $this->saveRules($rules);
        }

        return $removed;
    }

    /**
     * Bulk activate/deactivate. Returns the number of rules whose state
     * actually changed (already-correct rules are silently skipped, missing
     * ids too).
     *
     * @param  string[]  $ids
     */
    public function setRulesActive(array $ids, bool $active): int
    {
        $rules = $this->loadRules();
        $changed = 0;
        foreach ($ids as $id) {
            if (! isset($rules[$id])) {
                continue;
            }
            if ($rules[$id]->active === $active) {
                continue;
            }
            $rules[$id] = AutoLinkRule::create(array_merge(
                $rules[$id]->toArray(),
                ['active' => $active, 'id' => $id],
            ));
            $changed++;
        }
        if ($changed > 0) {
            $this->saveRules($rules);
        }

        return $changed;
    }

    /**
     * Pure helper — checks whether a (keyword, caseSensitive) pair would
     * collide with any rule in the given set.
     *
     * Truth table:
     *   - Both rules case-sensitive  → exact-character match counts as duplicate.
     *     (e.g. "Hund" + "HUND" with cs=true coexist as distinct rules.)
     *   - Otherwise → case-insensitive match counts as duplicate, since at
     *     least one of the rules already covers all casings of the keyword.
     *
     * Returns the conflicting rule, or null if no collision.
     *
     * @param  AutoLinkRule[]  $existing
     */
    public static function findDuplicate(array $existing, string $keyword, bool $caseSensitive): ?AutoLinkRule
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return null;
        }

        foreach ($existing as $rule) {
            $bothCs = $caseSensitive && $rule->caseSensitive;
            $isDuplicate = $bothCs
                ? $rule->keyword === $keyword
                : mb_strtolower($rule->keyword) === mb_strtolower($keyword);

            if ($isDuplicate) {
                return $rule;
            }
        }

        return null;
    }

    public function getRule(string $id): ?AutoLinkRule
    {
        $rules = $this->loadRules();

        return $rules[$id] ?? null;
    }

    protected function getPath(): string
    {
        return $this->storagePath.'/autolink-rules.json';
    }
}

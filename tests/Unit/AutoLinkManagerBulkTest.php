<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\AutoLink\AutoLinkManager;
use Arturrossbach\Linkwise\Tests\TestCase;

/**
 * Bulk operations on rules: deleteRules + setRulesActive.
 *
 * Both must:
 *   - silently skip ids that don't exist (no exception, no count)
 *   - return the number of rules they actually changed
 *   - not write to disk when nothing changes (avoids spurious file timestamps)
 */
class AutoLinkManagerBulkTest extends TestCase
{
    protected AutoLinkManager $manager;
    protected string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/linkwise-bulk-test-'.uniqid();
        @mkdir($this->storage, 0755, true);
        $this->manager = new AutoLinkManager($this->storage);
    }

    protected function tearDown(): void
    {
        @unlink($this->storage.'/autolink-rules.json');
        @rmdir($this->storage);
        parent::tearDown();
    }

    private function seedRules(int $count, bool $active = true): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $rule = $this->manager->createRule([
                'keyword' => 'kw-'.$i,
                'url' => 'https://example.com/'.$i,
                'active' => $active,
            ]);
            $ids[] = $rule->id;
        }

        return $ids;
    }

    // --- deleteRules ---

    public function test_delete_rules_removes_existing(): void
    {
        $ids = $this->seedRules(3);
        $this->assertSame(3, $this->manager->deleteRules($ids));
        $this->assertSame([], $this->manager->loadRules());
    }

    public function test_delete_rules_partial_match(): void
    {
        $ids = $this->seedRules(3);
        $this->assertSame(2, $this->manager->deleteRules([$ids[0], $ids[1], 'unknown-id']));
        $this->assertCount(1, $this->manager->loadRules());
    }

    public function test_delete_rules_all_unknown(): void
    {
        $this->seedRules(2);
        $this->assertSame(0, $this->manager->deleteRules(['x', 'y']));
        $this->assertCount(2, $this->manager->loadRules());
    }

    public function test_delete_rules_empty_list(): void
    {
        $this->seedRules(2);
        $this->assertSame(0, $this->manager->deleteRules([]));
        $this->assertCount(2, $this->manager->loadRules());
    }

    public function test_delete_rules_with_duplicate_ids(): void
    {
        $ids = $this->seedRules(2);
        // The first occurrence removes it; the second is a no-op.
        $this->assertSame(1, $this->manager->deleteRules([$ids[0], $ids[0]]));
    }

    // --- setRulesActive ---

    public function test_set_rules_active_flips_inactive_to_active(): void
    {
        $ids = $this->seedRules(3, false);
        $this->assertSame(3, $this->manager->setRulesActive($ids, true));
        foreach ($this->manager->loadRules() as $r) {
            $this->assertTrue($r->active);
        }
    }

    public function test_set_rules_active_skips_already_correct(): void
    {
        $ids = $this->seedRules(3, true);
        // Already active — nothing changes.
        $this->assertSame(0, $this->manager->setRulesActive($ids, true));
    }

    public function test_set_rules_active_partial_match(): void
    {
        $ids = $this->seedRules(2, true);
        $this->assertSame(2, $this->manager->setRulesActive(
            [$ids[0], $ids[1], 'unknown-id'],
            false,
        ));
        foreach ($this->manager->loadRules() as $r) {
            $this->assertFalse($r->active);
        }
    }

    public function test_set_rules_active_preserves_other_fields(): void
    {
        $ids = $this->seedRules(1, true);
        $before = $this->manager->loadRules()[$ids[0]];
        $this->manager->setRulesActive($ids, false);
        $after = $this->manager->loadRules()[$ids[0]];
        $this->assertSame($before->keyword, $after->keyword);
        $this->assertSame($before->url, $after->url);
        $this->assertSame($before->id, $after->id);
        $this->assertFalse($after->active);
    }

    // ── normalizeAutoApply (tri-state migration from legacy bool) ──

    public function test_normalize_auto_apply_legacy_true_becomes_follow_global(): void
    {
        $this->assertSame('follow_global', \Arturrossbach\Linkwise\AutoLink\AutoLinkRule::normalizeAutoApply(true));
    }

    public function test_normalize_auto_apply_legacy_false_becomes_never(): void
    {
        $this->assertSame('never', \Arturrossbach\Linkwise\AutoLink\AutoLinkRule::normalizeAutoApply(false));
    }

    public function test_normalize_auto_apply_passes_through_valid_states(): void
    {
        $this->assertSame('follow_global', \Arturrossbach\Linkwise\AutoLink\AutoLinkRule::normalizeAutoApply('follow_global'));
        $this->assertSame('always', \Arturrossbach\Linkwise\AutoLink\AutoLinkRule::normalizeAutoApply('always'));
        $this->assertSame('never', \Arturrossbach\Linkwise\AutoLink\AutoLinkRule::normalizeAutoApply('never'));
    }

    public function test_normalize_auto_apply_unknown_falls_back_to_follow_global(): void
    {
        $this->assertSame('follow_global', \Arturrossbach\Linkwise\AutoLink\AutoLinkRule::normalizeAutoApply(null));
        $this->assertSame('follow_global', \Arturrossbach\Linkwise\AutoLink\AutoLinkRule::normalizeAutoApply('invalid'));
        $this->assertSame('follow_global', \Arturrossbach\Linkwise\AutoLink\AutoLinkRule::normalizeAutoApply(1));
        $this->assertSame('follow_global', \Arturrossbach\Linkwise\AutoLink\AutoLinkRule::normalizeAutoApply(''));
    }

    public function test_rule_round_trips_tristate(): void
    {
        $rule = \Arturrossbach\Linkwise\AutoLink\AutoLinkRule::create([
            'keyword' => 'hund',
            'url' => 'https://example.com',
            'auto_apply_on_save' => 'always',
        ]);
        $this->assertSame('always', $rule->autoApplyOnSave);
        $this->assertSame('always', $rule->toArray()['auto_apply_on_save']);
    }
}

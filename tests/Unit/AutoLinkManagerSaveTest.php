<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\AutoLink\AutoLinkManager;
use Arturrossbach\Linkwise\AutoLink\AutoLinkRule;
use Arturrossbach\Linkwise\Tests\TestCase;

/**
 * H-2 (Code-Review 2026-05-29): AutoLinkManager::saveRules wrote the rule
 * store via raw file_put_contents(json_encode(...)) with no false-check and
 * non-atomically. A rule keyword carrying a malformed UTF-8 byte makes
 * json_encode return false; file_put_contents($path, false) writes '' and
 * truncates autolink-rules.json to 0 bytes — every rule the user created is
 * silently lost. The fix routes saveRules through AtomicJsonWriter (which
 * guards json_encode === false and writes atomically), so an unencodable
 * save leaves the existing store intact.
 */
class AutoLinkManagerSaveTest extends TestCase
{
    protected string $storage;

    protected AutoLinkManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/linkwise-save-test-'.uniqid();
        @mkdir($this->storage, 0755, true);
        $this->manager = new AutoLinkManager($this->storage);
    }

    protected function tearDown(): void
    {
        @unlink($this->storage.'/autolink-rules.json');
        foreach (glob($this->storage.'/autolink-rules.json.tmp.*') ?: [] as $orphan) {
            @unlink($orphan);
        }
        @rmdir($this->storage);
        parent::tearDown();
    }

    private function keywords(array $rules): array
    {
        return array_values(array_map(fn (AutoLinkRule $r) => $r->keyword, $rules));
    }

    public function test_save_roundtrip_persists_rules_and_leaves_no_staging_file(): void
    {
        $this->manager->createRule(['keyword' => 'Vue', 'url' => 'https://example.com/vue']);
        $this->manager->createRule(['keyword' => 'Inertia', 'url' => 'https://example.com/inertia']);

        $reloaded = (new AutoLinkManager($this->storage))->loadRules();

        $this->assertCount(2, $reloaded);
        $this->assertEqualsCanonicalizing(['Vue', 'Inertia'], $this->keywords($reloaded));
        // Atomic write must not leave a staging file behind.
        $this->assertSame([], glob($this->storage.'/autolink-rules.json.tmp.*') ?: []);
    }

    public function test_save_with_unencodable_keyword_does_not_wipe_existing_store(): void
    {
        // Seed a valid rule the user already created.
        $this->manager->createRule(['keyword' => 'Vue', 'url' => 'https://example.com/vue']);
        $this->assertCount(1, $this->manager->loadRules());

        // A rule whose keyword carries a malformed UTF-8 byte → json_encode
        // returns false for the whole payload.
        $bad = AutoLinkRule::create(['keyword' => "\xB1\x31", 'url' => 'https://example.com/bad']);
        $this->manager->saveRules([$bad]);

        // The store must survive: the previously-saved valid rule is intact,
        // not truncated to an empty file.
        $reloaded = (new AutoLinkManager($this->storage))->loadRules();
        $this->assertCount(1, $reloaded, 'unencodable save must not wipe the rule store');
        $this->assertSame(['Vue'], $this->keywords($reloaded));
    }
}

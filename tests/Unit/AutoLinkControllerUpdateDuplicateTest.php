<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\AutoLink\AutoLinkManager;
use Arturrossbach\Linkwise\Http\Controllers\AutoLinkController;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Tests\TestCase;
use Illuminate\Http\Request;

/**
 * L-1 (Code-Review 2026-05-29): AutoLinkController::store() rejects a
 * duplicate keyword with 422, but update() had no such gate — editing a
 * rule's keyword to collide with another rule silently produced two rules
 * competing for the same keyword with undefined precedence. update() must
 * mirror the store() gate, while still allowing a rule to keep its own
 * keyword (no self-conflict).
 */
class AutoLinkControllerUpdateDuplicateTest extends TestCase
{
    private string $storage;

    private AutoLinkManager $manager;

    private AutoLinkController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/linkwise-l1-test-'.uniqid();
        @mkdir($this->storage, 0755, true);
        $this->manager = new AutoLinkManager($this->storage);
        $this->controller = new AutoLinkController(
            $this->manager,
            new EntryIndexer(sys_get_temp_dir().'/linkwise-l1-idx-'.uniqid()),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->storage.'/autolink-rules.json');
        @rmdir($this->storage);
        parent::tearDown();
    }

    private function update(string $id, array $body): \Illuminate\Http\JsonResponse
    {
        return $this->controller->update(Request::create('/x', 'PUT', $body), $id);
    }

    public function test_editing_keyword_to_collide_with_another_rule_is_rejected(): void
    {
        $a = $this->manager->createRule(['keyword' => 'Vue', 'url' => 'https://example.com/vue']);
        $b = $this->manager->createRule(['keyword' => 'React', 'url' => 'https://example.com/react']);

        $resp = $this->update($b->id, ['keyword' => 'Vue']);

        $this->assertSame(422, $resp->getStatusCode(), 'colliding keyword edit must 422');
        // The rule must be left unchanged on disk.
        $this->assertSame('React', $this->manager->getRule($b->id)->keyword);
        // And the other rule untouched.
        $this->assertSame('Vue', $this->manager->getRule($a->id)->keyword);

        unset($a); // silence unused-var on some setups
    }

    public function test_editing_a_rule_keeping_its_own_keyword_is_allowed(): void
    {
        // No self-conflict: a rule may be edited without changing its keyword.
        $a = $this->manager->createRule(['keyword' => 'Vue', 'url' => 'https://example.com/vue']);

        $resp = $this->update($a->id, ['keyword' => 'Vue', 'url' => 'https://example.com/vue-new']);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('https://example.com/vue-new', $this->manager->getRule($a->id)->url);
    }

    public function test_editing_keyword_to_a_free_value_is_allowed(): void
    {
        $this->manager->createRule(['keyword' => 'Vue', 'url' => 'https://example.com/vue']);
        $b = $this->manager->createRule(['keyword' => 'React', 'url' => 'https://example.com/react']);

        $resp = $this->update($b->id, ['keyword' => 'Svelte']);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('Svelte', $this->manager->getRule($b->id)->keyword);
    }
}

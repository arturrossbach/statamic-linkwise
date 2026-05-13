<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\EntryFieldWalker;
use PHPUnit\Framework\TestCase;

/**
 * REV-RL-02 — Tests für den firstMutation-Helper.
 *
 * Pattern-Extraction für "walk blueprint fields, call callback per type, stop
 * on first mutation, write back via entry->set()". Heute hardcoded inline in
 * RelinkService::relink Step A.
 */
class EntryFieldWalkerFirstMutationTest extends TestCase
{
    private function makeFakeEntry(array $fields, array $values): object
    {
        $blueprint = new class($fields) {
            public function __construct(private array $fields) {}
            public function fields(): object
            {
                return new class($this->fields) {
                    public function __construct(private array $fields) {}
                    public function all(): array { return $this->fields; }
                };
            }
        };

        return new class($blueprint, $values) {
            public array $sets = [];
            public function __construct(private object $blueprint, private array $values) {}
            public function blueprint(): object { return $this->blueprint; }
            public function get(string $handle): mixed { return $this->values[$handle] ?? null; }
            public function set(string $handle, mixed $value): void
            {
                $this->sets[$handle] = $value;
                $this->values[$handle] = $value;
            }
            public function id(): string { return 'fake-id'; }
        };
    }

    private function makeField(string $type): object
    {
        return new class($type) {
            public function __construct(private string $type) {}
            public function type(): string { return $this->type; }
        };
    }

    public function test_returns_null_when_no_callback_mutates(): void
    {
        $entry = $this->makeFakeEntry(
            fields: ['body' => $this->makeField('bard')],
            values: ['body' => [['type' => 'paragraph']]],
        );

        $result = EntryFieldWalker::firstMutation(
            $entry,
            onBard: fn ($v) => null,
            onReplicator: fn ($v) => null,
            onMarkdown: fn ($v) => null,
        );

        $this->assertNull($result);
        $this->assertEmpty($entry->sets);
    }

    public function test_first_mutation_writes_back_and_returns_handle_and_type(): void
    {
        $entry = $this->makeFakeEntry(
            fields: ['body' => $this->makeField('bard'), 'extra' => $this->makeField('markdown')],
            values: ['body' => [['type' => 'paragraph']], 'extra' => 'some markdown'],
        );

        $result = EntryFieldWalker::firstMutation(
            $entry,
            onBard: fn ($v) => ['value' => [['type' => 'heading']], 'position' => [1, 2, 3]],
            onReplicator: fn ($v) => null,
            onMarkdown: fn ($v) => ['value' => 'mutated'],
        );

        $this->assertSame('body', $result['handle']);
        $this->assertSame('bard', $result['field_type']);
        $this->assertSame([[1, 2, 3]], [$result['result']['position']]);
        // Only 'body' was written — markdown callback never fired because Bard came first
        $this->assertSame([[['type' => 'heading']]], [$entry->sets['body']]);
        $this->assertArrayNotHasKey('extra', $entry->sets);
    }

    public function test_skips_bard_when_value_is_empty(): void
    {
        $entry = $this->makeFakeEntry(
            fields: ['empty' => $this->makeField('bard'), 'full' => $this->makeField('bard')],
            values: ['empty' => [], 'full' => [['type' => 'paragraph']]],
        );

        $callOrder = [];
        $result = EntryFieldWalker::firstMutation(
            $entry,
            onBard: function ($v) use (&$callOrder) {
                $callOrder[] = count($v);
                return count($v) > 0 ? ['value' => $v] : null;
            },
            onReplicator: fn ($v) => null,
            onMarkdown: fn ($v) => null,
        );

        $this->assertNotNull($result);
        $this->assertSame('full', $result['handle']);
        // Empty bard skipped before reaching callback — only the non-empty one was passed in
        $this->assertSame([1], $callOrder);
    }

    public function test_markdown_title_handle_is_skipped(): void
    {
        // 'title' as markdown-typed must be skipped — mirrors inline-cascade
        // skip in RelinkService Step A and BardLinkInserter.
        $entry = $this->makeFakeEntry(
            fields: ['title' => $this->makeField('markdown'), 'body' => $this->makeField('markdown')],
            values: ['title' => 'a title', 'body' => 'body markdown'],
        );

        $callValues = [];
        $result = EntryFieldWalker::firstMutation(
            $entry,
            onBard: fn ($v) => null,
            onReplicator: fn ($v) => null,
            onMarkdown: function ($v) use (&$callValues) {
                $callValues[] = $v;
                return ['value' => 'mutated:'.$v];
            },
        );

        $this->assertNotNull($result);
        $this->assertSame('body', $result['handle']);
        // 'title' never reached the callback
        $this->assertSame(['body markdown'], $callValues);
    }

    public function test_unsupported_field_types_are_skipped(): void
    {
        $entry = $this->makeFakeEntry(
            fields: ['date' => $this->makeField('date'), 'body' => $this->makeField('bard')],
            values: ['date' => '2026-01-01', 'body' => [['type' => 'paragraph']]],
        );

        $result = EntryFieldWalker::firstMutation(
            $entry,
            onBard: fn ($v) => ['value' => 'bard-mutated'],
            onReplicator: fn ($v) => null,
            onMarkdown: fn ($v) => null,
        );

        $this->assertSame('body', $result['handle']);
    }

    public function test_callback_must_return_value_key_when_signaling_mutation(): void
    {
        $entry = $this->makeFakeEntry(
            fields: ['body' => $this->makeField('bard')],
            values: ['body' => [['type' => 'paragraph']]],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"value" key/');

        EntryFieldWalker::firstMutation(
            $entry,
            onBard: fn ($v) => ['no_value_key' => 'oops'], // missing 'value'
            onReplicator: fn ($v) => null,
            onMarkdown: fn ($v) => null,
        );
    }

    public function test_blueprint_load_failure_returns_null_gracefully(): void
    {
        // Skipped at this level — the implementation calls Log::warning()
        // on blueprint-load failure which needs the Laravel facade root.
        // The behaviour itself is exercised at integration level by the
        // EntryFieldWalker existing tests via Orchestra Testbench. Keeping
        // this as a structural marker that the contract IS "return null
        // on failure", without bootstrapping the framework just to verify
        // a log-and-fallthrough path.
        $this->markTestSkipped('blueprint-failure path needs Laravel facade bootstrap; covered by integration tests');
    }
}

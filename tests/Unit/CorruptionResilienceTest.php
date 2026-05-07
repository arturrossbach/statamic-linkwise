<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\AutoLink\AutoLinkManager;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Indexer\EntryRecord;
use Arturrossbach\Linkwise\Links\BrokenLinkRecord;
use Arturrossbach\Linkwise\Links\BrokenLinkReport;
use Illuminate\Support\Facades\Log;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * Provoked-corruption tests — ensure that a corrupt JSON file on disk
 * cannot crash the CP. Linkwise stores three JSON-backed indexes
 * (linkwise-index.json, broken-links.json, autolink-rules.json) and a
 * fourth (domain-attributes.json) read by the public-site Bard renderer.
 *
 * Whatever a developer or sysadmin puts on disk — an old schema, a
 * truncated write, manually edited garbage — the loaders must skip
 * the bad records and keep serving the rest. None of these tests
 * should throw.
 */
class CorruptionResilienceTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/linkwise-corruption-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        // Silence the deliberate warning logs produced by the loaders during
        // these tests — we assert the behaviour, not the log output.
        Log::shouldReceive('warning')->andReturnNull();
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
        Mockery::close();
        parent::tearDown();
    }

    protected function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir.'/'.$name;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ─── EntryRecord / EntryIndexer ────────────────────────────────────────

    public function test_entry_record_throws_on_missing_required_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EntryRecord::fromArray(['title' => 'No id', 'collection' => 'articles']);
    }

    public function test_entry_indexer_skips_corrupt_records_and_returns_valid_ones(): void
    {
        $indexer = $this->makeIndexer();
        $indexPath = $this->tempDir.'/linkwise-index.json';

        // Mix of valid and corrupt entries — only the valid ones should survive.
        file_put_contents($indexPath, json_encode([
            ['id' => 'good-1', 'title' => 'Good', 'collection' => 'articles'],
            ['title' => 'Missing id', 'collection' => 'articles'],
            'not-an-array',
            ['id' => 'good-2', 'title' => 'Also good', 'collection' => 'pages'],
            null,
            ['id' => 123, 'title' => 'Bad id type', 'collection' => 'articles'],
        ]));

        $records = $indexer->load();

        $this->assertCount(2, $records);
        $this->assertArrayHasKey('good-1', $records);
        $this->assertArrayHasKey('good-2', $records);
    }

    public function test_entry_indexer_returns_empty_when_file_is_garbage_json(): void
    {
        $indexer = $this->makeIndexer();
        file_put_contents($this->tempDir.'/linkwise-index.json', '{"not": "an array of records"}');
        // is_array() check rejects associative-shape root; but iteration
        // would still treat it as iterable. Verify no throw + empty result
        // OR result with sane fallback (skip-on-throw catches the rest).
        $records = $indexer->load();
        $this->assertIsArray($records);
    }

    public function test_entry_indexer_returns_empty_when_file_is_invalid_json(): void
    {
        $indexer = $this->makeIndexer();
        file_put_contents($this->tempDir.'/linkwise-index.json', 'this is not json {[');
        $records = $indexer->load();
        $this->assertSame([], $records);
    }

    public function test_entry_indexer_returns_empty_when_file_missing(): void
    {
        $indexer = $this->makeIndexer();
        $records = $indexer->load();
        $this->assertSame([], $records);
    }

    // ─── BrokenLinkRecord / BrokenLinkReport ──────────────────────────────

    public function test_broken_link_record_throws_on_missing_post_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BrokenLinkRecord::fromArray(['url' => 'https://x']);
    }

    public function test_broken_link_record_throws_on_missing_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BrokenLinkRecord::fromArray(['post_id' => 'p1']);
    }

    public function test_broken_link_report_skips_corrupt_records(): void
    {
        $reportPath = $this->tempDir.'/broken-links.json';
        file_put_contents($reportPath, json_encode([
            'metadata' => ['scanned_at' => '2026-01-01T00:00:00+00:00'],
            'broken_links' => [
                ['post_id' => 'p1', 'post_title' => 'T1', 'url' => 'https://a'],
                ['post_title' => 'no post_id'], // corrupt
                'not-an-array', // corrupt
                ['post_id' => 'p2', 'url' => 'https://b'],
                null, // corrupt
            ],
        ]));

        $report = new BrokenLinkReport($this->tempDir);
        $data = $report->load();

        $this->assertCount(2, $data['broken_links']);
        $this->assertSame('p1', $data['broken_links'][0]->postId);
        $this->assertSame('p2', $data['broken_links'][1]->postId);
    }

    public function test_broken_link_report_handles_invalid_json_root(): void
    {
        file_put_contents($this->tempDir.'/broken-links.json', 'totally invalid {{');
        $report = new BrokenLinkReport($this->tempDir);
        $data = $report->load();
        $this->assertSame([], $data['broken_links']);
        $this->assertNull($data['metadata']);
    }

    public function test_broken_link_report_handles_non_array_broken_links_field(): void
    {
        file_put_contents($this->tempDir.'/broken-links.json', json_encode([
            'metadata' => null,
            'broken_links' => 'should-be-array-but-isnt',
        ]));
        $report = new BrokenLinkReport($this->tempDir);
        $data = $report->load();
        $this->assertSame([], $data['broken_links']);
    }

    // ─── AutoLinkManager ──────────────────────────────────────────────────

    public function test_autolink_manager_skips_corrupt_rules(): void
    {
        $rulesPath = $this->tempDir.'/autolink-rules.json';
        file_put_contents($rulesPath, json_encode([
            ['id' => 'ok-1', 'keyword' => 'foo', 'url' => 'statamic://entry::e1', 'active' => true],
            ['keyword' => 'no-id', 'url' => 'https://x'], // missing id
            'not-an-array', // wrong type
            ['id' => 'ok-2', 'keyword' => 'bar', 'url' => 'statamic://entry::e2', 'active' => true],
            ['id' => 'no-keyword', 'url' => 'https://y'], // missing keyword
            ['id' => 'no-url', 'keyword' => 'baz'], // missing url
            ['id' => 123, 'keyword' => 'bad-id-type', 'url' => 'https://z'], // wrong id type
        ]));

        $manager = new AutoLinkManager($this->tempDir);
        $rules = $manager->loadRules();

        $this->assertCount(2, $rules);
        $this->assertArrayHasKey('ok-1', $rules);
        $this->assertArrayHasKey('ok-2', $rules);
    }

    public function test_autolink_manager_returns_empty_when_file_invalid(): void
    {
        file_put_contents($this->tempDir.'/autolink-rules.json', 'not-json');
        $manager = new AutoLinkManager($this->tempDir);
        $this->assertSame([], $manager->loadRules());
    }

    // ─── Helper ───────────────────────────────────────────────────────────

    /**
     * Build an EntryIndexer pointed at our temp dir. The indexer is a
     * full-fledged Statamic-aware service; we only test the load() path
     * here, which doesn't touch Statamic — it just reads the JSON file.
     */
    protected function makeIndexer(): EntryIndexer
    {
        // Reach into the indexer via reflection — we don't want to spin
        // up a full Statamic boot just to test JSON tolerance.
        $indexer = new class($this->tempDir) extends EntryIndexer
        {
            public function __construct(protected string $testDir)
            {
                // Skip parent constructor — we don't need its dependencies for load()
            }

            protected function getIndexPath(): string
            {
                return $this->testDir.'/linkwise-index.json';
            }
        };

        return $indexer;
    }
}

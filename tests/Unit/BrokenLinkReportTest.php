<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Links\BrokenLinkRecord;
use Arturrossbach\Linkwise\Links\BrokenLinkReport;
use PHPUnit\Framework\TestCase;

class BrokenLinkReportTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/linkwise-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (['broken-links.json', 'ignored-links.json'] as $name) {
            $path = $this->tempDir.'/'.$name;
            if (file_exists($path)) {
                unlink($path);
            }
        }
        rmdir($this->tempDir);
        parent::tearDown();
    }

    protected function makeRecord(string $postId, string $url, string $title = 'Test', bool $ignored = false): BrokenLinkRecord
    {
        return new BrokenLinkRecord(
            postId: $postId,
            postTitle: $title,
            url: $url,
            anchorText: 'click',
            type: 'external',
            statusCode: 404,
            errorType: 'not_found',
            firstDetectedAt: '2026-01-01T00:00:00+00:00',
            lastCheckedAt: '2026-01-01T00:00:00+00:00',
            ignored: $ignored,
        );
    }

    public function test_remove_link_removes_only_one_occurrence(): void
    {
        $report = new BrokenLinkReport($this->tempDir);

        $report->save([
            $this->makeRecord('entry-1', 'https://broken.com'),
            $this->makeRecord('entry-1', 'https://broken.com'),
            $this->makeRecord('entry-1', 'https://broken.com'),
        ]);

        $report->removeLink('entry-1', 'https://broken.com');

        $data = $report->load();
        $this->assertCount(2, $data['broken_links']);
    }

    public function test_remove_link_does_not_affect_other_entries(): void
    {
        $report = new BrokenLinkReport($this->tempDir);

        $report->save([
            $this->makeRecord('entry-1', 'https://broken.com', 'Entry One'),
            $this->makeRecord('entry-2', 'https://broken.com', 'Entry Two'),
        ]);

        $report->removeLink('entry-1', 'https://broken.com');

        $data = $report->load();
        $this->assertCount(1, $data['broken_links']);
        $this->assertSame('entry-2', $data['broken_links'][0]->postId);
    }

    public function test_set_ignored_flips_flag_on_matching_record(): void
    {
        $report = new BrokenLinkReport($this->tempDir);
        $report->save([
            $this->makeRecord('e1', 'https://x.com'),
            $this->makeRecord('e2', 'https://y.com'),
        ]);

        $found = $report->setIgnored('e2', 'https://y.com', true);
        $this->assertTrue($found);

        $data = $report->load();
        $this->assertFalse($data['broken_links'][0]->ignored);
        $this->assertTrue($data['broken_links'][1]->ignored);
    }

    public function test_set_ignored_returns_false_for_unknown_record(): void
    {
        $report = new BrokenLinkReport($this->tempDir);
        $report->save([$this->makeRecord('e1', 'https://x.com')]);

        $found = $report->setIgnored('e999', 'https://nope.com', true);

        $this->assertFalse($found);
    }

    public function test_set_ignored_can_toggle_back(): void
    {
        $report = new BrokenLinkReport($this->tempDir);
        $report->save([$this->makeRecord('e1', 'https://x.com', 'Test', ignored: true)]);

        $report->setIgnored('e1', 'https://x.com', false);

        $data = $report->load();
        $this->assertFalse($data['broken_links'][0]->ignored);
    }

    public function test_set_ignored_preserves_metadata(): void
    {
        $report = new BrokenLinkReport($this->tempDir);
        $report->save([$this->makeRecord('e1', 'https://x.com')], 42.5);

        $before = $report->load();
        $lastChecked = $before['metadata']['last_checked'];

        $report->setIgnored('e1', 'https://x.com', true);

        $after = $report->load();
        $this->assertSame($lastChecked, $after['metadata']['last_checked'], 'last_checked should not change');
        $this->assertSame(42.5, $after['metadata']['duration_seconds']);
    }

    public function test_to_array_includes_ignored_flag(): void
    {
        $report = new BrokenLinkReport($this->tempDir);
        $report->save([
            $this->makeRecord('e1', 'https://a.com'),
            $this->makeRecord('e2', 'https://b.com', 'Entry B', ignored: true),
        ]);

        $result = $report->toArray();

        $this->assertCount(2, $result['broken_links']);
        $this->assertFalse($result['broken_links'][0]['ignored']);
        $this->assertTrue($result['broken_links'][1]['ignored']);
    }

    public function test_legacy_ignored_file_migrates_into_broken_links(): void
    {
        // Simulate pre-consolidation state
        $broken = [
            'metadata' => ['last_checked' => '2026-01-01T00:00:00+00:00', 'duration_seconds' => 10, 'broken_count' => 1],
            'broken_links' => [[
                'post_id' => 'e1', 'post_title' => 'One', 'url' => 'https://a.com',
                'anchor_text' => '', 'type' => 'external', 'status_code' => 404, 'error_type' => 'not_found',
                'first_detected_at' => '2026-01-01T00:00:00+00:00', 'last_checked_at' => '2026-01-01T00:00:00+00:00',
                'sentence_context' => '',
            ]],
        ];
        file_put_contents($this->tempDir.'/broken-links.json', json_encode($broken));
        file_put_contents($this->tempDir.'/ignored-links.json', json_encode([
            ['post_id' => 'e2', 'url' => 'https://b.com', 'post_title' => 'Ignored One', 'type' => 'external', 'status_label' => 'Forbidden'],
        ]));

        $report = new BrokenLinkReport($this->tempDir);
        $data = $report->load();

        $this->assertCount(2, $data['broken_links']);
        $this->assertFalse($data['broken_links'][0]->ignored);
        $this->assertTrue($data['broken_links'][1]->ignored);
        $this->assertFileDoesNotExist($this->tempDir.'/ignored-links.json', 'Legacy file should be deleted after migration');
    }

    public function test_add_record_appends_without_rewriting_metadata(): void
    {
        $report = new BrokenLinkReport($this->tempDir);
        $report->save([$this->makeRecord('e1', 'https://a.com')], 5.0);

        $before = $report->load();
        $lastChecked = $before['metadata']['last_checked'];

        $report->addRecord($this->makeRecord('e2', 'https://b.com'));

        $after = $report->load();
        $this->assertCount(2, $after['broken_links']);
        $this->assertSame($lastChecked, $after['metadata']['last_checked']);
        $this->assertSame(2, $after['metadata']['broken_count']);
    }

    public function test_save_and_load_roundtrip(): void
    {
        $report = new BrokenLinkReport($this->tempDir);

        $records = [
            $this->makeRecord('e1', 'https://a.com', 'Entry A'),
            $this->makeRecord('e2', 'https://b.com', 'Entry B', ignored: true),
        ];

        $report->save($records, 1.5);

        $data = $report->load();
        $this->assertCount(2, $data['broken_links']);
        $this->assertSame(1.5, $data['metadata']['duration_seconds']);
        $this->assertFalse($data['broken_links'][0]->ignored);
        $this->assertTrue($data['broken_links'][1]->ignored);
    }
}

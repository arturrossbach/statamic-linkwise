<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\BulkSnapshotStore;
use Illuminate\Support\Facades\Log;
use Mockery;
use Orchestra\Testbench\TestCase;

class BulkSnapshotStoreTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/linkwise-snap-'.uniqid();
        Log::shouldReceive('warning')->andReturnNull();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tempDir);
        }
        Mockery::close();
        parent::tearDown();
    }

    public function test_record_creates_a_json_file_with_expected_keys(): void
    {
        $store = new BulkSnapshotStore($this->tempDir);

        $id = $store->record(
            kind: 'detailunlink',
            entryIds: ['e1', 'e2'],
            preHashes: ['e1' => 'abc', 'e2' => 'def'],
            summary: ['source_mode' => 'inbound', 'entry_title' => 'Test'],
        );

        $this->assertNotEmpty($id);
        $path = $this->tempDir.'/'.$id.'.json';
        $this->assertFileExists($path);

        $data = json_decode(file_get_contents($path), true);
        $this->assertSame('detailunlink', $data['kind']);
        $this->assertSame(['e1', 'e2'], $data['entry_ids']);
        $this->assertSame(['e1' => 'abc', 'e2' => 'def'], $data['pre_hashes']);
        $this->assertSame('inbound', $data['summary']['source_mode']);
        $this->assertSame(2, $data['entry_count_total']);
        $this->assertFalse($data['entries_trimmed']);
    }

    public function test_record_dedupes_and_filters_entry_ids(): void
    {
        $store = new BulkSnapshotStore($this->tempDir);

        $id = $store->record(
            kind: 'bulkunlink',
            entryIds: ['e1', 'e2', 'e1', '', null, 123, 'e3'],
            preHashes: [],
            summary: [],
        );

        $data = $store->get($id);
        // Only valid string ids, deduped
        $this->assertSame(['e1', 'e2', 'e3'], $data['entry_ids']);
    }

    public function test_record_caps_huge_entry_lists(): void
    {
        $store = new BulkSnapshotStore($this->tempDir);

        $entries = [];
        for ($i = 0; $i < 1500; $i++) {
            $entries[] = 'entry-'.$i;
        }

        $id = $store->record('urlchanger', $entries);
        $data = $store->get($id);

        $this->assertCount(1000, $data['entry_ids']);
        $this->assertSame(1500, $data['entry_count_total']);
        $this->assertTrue($data['entries_trimmed']);
    }

    public function test_list_returns_newest_first(): void
    {
        $store = new BulkSnapshotStore($this->tempDir);

        $id1 = $store->record('applyrule', ['e1']);
        usleep(1100000); // 1.1s — file mtime resolution is per-second
        $id2 = $store->record('applyrule', ['e2']);
        usleep(1100000);
        $id3 = $store->record('applyrule', ['e3']);

        $list = $store->list();
        $this->assertCount(3, $list);
        $this->assertSame($id3, $list[0]['id']);
        $this->assertSame($id2, $list[1]['id']);
        $this->assertSame($id1, $list[2]['id']);
    }

    public function test_list_respects_limit(): void
    {
        $store = new BulkSnapshotStore($this->tempDir);

        for ($i = 0; $i < 5; $i++) {
            $store->record('applyrule', ['e'.$i]);
        }

        $this->assertCount(2, $store->list(2));
    }

    public function test_list_returns_empty_when_directory_missing(): void
    {
        $store = new BulkSnapshotStore($this->tempDir.'/nope');
        $this->assertSame([], $store->list());
    }

    public function test_list_skips_corrupt_files(): void
    {
        $store = new BulkSnapshotStore($this->tempDir);
        @mkdir($this->tempDir, 0755, true);

        // Valid record
        $store->record('applyrule', ['e1']);
        // Corrupt file in the same dir
        file_put_contents($this->tempDir.'/garbage.json', 'not-valid-json');

        $list = $store->list();
        $this->assertCount(1, $list); // garbage skipped silently
    }

    public function test_get_returns_null_for_unknown_id(): void
    {
        $store = new BulkSnapshotStore($this->tempDir);
        $this->assertNull($store->get('does-not-exist'));
    }

    public function test_mark_reverted_sets_fields(): void
    {
        $store = new BulkSnapshotStore($this->tempDir);

        $id = $store->record('applyrule', ['e1'], ['e1' => 'h1']);
        $store->markReverted($id, 'revert-snap-id');

        $reloaded = $store->get($id);
        $this->assertNotNull($reloaded['reverted_at'] ?? null);
        $this->assertSame('revert-snap-id', $reloaded['reverted_by']);
    }

    public function test_mark_reverted_is_idempotent_silent_on_missing_id(): void
    {
        $store = new BulkSnapshotStore($this->tempDir);
        // Must not throw on unknown id
        $store->markReverted('does-not-exist', 'whatever');
        $this->assertTrue(true); // reached without throwing
    }

    public function test_record_doesnt_throw_when_directory_uncreatable(): void
    {
        // Path that can't be created (file collision)
        $blocker = sys_get_temp_dir().'/linkwise-blocker-'.uniqid();
        touch($blocker);
        try {
            $store = new BulkSnapshotStore($blocker);
            // record() must NEVER throw — it's best-effort
            $id = $store->record('applyrule', ['e1']);
            $this->assertNotEmpty($id); // returns id even if write failed
        } finally {
            @unlink($blocker);
        }
    }

    public function test_record_revert_skipped_appends_across_multiple_calls(): void
    {
        // The 2026-05-08 fix: DetailUnlink, UrlChangerApply and LinkInsert
        // now write skip records on EVERY conflict — not only when running
        // as a revert. For original snapshots that get reverted, this means
        // recordRevertSkipped is called twice: once by the original bulk
        // for its own conflicts ($snapshotId), once by the revert run for
        // entries it had to skip ($reverts). Both sets must coexist on the
        // same snapshot — the drawer reads the merged list. If the second
        // call clobbered the first, the original bulk's conflicts would
        // disappear when the revert ran.
        $store = new BulkSnapshotStore($this->tempDir);
        $id = $store->record('detailunlink', ['e1', 'e2', 'e3']);

        $store->recordRevertSkipped($id, [
            ['entry_id' => 'e1', 'reason' => 'modified', 'entry_title' => 'First'],
        ]);
        $store->recordRevertSkipped($id, [
            ['entry_id' => 'e2', 'reason' => 'modified', 'entry_title' => 'Second'],
            ['entry_id' => 'e3', 'reason' => 'deleted',  'entry_title' => 'Third'],
        ]);

        $reloaded = $store->get($id);
        $this->assertCount(3, $reloaded['revert_skipped']);
        $this->assertSame('e1', $reloaded['revert_skipped'][0]['entry_id']);
        $this->assertSame('e2', $reloaded['revert_skipped'][1]['entry_id']);
        $this->assertSame('e3', $reloaded['revert_skipped'][2]['entry_id']);
        $this->assertSame('deleted', $reloaded['revert_skipped'][2]['reason']);
    }

    public function test_record_revert_skipped_no_op_on_empty_array(): void
    {
        // Defensive — every command issues this call unconditionally now,
        // so an "always-record" pattern with zero conflicts must NOT
        // initialise revert_skipped to []. Drawers test
        // Array.isArray(skipped) && skipped.length > 0 — an empty array
        // would still be truthy on the array side but cleaner not to write.
        $store = new BulkSnapshotStore($this->tempDir);
        $id = $store->record('linkinsert', ['e1']);

        $store->recordRevertSkipped($id, []);

        $reloaded = $store->get($id);
        $this->assertArrayNotHasKey('revert_skipped', $reloaded);
    }

    public function test_record_revert_skipped_no_op_when_snapshot_missing(): void
    {
        // Snapshot directory cleaned up between record and revert (e.g.
        // 30-day auto-prune fired during a long-running revert) — must
        // not throw, the revert proceeds even without forensic context.
        $store = new BulkSnapshotStore($this->tempDir);

        $store->recordRevertSkipped('does-not-exist', [
            ['entry_id' => 'e1', 'reason' => 'modified'],
        ]);

        $this->assertTrue(true); // reached without throwing
    }
}

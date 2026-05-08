<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;
use Statamic\Entries\Entry;

/**
 * Disk-path-aware hash() tests.
 *
 * SafeEntrySaver::hash() reads the on-disk YAML file rather than the
 * in-memory $entry->data() so the hash matches what a fresh Entry::find()
 * will reproduce. The bug this guards against: Statamic's Bard fieldtype
 * canonicalises content during save() — re-serialising the in-memory
 * Entry produces a different json_encode byte stream from the YAML on
 * disk, even though the persisted state is byte-identical. Hashing
 * data() then captured a hash no later find() could ever match,
 * falsely flagging revert-preview entries as "modified by user".
 *
 * The legacy md5(json_encode($data)) fallback still applies when the
 * entry has no path yet (creation flow). New entries are written with
 * an empty $expectedHash so the value is never compared anyway —
 * fallback exists to keep the call site interface uniform.
 */
class SafeEntrySaverHashTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/linkwise-safe-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tempDir);
        Mockery::close();
        parent::tearDown();
    }

    public function test_hash_reads_from_disk_when_path_resolves_to_real_file(): void
    {
        $path = $this->writeFile('a.md', "title: Test\ncontent: hello\n");
        $entry = $this->mockEntry(path: $path);

        $expected = md5("title: Test\ncontent: hello\n");
        $this->assertSame($expected, SafeEntrySaver::hash($entry));
    }

    public function test_hash_strips_updated_at_line_from_disk_content(): void
    {
        // Two on-disk states differ only in updated_at — Statamic rewrites
        // this on every save() (including cascading subscribers), so it
        // must NOT contribute to the hash or every cascade looks like a
        // user edit.
        $pathA = $this->writeFile('a.md', "title: Test\nupdated_at: 1700000000\ncontent: hi\n");
        $pathB = $this->writeFile('b.md', "title: Test\nupdated_at: 1800000000\ncontent: hi\n");

        $hashA = SafeEntrySaver::hash($this->mockEntry(path: $pathA));
        $hashB = SafeEntrySaver::hash($this->mockEntry(path: $pathB));

        $this->assertSame($hashA, $hashB, 'updated_at differences must not affect the hash');
    }

    public function test_hash_strips_updated_by_and_last_modified_lines(): void
    {
        // All three volatile keys are stripped by the same regex. Verify
        // each independently — a regex typo could silently leak one.
        $pathA = $this->writeFile('a.md', "title: T\nupdated_by: alice\ncontent: c\n");
        $pathB = $this->writeFile('b.md', "title: T\nupdated_by: bob\ncontent: c\n");
        $this->assertSame(
            SafeEntrySaver::hash($this->mockEntry(path: $pathA)),
            SafeEntrySaver::hash($this->mockEntry(path: $pathB)),
            'updated_by must be stripped',
        );

        $pathC = $this->writeFile('c.md', "title: T\nlast_modified: 2026-01-01\ncontent: c\n");
        $pathD = $this->writeFile('d.md', "title: T\nlast_modified: 2026-12-31\ncontent: c\n");
        $this->assertSame(
            SafeEntrySaver::hash($this->mockEntry(path: $pathC)),
            SafeEntrySaver::hash($this->mockEntry(path: $pathD)),
            'last_modified must be stripped',
        );
    }

    public function test_hash_changes_when_real_content_changes(): void
    {
        // Sanity check that the strip regex isn't over-broad.
        $pathA = $this->writeFile('a.md', "title: First\ncontent: hi\n");
        $pathB = $this->writeFile('b.md', "title: Second\ncontent: hi\n");

        $this->assertNotSame(
            SafeEntrySaver::hash($this->mockEntry(path: $pathA)),
            SafeEntrySaver::hash($this->mockEntry(path: $pathB)),
        );
    }

    public function test_hash_falls_back_to_data_when_path_is_null(): void
    {
        // Creation flow: the entry hasn't been persisted yet, path() is null.
        // hash() must still produce a value (not throw) — callers compare
        // against an empty string in this case so the value is never
        // asserted equal to anything, but the function must remain total.
        $entry = $this->mockEntry(path: null, data: ['title' => 'Draft']);

        $expected = md5(json_encode(['title' => 'Draft']));
        $this->assertSame($expected, SafeEntrySaver::hash($entry));
    }

    public function test_hash_falls_back_to_data_when_path_points_to_missing_file(): void
    {
        // Race: entry was deleted between path() resolution and file read.
        // Same fallback as the null-path case.
        $missing = $this->tempDir.'/never-existed.md';
        $entry = $this->mockEntry(path: $missing, data: ['title' => 'Ghost']);

        $expected = md5(json_encode(['title' => 'Ghost']));
        $this->assertSame($expected, SafeEntrySaver::hash($entry));
    }

    public function test_hash_fallback_strips_volatile_keys_from_data_array(): void
    {
        // The fallback path mirrors the disk-strip behaviour — volatile
        // keys removed from the data array before json_encode/md5.
        $entryA = $this->mockEntry(path: null, data: [
            'title' => 'Test',
            'updated_at' => 1700000000,
            'updated_by' => 'alice',
            'last_modified' => '2026-01-01',
        ]);
        $entryB = $this->mockEntry(path: null, data: [
            'title' => 'Test',
            'updated_at' => 1800000000,
            'updated_by' => 'bob',
            'last_modified' => '2026-12-31',
        ]);

        $this->assertSame(
            SafeEntrySaver::hash($entryA),
            SafeEntrySaver::hash($entryB),
            'volatile keys must be stripped in the fallback path too',
        );
    }

    /**
     * Build a Mockery-backed Entry stub. data() is set up unconditionally
     * because Mockery's strict mode otherwise errors on unexpected calls
     * even when the disk-path branch returns first — making the path
     * tests fragile to unrelated refactors.
     */
    private function mockEntry(?string $path, array $data = []): Entry
    {
        $entry = Mockery::mock(Entry::class);
        $entry->shouldReceive('path')->andReturn($path);
        $entry->shouldReceive('data')->andReturn(new Collection($data));

        return $entry;
    }

    private function writeFile(string $name, string $content): string
    {
        $path = $this->tempDir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }
}
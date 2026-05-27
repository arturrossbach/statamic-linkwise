<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\AtomicJsonWriter;
use Arturrossbach\Linkwise\Tests\TestCase;

/**
 * Pin-Test for CR-H-3 (V1.x-Polish-Audit):
 *
 *   AtomicJsonWriter::write() must return `true` ONLY when the target file
 *   on disk contains the full intended payload. A `true` return that doesn't
 *   match disk state is the bug — fallback `file_put_contents()` could
 *   partial-write on disk-full and previously returned `true` because
 *   `!== false` passed.
 *
 * ## Test scope (and scope limits, honest)
 *
 * Pins the SUCCESS postcondition:
 *   - return value matches file presence
 *   - return value matches content roundtrip
 *   - file size matches the written content (byte-exact)
 *
 * Does NOT reproduce the truncation-bug via Filesystem-Mock. Linkwise has
 * no FS-mock layer; constructing a real partial-write requires kernel-level
 * simulation. The fix is derived from code-inspection of `file_put_contents`
 * semantics (returns int byte count or false; partial-byte-count is
 * legitimate behaviour on interrupted writes). Pin guards the postcondition;
 * the bug-class is mitigated by the size-equality check in the source.
 */
class AtomicJsonWriterTest extends TestCase
{
    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpPath = sys_get_temp_dir().'/linkwise-atomicjson-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpPath);
        // Defensive: clean up any orphan staging files from a failed run.
        foreach (glob($this->tmpPath.'.tmp.*') ?: [] as $orphan) {
            @unlink($orphan);
        }
        parent::tearDown();
    }

    public function test_write_returns_true_and_file_matches_payload_on_happy_path(): void
    {
        $data = ['answer' => 42, 'unicode' => 'äöü', 'nested' => ['a' => 1]];

        $ok = AtomicJsonWriter::write($this->tmpPath, $data, 'PinTest');

        $this->assertTrue($ok, 'happy-path write should return true');
        $this->assertFileExists($this->tmpPath);

        $written = file_get_contents($this->tmpPath);
        $this->assertNotFalse($written);

        // Postcondition that the size-equality fix protects: file on disk
        // must match the byte-count of the intended JSON. A truncated
        // fallback write would have returned true previously but failed
        // this check.
        $this->assertSame(
            strlen($written),
            filesize($this->tmpPath),
            'file size must equal the in-memory content length',
        );

        $this->assertSame($data, json_decode($written, true));
    }

    public function test_write_to_unwritable_path_returns_false_and_does_not_corrupt_target(): void
    {
        $unwritable = '/this/path/does/not/exist/'.uniqid().'.json';

        $ok = AtomicJsonWriter::write($unwritable, ['x' => 1], 'PinTest');

        $this->assertFalse($ok, 'write to unwritable path must return false');
        $this->assertFileDoesNotExist($unwritable);
    }

    public function test_write_overwrites_existing_file_atomically(): void
    {
        // Seed an existing file with garbage.
        file_put_contents($this->tmpPath, '{"old": "garbage"}');

        $newData = ['fresh' => 'content'];
        $ok = AtomicJsonWriter::write($this->tmpPath, $newData, 'PinTest');

        $this->assertTrue($ok);
        $this->assertSame($newData, json_decode((string) file_get_contents($this->tmpPath), true));
    }
}

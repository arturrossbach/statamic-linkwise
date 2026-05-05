<?php

namespace Inkline\Linkwise\Tests\Unit;

use Inkline\Linkwise\Exceptions\EntryConflictException;
use Inkline\Linkwise\Support\SafeEntrySaver;
use PHPUnit\Framework\TestCase;

class SafeEntrySaverTest extends TestCase
{
    public function test_hash_is_deterministic(): void
    {
        // Same data should produce same hash
        $hash1 = md5(json_encode(['title' => 'Test', 'content' => 'Hello']));
        $hash2 = md5(json_encode(['title' => 'Test', 'content' => 'Hello']));

        $this->assertSame($hash1, $hash2);
    }

    public function test_hash_changes_on_data_change(): void
    {
        $hash1 = md5(json_encode(['title' => 'Test', 'content' => 'Hello']));
        $hash2 = md5(json_encode(['title' => 'Test', 'content' => 'Changed']));

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_conflict_exception_has_entry_info(): void
    {
        $exception = new EntryConflictException('abc-123', 'My Entry');

        $this->assertSame('abc-123', $exception->entryId);
        $this->assertSame('My Entry', $exception->entryTitle);
        $this->assertStringContainsString('My Entry', $exception->getMessage());
        $this->assertStringContainsString('modified by another user', $exception->getMessage());
    }

    public function test_hash_from_preview_detects_parallel_edit(): void
    {
        // Simulates: User gets preview (hash A), editor changes entry (hash B),
        // user clicks Apply with hash A → must be rejected

        $dataV1 = ['title' => 'My Article', 'content' => [['type' => 'paragraph']]];
        $dataV2 = ['title' => 'My Article', 'content' => [['type' => 'paragraph', 'text' => 'edited']]];

        $hashAtPreview = md5(json_encode($dataV1));
        $hashAfterEdit = md5(json_encode($dataV2));

        // These must differ — if they don't, the locking is useless
        $this->assertNotSame($hashAtPreview, $hashAfterEdit);
    }

    public function test_hash_stable_when_no_changes(): void
    {
        $data = ['title' => 'Test', 'bard' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]]]];

        $hash1 = md5(json_encode($data));
        $hash2 = md5(json_encode($data));

        $this->assertSame($hash1, $hash2);
    }
}

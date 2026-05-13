<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\JobLock;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the busy-job 409 message string.
 *
 * REV-BJ-03 documents the global JobLock as INTENTIONAL — load-bearing for
 * index integrity (naive per-entry locking would cause silent index
 * corruption when two bulks save to the JSON index simultaneously). Since
 * we're not refactoring the lock, the user-facing change is making the
 * 409 message explain WHY they're waiting, WHO triggered the conflict,
 * WHAT phase the running job is in, and WHAT progress it has made.
 *
 * Pure-static helper buildBusyMessage() is the testable unit; the
 * busyResponseData() wrapper just feeds it data from cache.
 */
class JobLockBusyMessageTest extends TestCase
{
    public function test_includes_label_for_running_job(): void
    {
        $msg = JobLock::buildBusyMessage(
            label: 'URL Changer Apply',
            phase: 'running',
            current: null,
            total: null,
            startedBy: null,
            isOwner: false,
        );

        $this->assertStringContainsString('URL Changer Apply', $msg);
    }

    public function test_includes_started_by_when_not_owner(): void
    {
        $msg = JobLock::buildBusyMessage(
            label: 'Apply Rule',
            phase: 'running',
            current: null,
            total: null,
            startedBy: 'Anna',
            isOwner: false,
        );

        $this->assertStringContainsString('Anna', $msg,
            'Other editors must be named so the user knows the conflict source');
    }

    public function test_hides_started_by_when_self(): void
    {
        $msg = JobLock::buildBusyMessage(
            label: 'Apply Rule',
            phase: 'running',
            current: null,
            total: null,
            startedBy: 'Artur',
            isOwner: true,
        );

        $this->assertStringNotContainsString('Artur', $msg,
            'Self-triggered runs must not say "started by yourself" — that is noise');
    }

    public function test_progress_included_when_available(): void
    {
        $msg = JobLock::buildBusyMessage(
            label: 'Bulk Unlink',
            phase: 'running',
            current: 42,
            total: 100,
            startedBy: null,
            isOwner: false,
        );

        $this->assertStringContainsString('42', $msg);
        $this->assertStringContainsString('100', $msg);
    }

    public function test_indexing_phase_communicates_finalization(): void
    {
        $msg = JobLock::buildBusyMessage(
            label: 'Apply Rule',
            phase: 'indexing',
            current: null,
            total: null,
            startedBy: null,
            isOwner: false,
        );

        // 'indexing' is the post-mutation finalize phase — distinct UX from
        // 'running' because cancel is no longer possible at this point.
        $this->assertStringContainsString('Index', $msg);
    }

    public function test_handles_missing_phase_gracefully(): void
    {
        // Older payloads may not have phase set yet. Must not crash.
        $msg = JobLock::buildBusyMessage(
            label: 'Apply Rule',
            phase: null,
            current: null,
            total: null,
            startedBy: null,
            isOwner: false,
        );

        $this->assertNotEmpty($msg);
        $this->assertStringContainsString('Apply Rule', $msg);
    }

    public function test_actionable_guidance_present(): void
    {
        $msg = JobLock::buildBusyMessage(
            label: 'Bulk Unlink',
            phase: 'running',
            current: null,
            total: null,
            startedBy: null,
            isOwner: false,
        );

        // The message must end with what the user should DO. Just "busy" is
        // not actionable; "wait" / "warten" tells them this is normal.
        $this->assertMatchesRegularExpression(
            '/(wait|warten|finish|abgeschlossen|done)/i',
            $msg,
            'Message must include actionable guidance (wait / finish indicator)'
        );
    }
}

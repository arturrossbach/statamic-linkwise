<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\ExecAvailability;
use PHPUnit\Framework\TestCase;

/**
 * Pin for the exec-availability probe. The probe gates the CP banner
 * that warns shared-hosting users that Linkwise's bulk-job pipeline
 * (Scan Content, Check Links, Bulk Unlink, …) requires shell-exec
 * primitives that some hosts disable via ini `disable_functions`.
 *
 * Tested through the `setForTesting()` seam — we don't want this
 * suite to depend on the runtime PHP's actual configuration.
 */
class ExecAvailabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        ExecAvailability::setForTesting(null);
        parent::tearDown();
    }

    public function test_reports_available_when_both_primitives_pass(): void
    {
        ExecAvailability::setForTesting([
            'exec_available' => true,
            'proc_open_available' => true,
        ]);

        $this->assertTrue(ExecAvailability::available());
        $check = ExecAvailability::check();
        $this->assertTrue($check['exec_available']);
        $this->assertTrue($check['proc_open_available']);
    }

    public function test_reports_unavailable_when_exec_is_disabled(): void
    {
        ExecAvailability::setForTesting([
            'exec_available' => false,
            'proc_open_available' => true,
        ]);

        $this->assertFalse(
            ExecAvailability::available(),
            'available() must be false when EITHER primitive is missing — both are required by the bulk-job pipeline'
        );
    }

    public function test_reports_unavailable_when_proc_open_is_disabled(): void
    {
        ExecAvailability::setForTesting([
            'exec_available' => true,
            'proc_open_available' => false,
        ]);

        $this->assertFalse(ExecAvailability::available());
    }

    public function test_carries_disabled_functions_list_for_banner_copy(): void
    {
        ExecAvailability::setForTesting([
            'exec_available' => false,
            'proc_open_available' => false,
            'disabled_functions' => ['exec', 'proc_open', 'shell_exec'],
        ]);

        $check = ExecAvailability::check();
        $this->assertContains('exec', $check['disabled_functions']);
        $this->assertContains('proc_open', $check['disabled_functions']);
        $this->assertContains('shell_exec', $check['disabled_functions']);
    }

    public function test_clear_cache_resets_state_between_tests(): void
    {
        ExecAvailability::setForTesting([
            'exec_available' => false,
            'proc_open_available' => false,
        ]);
        $this->assertFalse(ExecAvailability::available());

        ExecAvailability::setForTesting(null);

        // After reset, the real probe runs against the test runner's PHP.
        // Test-runner PHP has exec + proc_open available — that's the
        // entire reason we can run the audit + Statamic suite — so this
        // doubles as a sanity check that the live probe path works.
        $this->assertTrue(
            ExecAvailability::available(),
            'Test-runner PHP must have exec/proc_open available; this catches the case where the live probe path is broken'
        );
    }
}

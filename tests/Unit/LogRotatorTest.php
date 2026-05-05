<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\LogRotator;
use Orchestra\Testbench\TestCase;

class LogRotatorTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logPath = storage_path('linkwise/test-rotator-unit.log');
        @unlink($this->logPath);
        @unlink($this->logPath.'.1');
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
        @unlink($this->logPath.'.1');
        parent::tearDown();
    }

    public function test_prepare_creates_directory_and_writes_separator(): void
    {
        $path = LogRotator::prepare('test-rotator-unit.log', 'Run 1');

        $this->assertFileExists($path);
        $this->assertSame($this->logPath, $path);
        $contents = file_get_contents($path);
        $this->assertStringContainsString('Run 1', $contents);
        $this->assertStringContainsString('──────────', $contents);
    }

    public function test_subsequent_runs_append_with_separator(): void
    {
        LogRotator::prepare('test-rotator-unit.log', 'Run 1');
        file_put_contents($this->logPath, "output 1\n", FILE_APPEND);

        LogRotator::prepare('test-rotator-unit.log', 'Run 2');
        file_put_contents($this->logPath, "output 2\n", FILE_APPEND);

        $contents = file_get_contents($this->logPath);
        $this->assertStringContainsString('Run 1', $contents);
        $this->assertStringContainsString('output 1', $contents);
        $this->assertStringContainsString('Run 2', $contents);
        $this->assertStringContainsString('output 2', $contents);
        // Run 1 must come before Run 2 in the file (chronological order).
        $this->assertLessThan(strpos($contents, 'Run 2'), strpos($contents, 'Run 1'));
    }

    public function test_files_over_5mb_get_rotated_to_dot1(): void
    {
        // Write a 6MB-sized file directly so we don't have to spam writes.
        @mkdir(dirname($this->logPath), 0755, true);
        file_put_contents($this->logPath, str_repeat('x', 6 * 1024 * 1024));

        $this->assertFileExists($this->logPath);
        $this->assertGreaterThan(5 * 1024 * 1024, filesize($this->logPath));

        LogRotator::prepare('test-rotator-unit.log', 'Run after rotation');

        // The fat file should now be at .1 and the live log should hold
        // only the new separator (a few hundred bytes).
        $this->assertFileExists($this->logPath.'.1');
        $this->assertGreaterThan(5 * 1024 * 1024, filesize($this->logPath.'.1'));
        $this->assertLessThan(1000, filesize($this->logPath));
        $this->assertStringContainsString('Run after rotation', file_get_contents($this->logPath));
    }
}

<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verify that every config setting defined in config/linkwise.php
 * is actually read somewhere in the codebase (excluding ServiceProvider).
 * Dead config = broken promise to the user.
 */
class ConfigEnforcementTest extends TestCase
{
    protected array $configKeys = [];

    protected string $srcDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->srcDir = dirname(__DIR__, 2).'/src';
        $this->configKeys = $this->extractConfigKeys();
    }

    public function test_every_config_key_is_used_in_source_code(): void
    {
        $unused = [];

        // Keys that are intentionally unused until a future sprint
        $deferred = [
            'ai',             // Sprint 16: AI feature (parent key)
            'ai.provider',    // Sprint 16: AI feature
            'ai.api_key',     // Sprint 16: AI feature
            'ai.model',       // Sprint 16: AI feature
            'broken_links',   // Parent key — sub-keys are checked
        ];

        foreach ($this->configKeys as $key) {
            if (in_array($key, $deferred, true)) {
                continue;
            }

            $found = $this->keyUsedInSource($key);
            if (! $found) {
                $unused[] = $key;
            }
        }

        $this->assertEmpty(
            $unused,
            'Config keys defined but never used in src/: '.implode(', ', $unused).
            "\nEither implement them or remove from config/linkwise.php"
        );
    }

    public function test_no_config_read_without_definition(): void
    {
        // Find all config('linkwise.*') calls in source
        $usedKeys = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->srcDir)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if (preg_match_all("/config\(['\"]linkwise\.([^'\"]+)['\"]/", $content, $matches)) {
                foreach ($matches[1] as $key) {
                    $usedKeys[$key] = $file->getPathname();
                }
            }
        }

        // Check ServiceProvider configOrDefault calls too
        if (preg_match_all("/configOrDefault\(['\"]linkwise\.([^'\"]+)['\"]/", file_get_contents($this->srcDir.'/Suggestions/SuggestionEngine.php'), $matches)) {
            foreach ($matches[1] as $key) {
                $usedKeys[$key] = 'SuggestionEngine.php';
            }
        }

        $phantom = [];
        foreach ($usedKeys as $key => $file) {
            if (! in_array($key, $this->configKeys, true)) {
                $phantom[] = "$key (used in ".basename($file).')';
            }
        }

        $this->assertEmpty(
            $phantom,
            'Config keys read in code but not defined in config/linkwise.php: '.implode(', ', $phantom)
        );
    }

    public function test_excluded_entries_respected_everywhere(): void
    {
        // excluded_entries must be checked in: EntryIndexer, BrokenLinkChecker, AutoLinkApplier, DomainReport
        $mustCheck = [
            'Indexer/EntryIndexer.php',
            'Links/BrokenLinkChecker.php',
            'AutoLink/AutoLinkApplier.php',
            'Reports/DomainReport.php',
        ];

        foreach ($mustCheck as $file) {
            $content = file_get_contents($this->srcDir.'/'.$file);
            $this->assertStringContainsString(
                'excluded_entries',
                $content,
                "$file must check linkwise.excluded_entries config"
            );
        }
    }

    public function test_excluded_collections_respected_everywhere(): void
    {
        $mustCheck = [
            'Indexer/EntryIndexer.php',
            'Links/BrokenLinkChecker.php',
            'Reports/DomainReport.php',
        ];

        foreach ($mustCheck as $file) {
            $content = file_get_contents($this->srcDir.'/'.$file);
            $this->assertStringContainsString(
                'excluded_collections',
                $content,
                "$file must check linkwise.excluded_collections config"
            );
        }
    }

    public function test_collections_filter_respected_in_indexer(): void
    {
        $content = file_get_contents($this->srcDir.'/Indexer/EntryIndexer.php');
        $this->assertStringContainsString('linkwise.collections', $content);
    }

    public function test_open_in_new_tab_respected_in_link_inserter(): void
    {
        $content = file_get_contents($this->srcDir.'/Support/BardLinkInserter.php');
        $this->assertStringContainsString('open_in_new_tab', $content);
    }

    // ─── Helpers ───────────────────────────────────────────────────

    protected function extractConfigKeys(): array
    {
        $configFile = dirname(__DIR__, 2).'/config/linkwise.php';
        $config = include $configFile;

        return $this->flattenKeys($config);
    }

    protected function flattenKeys(array $array, string $prefix = ''): array
    {
        $keys = [];
        foreach ($array as $key => $value) {
            $full = $prefix ? $prefix.'.'.$key : $key;
            $keys[] = $full;
            if (is_array($value) && ! array_is_list($value)) {
                $keys = array_merge($keys, $this->flattenKeys($value, $full));
            }
        }

        return $keys;
    }

    protected function keyUsedInSource(string $key): bool
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->srcDir)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains($file->getPathname(), 'ServiceProvider.php')) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            // Check for config('linkwise.key') or configOrDefault('linkwise.key')
            if (str_contains($content, "linkwise.$key")) {
                return true;
            }
            // Also check for just the key name in case of variable access
            if (str_contains($content, "'$key'")) {
                return true;
            }
        }

        return false;
    }
}

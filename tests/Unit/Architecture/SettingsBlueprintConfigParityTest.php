<?php

namespace Arturrossbach\Linkwise\Tests\Unit\Architecture;

use Arturrossbach\Linkwise\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Structural pin: every UI-exposed setting handle in
 * `resources/blueprints/settings.yaml` is connected end-to-end to the
 * config + consumer code.
 *
 * Three invariants:
 *   1. Each blueprint handle has a corresponding default in
 *      `config/linkwise.php`.
 *   2. Each blueprint handle appears in the configMap of
 *      `ServiceProvider::mergeAddonSettingsIntoConfig()` so user-saved
 *      values flow into `config('linkwise.<key>')`.
 *   3. Each handle is read at least once somewhere in `src/`.
 *
 * Without this pin, a future "I'll add a setting field" PR could ship
 * a blueprint handle that looks editable but is never persisted into
 * config, OR ship a config-default that's never wired into the
 * Statamic-Settings load path — both surfaced silently to users as
 * "I saved this but nothing happened".
 *
 * Pattern parity with the other source-grep architecture tests
 * (BulkCommandSkipRecordParityTest, TerminalStatusErrorsFieldParityTest,
 * etc.) — O(1) maintenance, catches the drift class directly.
 */
class SettingsBlueprintConfigParityTest extends TestCase
{
    /**
     * Handles that are intentionally NOT wired through the full
     * pipeline. Add an entry only with a justification.
     *
     * Today: empty — all 18 visible settings honour the contract.
     */
    private const EXEMPT_HANDLES = [
        // 'some_handle' => 'Justification: ...',
    ];

    public function test_every_blueprint_handle_has_config_default(): void
    {
        $handles = $this->blueprintHandles();
        $configSrc = file_get_contents(__DIR__.'/../../../config/linkwise.php');
        $this->assertNotFalse($configSrc);

        $gaps = [];
        foreach ($handles as $handle) {
            if (array_key_exists($handle, self::EXEMPT_HANDLES)) {
                continue;
            }
            // Match `'<handle>' => ...` or `"<handle>" => ...` at top-
            // level config-array indentation.
            $pattern = "/['\"]{$handle}['\"]\\s*=>/";
            if (! preg_match($pattern, $configSrc)) {
                $gaps[] = $handle;
            }
        }

        $this->assertEmpty(
            $gaps,
            'Blueprint handles without `config/linkwise.php` defaults: '
            .implode(', ', $gaps)
            .'. UI would show editable fields with no documented default — '
            .'add the key to config/linkwise.php or to EXEMPT_HANDLES.',
        );
    }

    public function test_every_blueprint_handle_is_in_service_provider_config_map(): void
    {
        $handles = $this->blueprintHandles();
        $providerSrc = file_get_contents(__DIR__.'/../../../src/ServiceProvider.php');
        $this->assertNotFalse($providerSrc);

        // Extract the configMap block. Loose match — looks for
        // 'handle' => 'configKey' entries inside the
        // mergeAddonSettingsIntoConfig method. We scope to "between
        // the method's opening brace and the next method declaration"
        // by finding the next `protected|public|private function`
        // (anonymous closures use bare `function (` and don't trip this).
        $startMarker = strpos($providerSrc, 'mergeAddonSettingsIntoConfig');
        $this->assertNotFalse($startMarker, 'mergeAddonSettingsIntoConfig method not found in ServiceProvider');
        $tail = substr($providerSrc, $startMarker);
        if (preg_match('/^(.*?)\n\s+(?:protected|public|private)\s+function\s+\w+/sU', $tail, $m)) {
            $methodBody = $m[1];
        } else {
            $methodBody = $tail;
        }

        $gaps = [];
        foreach ($handles as $handle) {
            if (array_key_exists($handle, self::EXEMPT_HANDLES)) {
                continue;
            }
            // Match `'<handle>' =>` in the method body (= a configMap entry).
            $pattern = "/['\"]{$handle}['\"]\\s*=>\\s*['\"]/";
            if (! preg_match($pattern, $methodBody)) {
                $gaps[] = $handle;
            }
        }

        $this->assertEmpty(
            $gaps,
            'Blueprint handles missing from ServiceProvider::mergeAddonSettingsIntoConfig configMap: '
            .implode(', ', $gaps)
            .'. User-saved Settings UI values would NOT flow into '
            ."config('linkwise.<key>') — `Settings: <none>` saved without effect.",
        );
    }

    public function test_every_blueprint_handle_is_read_by_at_least_one_src_file(): void
    {
        $handles = $this->blueprintHandles();
        $srcDir = realpath(__DIR__.'/../../../src');
        $this->assertDirectoryExists($srcDir);

        $allSrc = '';
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $allSrc .= "\n".file_get_contents($f->getPathname());
            }
        }

        $gaps = [];
        foreach ($handles as $handle) {
            if (array_key_exists($handle, self::EXEMPT_HANDLES)) {
                continue;
            }
            // Match any reader form for `linkwise.<handle>`:
            //   - `config('linkwise.X'` / `config("linkwise.X"`
            //   - `configOrDefault('linkwise.X'` / `getConfigArray('linkwise.X'`
            //     (helper-method wrappers used in SuggestionEngine et al.)
            //   - any future helper that takes the dotted-config key as
            //     a string literal
            $pattern = "/['\"]linkwise\\.{$handle}\\b/";
            if (! preg_match($pattern, $allSrc)) {
                $gaps[] = $handle;
            }
        }

        $this->assertEmpty(
            $gaps,
            'Blueprint handles with no `config(\'linkwise.<key>\')` read in src/: '
            .implode(', ', $gaps)
            .'. UI exposes a setting that no code consumes — orphaned field. '
            .'Add a reader or remove the blueprint entry / add to EXEMPT_HANDLES.',
        );
    }

    /**
     * @return list<string>
     */
    private function blueprintHandles(): array
    {
        $blueprintPath = __DIR__.'/../../../resources/blueprints/settings.yaml';
        $data = Yaml::parseFile($blueprintPath);

        $handles = [];
        foreach ($data['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (! empty($field['handle'])) {
                    $handles[] = $field['handle'];
                }
            }
        }

        sort($handles);

        return $handles;
    }
}

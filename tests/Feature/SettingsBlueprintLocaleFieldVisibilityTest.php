<?php

namespace Arturrossbach\Linkwise\Tests\Feature;

use Arturrossbach\Linkwise\Support\LocaleFilterPresenter;
use Arturrossbach\Linkwise\Tests\TestCase;
use Statamic\Facades\Site;
use Symfony\Component\Yaml\Yaml;

/**
 * V1.2 settings-UX pin: on installs with ≥2 distinct `lang:` values in
 * sites.yaml, the "Single-site content language" field is dead config
 * (per-site `lang:` is authoritative) and should not surface in the
 * Settings UI. ServiceProvider::bootAddon overrides the addon's
 * settings_blueprint container binding to run the parsed YAML through
 * LocaleFilterPresenter::stripLanguageFieldIfMultilingual.
 *
 * Failure-mode this guards against:
 *
 *  - someone reverts the helper → field reappears on multilingual
 *    installs and confuses editors with "should I set this?".
 *  - the YAML field handle is renamed → strip becomes a no-op silently.
 *    Test catches that because it loads the real YAML file.
 *
 * Three scenarios cover the decision matrix:
 *
 *  - single-site                            → field visible
 *  - multisite same-lang (geo/SEO multi-domain) → field visible
 *  - multisite different-lang               → field hidden
 *
 * Plus a separate trio of isMultilingualBySites() unit pins covering
 * the detection logic in isolation.
 *
 * Why this is a Feature test (not Unit): Site::setSites() requires the
 * full Statamic ServiceProvider boot — Site::all() crashes on Unit-only
 * harnesses that don't register the Sites facade.
 */
class SettingsBlueprintLocaleFieldVisibilityTest extends TestCase
{
    private function loadSettingsBlueprintArray(): array
    {
        // Mirror Statamic's own bootSettingsBlueprint() loader (it goes
        // through Yaml::file()->parse()). We bypass the container binding
        // because Orchestra Testbench doesn't run Statamic's addon-
        // discovery, so the binding isn't registered — but the real
        // production path still reads from THIS yaml file.
        return Yaml::parseFile(__DIR__.'/../../resources/blueprints/settings.yaml');
    }

    private function generalFieldHandles(array $blueprint): array
    {
        $fields = $blueprint['sections']['general']['fields'] ?? [];

        return array_map(fn ($f) => $f['handle'] ?? null, $fields);
    }

    public function test_is_multilingual_by_sites_false_when_only_one_site(): void
    {
        Site::setSites([
            'default' => ['name' => 'EN', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
        ]);

        $this->assertFalse(LocaleFilterPresenter::isMultilingualBySites());
    }

    public function test_is_multilingual_by_sites_false_when_multisite_same_lang(): void
    {
        // 5 EN-only domains for geo/SEO is multisite but NOT multilingual.
        // Distinct lang count is 1 — the global content-language fallback
        // is still legitimately useful.
        Site::setSites([
            'us' => ['name' => 'US', 'url' => 'http://us.example/', 'locale' => 'en_US', 'lang' => 'en'],
            'uk' => ['name' => 'UK', 'url' => 'http://uk.example/', 'locale' => 'en_GB', 'lang' => 'en'],
            'au' => ['name' => 'AU', 'url' => 'http://au.example/', 'locale' => 'en_AU', 'lang' => 'en'],
        ]);

        $this->assertFalse(LocaleFilterPresenter::isMultilingualBySites());
    }

    public function test_is_multilingual_by_sites_true_when_distinct_langs(): void
    {
        Site::setSites([
            'default' => ['name' => 'EN', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
            'de' => ['name' => 'DE', 'url' => 'http://localhost/de/', 'locale' => 'de_DE', 'lang' => 'de'],
        ]);

        $this->assertTrue(LocaleFilterPresenter::isMultilingualBySites());
    }

    public function test_language_field_visible_on_single_site_install(): void
    {
        Site::setSites([
            'default' => ['name' => 'EN', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
        ]);

        $blueprint = LocaleFilterPresenter::stripLanguageFieldIfMultilingual($this->loadSettingsBlueprintArray());

        $this->assertContains('language', $this->generalFieldHandles($blueprint), 'Single-site install must expose the content-language field.');
    }

    public function test_language_field_visible_on_same_lang_multisite(): void
    {
        Site::setSites([
            'us' => ['name' => 'US', 'url' => 'http://us.example/', 'locale' => 'en_US', 'lang' => 'en'],
            'uk' => ['name' => 'UK', 'url' => 'http://uk.example/', 'locale' => 'en_GB', 'lang' => 'en'],
        ]);

        $blueprint = LocaleFilterPresenter::stripLanguageFieldIfMultilingual($this->loadSettingsBlueprintArray());

        $this->assertContains('language', $this->generalFieldHandles($blueprint), 'Same-lang multisite must keep the content-language field — only one distinct language declared.');
    }

    public function test_language_field_hidden_on_multilingual_install(): void
    {
        Site::setSites([
            'default' => ['name' => 'EN', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
            'de' => ['name' => 'DE', 'url' => 'http://localhost/de/', 'locale' => 'de_DE', 'lang' => 'de'],
        ]);

        $blueprint = LocaleFilterPresenter::stripLanguageFieldIfMultilingual($this->loadSettingsBlueprintArray());
        $handles = $this->generalFieldHandles($blueprint);

        $this->assertNotContains('language', $handles, 'Multilingual install must NOT expose the dead-config content-language field.');

        // Sibling fields must remain — we strip exactly one, not the section.
        $this->assertContains('collections', $handles);
        $this->assertContains('target_collections', $handles);
        $this->assertContains('entry_status', $handles);
        $this->assertContains('max_suggestions', $handles);
        $this->assertContains('open_in_new_tab', $handles);
    }
}

<?php

namespace Inkline\Linkwise;

use Inkline\Linkwise\Commands\ApplyRuleCommand;
use Inkline\Linkwise\Commands\BulkUnlinkCommand;
use Inkline\Linkwise\Commands\CheckLinksCommand;
use Inkline\Linkwise\Commands\DetailUnlinkCommand;
use Inkline\Linkwise\Commands\IndexCommand;
use Inkline\Linkwise\Commands\SeedTestDataCommand;
use Inkline\Linkwise\Commands\UrlChangerApplyCommand;
use Inkline\Linkwise\Links\LinkwiseLinkMark;
use Statamic\Fieldtypes\Bard\Augmentor;
use Inkline\Linkwise\Subscribers\AutoLinkOnEntrySaveSubscriber;
use Inkline\Linkwise\Subscribers\EntryBlueprintSubscriber;
use Inkline\Linkwise\Subscribers\EntryIndexSubscriber;
use Statamic\Facades\Addon;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'linkwise';

    protected $commands = [
        IndexCommand::class,
        CheckLinksCommand::class,
        BulkUnlinkCommand::class,
        ApplyRuleCommand::class,
        UrlChangerApplyCommand::class,
        DetailUnlinkCommand::class,
        SeedTestDataCommand::class,
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $vite = [
        'input' => [
            'resources/js/addon.js',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $subscribe = [
        EntryBlueprintSubscriber::class,
        EntryIndexSubscriber::class,
        AutoLinkOnEntrySaveSubscriber::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/linkwise.php', 'linkwise');
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__.'/../config/linkwise.php' => config_path('linkwise.php'),
        ], 'linkwise-config');

        // Merge addon settings (from CP UI) into the config, so all
        // existing config() calls automatically respect user settings.
        $this->app->booted(function () {
            $this->mergeAddonSettingsIntoConfig();
        });

        // Replace Bard's LinkMark with our version that applies domain rel attributes
        Augmentor::replaceExtension('link', function ($original) {
            return new LinkwiseLinkMark;
        });

        Permission::register('manage linkwise')
            ->label('Manage Linkwise');

        Nav::extend(function ($nav) {
            $nav->create('Linkwise')
                ->section('Tools')
                ->route('linkwise.dashboard')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>');
        });
    }

    /**
     * Merge addon settings saved via the CP Settings UI into the Laravel config.
     * This bridges Statamic's addon settings storage with our config()-based services.
     */
    protected function mergeAddonSettingsIntoConfig(): void
    {
        try {
            $addon = Addon::all()->first(fn ($a) => $a->id() === 'inkline/statamic-linkwise');

            if (! $addon) {
                return;
            }

            $settings = $addon->settings()->all();

            if (empty($settings)) {
                return;
            }

            // Map addon settings to config keys (only override non-null values)
            $configMap = [
                'collections' => 'collections',
                'target_collections' => 'target_collections',
                'entry_status' => 'entry_status',
                'max_suggestions' => 'max_suggestions',
                'open_in_new_tab' => 'open_in_new_tab',
                'min_phrase_words' => 'min_phrase_words',
                'min_score' => 'min_score',
                'max_keywords_per_entry' => 'max_keywords_per_entry',
                'min_keyword_score' => 'min_keyword_score',
                'prevent_two_way' => 'prevent_two_way',
                'excluded_entries' => 'excluded_entries',
                'excluded_collections' => 'excluded_collections',
                'title_blacklist' => 'title_blacklist',
                'orphaned_ignore' => 'orphaned_ignore',
                'ignored_links' => 'ignored_links',
                'custom_stopwords' => 'custom_stopwords',
                'language' => 'language',
                'auto_apply_on_save_enabled' => 'auto_apply_on_save_enabled',
            ];

            $intKeys = ['min_phrase_words', 'max_keywords_per_entry', 'max_suggestions'];
            $floatKeys = ['min_score', 'min_keyword_score'];
            $boolKeys = ['open_in_new_tab', 'prevent_two_way', 'auto_apply_on_save_enabled'];

            foreach ($configMap as $settingKey => $configKey) {
                if (! isset($settings[$settingKey])) {
                    continue;
                }

                $value = $settings[$settingKey];

                // Skip empty strings (unset toggles, empty textareas)
                if ($value === '' || $value === null) {
                    continue;
                }

                // Cast types with range validation
                if (in_array($configKey, $intKeys)) {
                    $value = max(1, (int) $value);
                } elseif (in_array($configKey, $floatKeys)) {
                    $value = max(0.0, min(1.0, (float) $value));
                } elseif (in_array($configKey, $boolKeys)) {
                    $value = (bool) $value;
                }

                config(["linkwise.{$configKey}" => $value]);
            }
        } catch (\Throwable) {
            // Settings not available yet (e.g. during install) — use defaults
        }
    }
}

<?php

namespace Arturrossbach\Linkwise;

use Arturrossbach\Linkwise\Commands\ApplyRuleCommand;
use Arturrossbach\Linkwise\Commands\AuditCommand;
use Arturrossbach\Linkwise\Commands\BulkUnlinkCommand;
use Arturrossbach\Linkwise\Commands\CheckLinksCommand;
use Arturrossbach\Linkwise\Commands\DetailUnlinkCommand;
use Arturrossbach\Linkwise\Commands\IndexCommand;
use Arturrossbach\Linkwise\Commands\LinkInsertCommand;
use Arturrossbach\Linkwise\Commands\SeedTestDataCommand;
use Arturrossbach\Linkwise\Commands\UrlChangerApplyCommand;
use Arturrossbach\Linkwise\Links\LinkwiseLinkMark;
use Statamic\Fieldtypes\Bard\Augmentor;
use Arturrossbach\Linkwise\Subscribers\AutoLinkOnEntrySaveSubscriber;
use Arturrossbach\Linkwise\Subscribers\EntryBlueprintSubscriber;
use Arturrossbach\Linkwise\Subscribers\EntryIndexSubscriber;
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
        LinkInsertCommand::class,
        SeedTestDataCommand::class,
        AuditCommand::class,
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

        // Dev-mode-only: ship a "BARD" badge per entry-row in CP tables so
        // the developer can visually pick Bard entries vs Markdown ones
        // when testing field-type-specific code paths. Production users
        // never see this — branch is gated on app()->environment('local').
        // Computed once per request via Inertia::share so the 8 endpoint
        // controllers don't need to thread the prop individually.
        \Inertia\Inertia::share('linkwise', function () {
            // Opt-in via LINKWISE_DEV=true (NOT app()->environment('local')) —
            // a customer site running locally during their dev cycle MUST NOT
            // see the BARD badge. Only the addon developer sets the env var.
            $devMode = (bool) config('linkwise.dev_mode', false);
            return [
                'dev_mode' => $devMode,
                'bard_entry_ids' => $devMode ? $this->detectBardEntryIds() : [],
            ];
        });

        // Merge addon settings (from CP UI) into the config, so all
        // existing config() calls automatically respect user settings.
        $this->app->booted(function () {
            $this->mergeAddonSettingsIntoConfig();
        });

        // Log ValidationException on Linkwise routes. Laravel's default
        // exception reporter EXCLUDES ValidationException (it's a "user
        // error" not an "app error"), so the user sees a generic red toast
        // ("the given data was invalid") with no server-side trace. For a
        // commercial addon that's a support nightmare — every failed
        // validation should leave a breadcrumb so the diagnostic-ZIP can
        // tell us which fields rejected and what payload shape was sent.
        // Use renderable() not reportable(): Laravel's default report()
        // shortcircuits ValidationException via the dontReport array,
        // so reportable callbacks never fire for it. renderable() fires
        // for ALL exceptions during HTTP rendering — we use it as a
        // logging hook and return null to let the default 422 response
        // continue unmodified.
        $this->app->afterResolving(\Illuminate\Contracts\Debug\ExceptionHandler::class, function ($handler) {
            if (! method_exists($handler, 'renderable')) {
                return;
            }
            $handler->renderable(function (\Illuminate\Validation\ValidationException $e, $request) {
                try {
                    if (! $request) {
                        return null;
                    }
                    $path = (string) $request->path();
                    if (! str_contains($path, 'linkwise')) {
                        return null;
                    }
                    \Log::warning('[Linkwise] Validation failed on '.$path, [
                        'method' => $request->method(),
                        'errors' => $e->errors(),
                        'request_keys' => array_keys($request->all()),
                        'insertion_count' => is_array($request->input('insertions'))
                            ? count($request->input('insertions'))
                            : null,
                        'replacement_count' => is_array($request->input('replacements'))
                            ? count($request->input('replacements'))
                            : null,
                    ]);
                } catch (\Throwable) {
                    // Never let logging itself break the response — keep
                    // silent on resolver errors during boot / tests.
                }

                // Return null so Laravel's default ValidationException
                // renderer still produces the 422 with field errors.
                return null;
            });
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
            $addon = Addon::all()->first(fn ($a) => $a->id() === 'arturrossbach/linkwise');

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

    /**
     * Dev-mode helper for the BARD badge in CP tables. Returns the entry IDs
     * whose blueprint contains a Bard or Replicator field — i.e. the entries
     * where Linkwise's Bard / nested-Bard write paths get exercised.
     *
     * Computed on the fly per request. Acceptable cost: dev-mode-only,
     * Statamic's blueprint cache makes the per-entry check cheap. NOT
     * cached across requests because adding/removing collections shouldn't
     * require a cache flush during local dev.
     *
     * @return list<string>
     */
    protected function detectBardEntryIds(): array
    {
        $ids = [];
        try {
            foreach (\Statamic\Facades\Entry::all() as $entry) {
                if ($this->entryHasActualBardContent($entry)) {
                    $ids[] = $entry->id();
                }
            }
        } catch (\Throwable) {
            return [];
        }
        return $ids;
    }

    /**
     * True if the entry actually carries Bard data — either a top-level
     * Bard field with content, or a Replicator field whose nested sets
     * contain a Bard fragment. Blueprint-only check (just looking at
     * field types) over-counts in setups like Peak where every entry has
     * a Replicator regardless of whether any set actually uses Bard.
     */
    protected function entryHasActualBardContent($entry): bool
    {
        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            return false;
        }
        foreach ($fields as $handle => $field) {
            $type = $field->type();
            $value = $entry->get($handle);
            if ($type === 'bard' && is_array($value) && ! empty($value)) {
                return true;
            }
            if ($type === 'replicator' && is_array($value) && ! empty($value)) {
                $found = false;
                \Arturrossbach\Linkwise\Support\EntryFieldWalker::walkReplicator(
                    $value,
                    function () use (&$found) { $found = true; },
                );
                if ($found) {
                    return true;
                }
            }
        }
        return false;
    }
}

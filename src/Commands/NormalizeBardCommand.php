<?php

namespace Arturrossbach\Linkwise\Commands;

use Arturrossbach\Linkwise\Support\BardWalker;
use Arturrossbach\Linkwise\Support\ProseMirrorTypes;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Arturrossbach\Linkwise\Support\UrlHelper;
use Illuminate\Console\Command;
use Statamic\Facades\Entry as EntryFacade;
use Throwable;

/**
 * Migration command: normalize Bard / Replicator-nested-Bard fields across
 * all entries by merging adjacent text nodes with identical mark-sets.
 *
 * Why this exists — Bug 16 (2026-05-11):
 * Linkwise's display path merged adjacent same-href text nodes into one
 * logical link; the write path (BardLinkInserter::linkAcrossNodes) left
 * them as separate marks. Statamic Bard does NOT normalize on save
 * (verified empirically), so over time entries accumulate fragmented
 * marks that silently break the Re-Link-after-anchor-edit flow.
 *
 * The save-time normalize hook in SafeEntrySaver (2026-05-11) prevents
 * NEW fragments — but pre-existing fragments only get cleaned when their
 * entry is saved by some other operation. This command is the explicit
 * cleanup channel: walk every entry, normalize, save only when the
 * normalization changed something.
 *
 * Usage:
 *   php artisan linkwise:normalize-bard            # apply
 *   php artisan linkwise:normalize-bard --dry-run  # report only
 *
 * Idempotent: a second run on the same content produces no further
 * changes (each entry's normalize is a no-op once the canonical form
 * is on disk).
 *
 * Why not via the AutoLinkOnEntrySaveSubscriber: that subscriber fires
 * on user-initiated saves only. For a one-shot cleanup of the existing
 * corpus we want explicit control, dry-run support, and a count of
 * entries actually touched. Plus: an explicit command is safer to
 * coordinate with backups during a real production run.
 */
class NormalizeBardCommand extends Command
{
    protected $signature = 'linkwise:normalize-bard
                            {--dry-run : Report what would change without saving}';

    protected $description = 'Normalize Bard fields: merge adjacent text nodes with identical mark-sets (Bug 16 cleanup)';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->info('Running in --dry-run mode (no writes).');
        } else {
            $this->info('Normalizing Bard fields across all entries…');
        }

        // Match the existing iteration pattern (see EntryIndexer:37-43)
        // for consistency. Query-then-get is the canonical Statamic call
        // and leaves room for later filters (e.g. only modified-after-X)
        // without restructuring the command.
        $entries = EntryFacade::query()->get();
        $total = $entries->count();
        $this->info("Scanning $total entries.");

        $scanned = 0;
        $needsChange = 0;
        $saved = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($entries as $entry) {
            $scanned++;
            $bar->advance();

            try {
                $changes = $this->collectChanges($entry);
                if (empty($changes)) {
                    continue;
                }

                $needsChange++;

                if ($isDryRun) {
                    continue;
                }

                // Apply changes via SafeEntrySaver::save — this path
                // re-runs normalize (idempotent) plus the full validator
                // chain plus the cascade-guard, keeping the migration
                // bound by the same safety rails as user-initiated saves.
                $hash = SafeEntrySaver::hash($entry);
                foreach ($changes as $handle => $newValue) {
                    $entry->set($handle, $newValue);
                }
                SafeEntrySaver::save($entry, $hash);
                $saved++;
            } catch (Throwable $e) {
                $errors++;
                // Don't abort the whole migration on a single entry error —
                // log and continue so the operator can address per-entry
                // issues afterwards. Same per-record-tolerance contract
                // the bulk commands follow.
                $bar->clear();
                $this->error('Entry '.$entry->id().' failed: '.$e->getMessage());
                $bar->display();
            }
        }

        $bar->finish();
        $this->newLine(2);

        // Summary always — gives the operator an actionable number whether
        // dry-run or applied. No info-level chatter for entries that didn't
        // need touching; the volume would drown the signal.
        $this->info("Scanned:        $scanned");
        $this->info("Need change:    $needsChange");
        if (! $isDryRun) {
            $this->info("Saved:          $saved");
            if ($errors > 0) {
                $this->error("Errors:         $errors");
            }
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Walk every Bard / Replicator-nested-Bard field on the entry and
     * return [handle => normalizedValue] for fields whose normalized
     * form differs from the current on-disk form.
     *
     * Empty return = entry already canonical, skip.
     *
     * @return array<string, mixed>
     */
    protected function collectChanges($entry): array
    {
        $changes = [];

        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (Throwable) {
            // No blueprint = nothing to scan. Same retreat the validators use.
            return [];
        }

        foreach ($fields as $handle => $field) {
            $type = $field->type();
            $value = $entry->get($handle);

            if ($type === 'bard' && is_array($value) && ! empty($value)) {
                $normalized = BardWalker::normalizeChildren($value);
                if ($normalized !== $value) {
                    $changes[$handle] = $normalized;
                }
            } elseif ($type === 'replicator' && is_array($value) && ! empty($value)) {
                $normalized = $this->normalizeReplicator($value);
                if ($normalized !== $value) {
                    $changes[$handle] = $normalized;
                }
            }
        }

        return $changes;
    }

    /**
     * Recurse into Replicator sets, normalize every nested Bard fragment.
     * Mirrors SafeEntrySaver::normalizeReplicatorBardFragments — the two
     * walkers are intentionally identical so the migration and the
     * runtime-save normalize-step apply the exact same transformation.
     */
    protected function normalizeReplicator(array $sets): array
    {
        foreach ($sets as $i => $set) {
            if (! is_array($set)) {
                continue;
            }
            foreach ($set as $key => $value) {
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true) || ! is_array($value) || empty($value)) {
                    continue;
                }
                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    $sets[$i][$key] = BardWalker::normalizeChildren($value);
                } elseif (isset($value[0]['type'], $value[0]['id'])) {
                    $sets[$i][$key] = $this->normalizeReplicator($value);
                }
            }
        }
        return $sets;
    }
}

<?php

namespace Arturrossbach\Linkwise\Commands;

use Arturrossbach\Linkwise\NLP\Stemmer;
use Illuminate\Console\Command;

/**
 * Build the frequency-stems JSON files that feed Stopwords::forLanguage().
 *
 * Input: raw FrequencyWords files (e.g. _raw_de_50k.txt, _raw_en_50k.txt)
 * from `hermitdave/FrequencyWords` (MIT). Format is "word frequency"
 * per line, sorted by frequency descending.
 *
 * Process: take the top-N words, run each through the Snowball stemmer
 * for that language, dedupe, write as a JSON string-array of stems.
 *
 * Output: resources/data/frequency-stems-{code}.json
 *
 * Why stems, not surface forms: a 5000-stem set covers ~15-25k surface
 * forms via inflection (vernachlässigt + vernachlässigte + vernachlässigen
 * → one stem). Surface-form matching would miss those.
 *
 * Why Top-50000 (user-decided 2026-05-22 round 2 after Top-10k smoke):
 *
 * Top-10000 was a compromise to avoid `mikrofon` (rank 11167) being
 * a false-positive. The smoke run revealed it was too sanft — words
 * like `geröstet` (rank 30367), `vernachlässigt` (12568) rutsch
 * durch and surface as auto-detected keywords for non-cooking and
 * non-care-related entries (e.g. "Foundry Hex-Multitool", "Apex
 * Bergsteiger-Klettergurt"). Empirically this is the dominant junk
 * the user wants gone.
 *
 * The middle-band concern (`mikrofon`, `suchmaschine`, `notebook`
 * being legitimate domain words at low-frequency rank) is solved by
 * Title-Protect in the KeywordExtractor: a stem in the entry's title
 * bypasses the filter. So a "Mikrofon-Test" post still gets `mikrofon`
 * as a keyword; a non-audio post that mentions `mikrofon` in passing
 * does not.
 *
 * 50k surface forms collapse via stemming to ~22-40k unique stems
 * per language (~300-600 KB JSON). Loaded lazy per active language.
 *
 * Residual escape valves the user has if a legitimate body-only
 * mid-frequency word gets filtered without title-protect:
 *   - Custom Target Keywords per entry (whitelist)
 *   - Ignored Suggestions (pair-specific kill)
 */
class BuildFrequencyStemsCommand extends Command
{
    protected $signature = 'linkwise:build-frequency-stems
        {language : ISO code (e.g. de, en)}
        {--top=50000 : How many top-frequency surface forms to include before stemming-dedup}
        {--input= : Path to raw FrequencyWords .txt (defaults to resources/data/_raw_{lang}_50k.txt)}';

    protected $description = 'Generate frequency-stems-{lang}.json from a FrequencyWords raw .txt file';

    public function handle(): int
    {
        $lang = (string) $this->argument('language');
        $top = (int) $this->option('top');
        $input = (string) ($this->option('input') ?? base_path("vendor/arturrossbach/statamic-linkwise/resources/data/_raw_{$lang}_50k.txt"));

        // Allow relative-to-package path during dev (no vendor dir yet).
        if (! file_exists($input)) {
            $input = __DIR__."/../../resources/data/_raw_{$lang}_50k.txt";
        }

        if (! file_exists($input)) {
            $this->error("Input file not found: {$input}");
            return 1;
        }

        $this->info("Reading top {$top} from {$input}");

        $lines = file($input, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_slice($lines, 0, $top);

        $stemmer = new Stemmer($lang);
        $stems = [];

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (! is_array($parts) || count($parts) < 1) {
                continue;
            }
            $word = mb_strtolower($parts[0]);
            if ($word === '' || mb_strlen($word) < 2) {
                continue;
            }
            $stems[$stemmer->stem($word)] = true;
        }

        $stemList = array_keys($stems);
        sort($stemList);

        $outPath = __DIR__."/../../resources/data/frequency-stems-{$lang}.json";
        file_put_contents($outPath, json_encode($stemList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info(sprintf(
            'Wrote %d unique stems from %d surface forms to %s',
            count($stemList),
            count($lines),
            $outPath,
        ));

        return 0;
    }
}

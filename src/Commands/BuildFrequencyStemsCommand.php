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
 * Why Top-10000 (user-decided 2026-05-22 after smoke + data analysis):
 *
 * Empirical run against the user's 39 reported junk words ("richtige",
 * "funktioniert", "vernachlässigten", ...) at different thresholds:
 *
 *     Top-3000  ->  27/39 (69%) caught, no domain false-positives
 *     Top-10000 ->  33/39 (85%) caught, no domain false-positives
 *     Top-15000 ->  36/39 (92%) caught, first false-positive: `mikrofon`
 *     Top-50000 ->  37/39 (97%) caught, false-positives: `mikrofon`,
 *                   `suchmaschine`, `notebook`
 *
 * Top-10000 is the empirical sweet spot — first threshold where
 * common-language coverage stops climbing as fast as middle-band
 * domain vocabulary starts getting hit. Words like `mikrofon`
 * (rank 11167), `suchmaschine` (47934), `notebook` (49078) survive,
 * so an editor's audio / SEO / hardware blog still gets meaningful
 * keywords from those tokens.
 *
 * Title-Protect on top of this catches the cases where a Top-10000
 * word IS a legitimate keyword for a specific entry (`Rezept` in a
 * cooking post's title bypasses the filter). For body-only domain
 * words that happen to be mid-frequency without title support, the
 * user has Custom Stopwords (additive removal — wait, no, additive
 * means they extend filtering, not protect words) → use Custom
 * Target Keywords per entry to whitelist them. The residual 6 junk
 * words (vernachlässigten, konkreter, reduziert, ...) can be added
 * via Custom Stopwords, which after the 2026-05-22 stem-first
 * rewrite catch all inflected forms automatically.
 */
class BuildFrequencyStemsCommand extends Command
{
    protected $signature = 'linkwise:build-frequency-stems
        {language : ISO code (e.g. de, en)}
        {--top=10000 : How many top-frequency surface forms to include before stemming-dedup}
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

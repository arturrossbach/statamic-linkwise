<?php

namespace Arturrossbach\Linkwise\NLP;

use Wamania\Snowball\StemmerFactory;

class Stemmer
{
    protected ?object $english = null;

    protected ?object $german = null;

    protected string $language;

    public function __construct(?string $language = null)
    {
        $this->language = $language ?? 'en_de';
    }

    /**
     * Stem a single word based on the configured language.
     * Returns the stemmed form, or the original if stemming fails.
     */
    public function stem(string $word): string
    {
        if (mb_strlen($word) < 3) {
            return $word;
        }

        try {
            return match ($this->language) {
                'en' => $this->stemEnglish($word),
                'de' => $this->stemGerman($word),
                'en_de' => $this->stemBilingual($word),
                default => $word,
            };
        } catch (\Throwable) {
            return $word;
        }
    }

    /**
     * Stem an array of words.
     *
     * @param  string[]  $words
     * @return string[]
     */
    public function stemAll(array $words): array
    {
        return array_map(fn (string $w) => $this->stem($w), $words);
    }

    protected function stemEnglish(string $word): string
    {
        if (! $this->english) {
            $this->english = StemmerFactory::create('en');
        }

        return $this->english->stem($word);
    }

    protected function stemGerman(string $word): string
    {
        if (! $this->german) {
            $this->german = StemmerFactory::create('de');
        }

        return $this->german->stem($word);
    }

    /**
     * For bilingual content: use English stemming.
     * German Snowball stemmer is more aggressive and can over-stem English words.
     */
    protected function stemBilingual(string $word): string
    {
        return $this->stemEnglish($word);
    }
}

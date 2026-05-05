<?php

namespace Arturrossbach\Linkwise\NLP;

class Stopwords
{
    /**
     * Get stopwords based on the configured language + custom stopwords.
     *
     * @return string[]
     */
    public static function forConfig(): array
    {
        try {
            $language = config('linkwise.language', 'en_de');
            $customRaw = config('linkwise.custom_stopwords', '');
        } catch (\Throwable) {
            $language = 'en_de';
            $customRaw = '';
        }

        $stopwords = match ($language) {
            'en' => static::english(),
            'de' => static::german(),
            default => static::default(),
        };

        // Add custom stopwords from settings
        if (is_string($customRaw) && trim($customRaw) !== '') {
            $custom = array_filter(
                array_map('trim', explode("\n", mb_strtolower($customRaw))),
                fn ($w) => $w !== '',
            );
            $stopwords = array_merge($stopwords, $custom);
        }

        return array_values(array_unique($stopwords));
    }

    /**
     * Get the default stopword list (English + German).
     *
     * @return string[]
     */
    public static function default(): array
    {
        return array_merge(static::english(), static::german());
    }

    /**
     * ISO standard English stopwords (stopwords-iso/stopwords-en) + domain extensions.
     * Source: https://github.com/stopwords-iso/stopwords-en
     *
     * @return string[]
     */
    public static function english(): array
    {
        return [
            // --- Pronouns ---
            'i', 'me', 'my', 'myself', 'mine',
            'you', 'your', 'yours', 'yourself', 'yourselves',
            'he', 'him', 'his', 'himself', 'she', 'her', 'hers', 'herself',
            'it', 'its', 'itself', 'we', 'us', 'our', 'ours', 'ourselves',
            'they', 'them', 'their', 'theirs', 'themselves',
            'who', 'whom', 'whose', 'which', 'what', 'that', 'this', 'these', 'those',
            'somebody', 'someone', 'something', 'anybody', 'anyone', 'anything',
            'everybody', 'everyone', 'everything', 'nobody', 'nothing', 'none',

            // --- Articles, determiners, quantifiers ---
            'a', 'an', 'the', 'some', 'any', 'no', 'every', 'each', 'all',
            'both', 'few', 'fewer', 'more', 'most', 'much', 'many', 'several',
            'other', 'another', 'such', 'own', 'same', 'half',

            // --- Be / have / do / modals ---
            'am', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
            'have', 'has', 'had', 'having',
            'do', 'does', 'did', 'doing', 'done',
            'will', 'would', 'shall', 'should', 'may', 'might',
            'can', 'could', 'must', 'need', 'ought', 'dare',

            // --- Contractions ---
            "don't", "doesn't", "didn't", "won't", "wouldn't", "shouldn't",
            "can't", "couldn't", "isn't", "aren't", "wasn't", "weren't",
            "hasn't", "haven't", "hadn't", "mustn't", "needn't", "shan't",
            "ain't", "let's", "i'm", "i've", "i'd", "i'll",
            "you're", "you've", "you'd", "you'll",
            "he's", "he'd", "he'll", "she's", "she'd", "she'll",
            "it's", "it'd", "it'll", "we're", "we've", "we'd", "we'll",
            "they're", "they've", "they'd", "they'll",
            "that's", "that'll", "who's", "who'd", "who'll",
            "what's", "what'll", "where's", "there's", "here's",
            "how's", "why's", "when's",

            // --- Prepositions ---
            'about', 'above', 'across', 'after', 'against', 'along', 'amid',
            'among', 'around', 'as', 'at', 'before', 'behind', 'below',
            'beneath', 'beside', 'besides', 'between', 'beyond', 'by',
            'despite', 'down', 'during', 'except', 'for', 'from', 'in',
            'inside', 'into', 'like', 'near', 'of', 'off', 'on', 'onto',
            'opposite', 'out', 'outside', 'over', 'past', 'per', 'plus',
            'round', 'since', 'than', 'through', 'throughout', 'to',
            'toward', 'towards', 'under', 'underneath', 'unlike', 'until',
            'up', 'upon', 'via', 'with', 'within', 'without',

            // --- Conjunctions & connectors ---
            'and', 'but', 'or', 'nor', 'for', 'yet', 'so',
            'if', 'then', 'else', 'when', 'while', 'although', 'because',
            'since', 'unless', 'until', 'whether', 'though', 'whereas',
            'wherever', 'whenever', 'whoever', 'whatever', 'whichever',
            'however', 'therefore', 'thus', 'hence', 'consequently',
            'furthermore', 'moreover', 'nevertheless', 'nonetheless',
            'meanwhile', 'otherwise', 'accordingly', 'instead',
            'notwithstanding', 'provided', 'insofar',

            // --- Adverbs (no topical value) ---
            'not', 'no', 'yes', 'only', 'just', 'also', 'too', 'very',
            'really', 'quite', 'rather', 'enough', 'almost', 'already',
            'always', 'never', 'ever', 'often', 'sometimes', 'usually',
            'still', 'again', 'further', 'once', 'twice', 'here', 'there',
            'now', 'then', 'where', 'when', 'why', 'how',
            'perhaps', 'probably', 'certainly', 'definitely', 'exactly',
            'actually', 'basically', 'generally', 'especially', 'particularly',
            'specifically', 'simply', 'merely', 'entirely', 'completely',
            'absolutely', 'apparently', 'obviously', 'clearly', 'directly',
            'effectively', 'essentially', 'frequently', 'hardly', 'highly',
            'increasingly', 'initially', 'largely', 'mainly', 'mostly',
            'naturally', 'necessarily', 'normally', 'notably', 'partly',
            'potentially', 'previously', 'primarily', 'recently', 'relatively',
            'significantly', 'slightly', 'somewhat', 'strongly', 'substantially',
            'successfully', 'suddenly', 'surely', 'typically', 'ultimately',
            'widely', 'recently', 'currently', 'approximately', 'respectively',
            'elsewhere', 'everywhere', 'somewhere', 'nowhere', 'anyway',
            'somehow', 'together', 'apart', 'aside', 'away', 'back',
            'forth', 'forward', 'ahead', 'even', 'well', 'much',

            // --- Generic adjectives (no topical value) ---
            'good', 'great', 'best', 'better', 'bad', 'worse', 'worst',
            'new', 'old', 'big', 'small', 'large', 'little', 'long', 'short',
            'high', 'low', 'right', 'left', 'first', 'last', 'next', 'early',
            'late', 'young', 'different', 'important', 'possible', 'available',
            'able', 'sure', 'real', 'true', 'full', 'whole', 'clear', 'easy',
            'hard', 'simple', 'common', 'specific', 'certain', 'likely',
            'unlikely', 'major', 'minor', 'similar', 'various', 'recent',
            'previous', 'current', 'general', 'particular', 'significant',
            'necessary', 'effective', 'appropriate', 'useful', 'interesting',
            'main', 'basic', 'free', 'open', 'close', 'ready', 'proper',
            // Comparatives & superlatives
            'easier', 'harder', 'faster', 'slower', 'bigger', 'smaller',
            'higher', 'lower', 'longer', 'shorter', 'newer', 'older',
            'simpler', 'stronger', 'weaker', 'closer', 'wider', 'deeper',
            'largest', 'smallest', 'highest', 'lowest', 'greatest',

            // --- Generic verbs (no topical value) ---
            'get', 'got', 'gotten', 'go', 'went', 'gone', 'going',
            'come', 'came', 'take', 'took', 'taken', 'make', 'made',
            'give', 'gave', 'given', 'find', 'found', 'know', 'knew', 'known',
            'think', 'thought', 'see', 'saw', 'seen', 'say', 'said',
            'tell', 'told', 'ask', 'asked', 'try', 'tried',
            'use', 'used', 'using', 'want', 'wanted', 'look', 'looked',
            'show', 'showed', 'shown', 'help', 'helped',
            'keep', 'kept', 'put', 'set', 'run', 'ran',
            'turn', 'turned', 'move', 'moved', 'start', 'started',
            'let', 'call', 'called', 'leave', 'left',
            'bring', 'brought', 'begin', 'began', 'begun',
            'seem', 'seemed', 'feel', 'felt', 'become', 'became',
            'mean', 'meant', 'hold', 'held', 'stand', 'stood',
            'happen', 'happened', 'follow', 'followed',
            'lead', 'led', 'allow', 'allowed',
            'add', 'added', 'change', 'changed', 'include', 'included',
            'continue', 'continued', 'provide', 'provided', 'create', 'created',
            'consider', 'considered', 'require', 'required',
            'read', 'write', 'written', 'wrote', 'send', 'sent',
            'receive', 'received', 'play', 'played', 'live', 'lived',
            'believe', 'believed', 'exist', 'existed',
            'expect', 'expected', 'offer', 'offered',
            'remember', 'understand', 'understood',

            // --- Number words ---
            'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight',
            'nine', 'ten', 'eleven', 'twelve', 'twenty', 'thirty', 'forty',
            'fifty', 'sixty', 'seventy', 'eighty', 'ninety',
            'hundred', 'thousand', 'million', 'billion', 'zero',

            // --- Filler nouns (no topical value) ---
            'thing', 'things', 'way', 'ways', 'time', 'times',
            'year', 'years', 'day', 'days', 'week', 'weeks', 'month', 'months',
            'point', 'part', 'parts', 'place', 'places',
            'case', 'cases', 'fact', 'facts', 'end', 'kind', 'lot', 'lots',
            'number', 'numbers', 'example', 'examples', 'area', 'areas',
            'world', 'life', 'work', 'works', 'result', 'results',
            'group', 'groups', 'problem', 'problems', 'state', 'states',
            'side', 'sides', 'line', 'lines', 'level', 'levels',
            'order', 'form', 'person', 'people', 'man', 'woman', 'child',
            'hand', 'home', 'room', 'word', 'words',
        ];
    }

    /**
     * @return string[]
     */
    public static function german(): array
    {
        return [
            // Articles, pronouns, prepositions, auxiliaries
            'der', 'die', 'das', 'ein', 'eine', 'einer', 'eines', 'einem', 'einen',
            'und', 'oder', 'aber', 'ist', 'sind', 'war', 'waren', 'wird', 'werden',
            'hat', 'haben', 'hatte', 'hatten', 'nicht', 'auch', 'noch', 'schon',
            'nur', 'dann', 'wenn', 'als', 'wie', 'mit', 'von', 'auf', 'aus',
            'bei', 'nach', 'vor', 'zu', 'zum', 'zur', 'im', 'am', 'um',
            'den', 'dem', 'des', 'er', 'sie', 'es', 'wir', 'ihr',
            // Common prepositions previously missing — German content uses
            // these as connectives, they should never end up as keyword
            // candidates or anchor seeds.
            'für', 'durch', 'gegen', 'ohne', 'während', 'seit', 'trotz',
            // Common modals and adjectives too generic for keywords
            'kann', 'muss', 'soll', 'will', 'darf', 'dass', 'mehr', 'viel',
            'sehr', 'gut', 'neue', 'neuen', 'neuer', 'neues', 'andere', 'anderen',
            'diese', 'dieser', 'dieses', 'diesem', 'diesen', 'jede', 'jeder',
            'jedes', 'jedem', 'jeden', 'keine', 'keiner', 'keines', 'keinem',
            'man', 'hier', 'dort', 'da', 'nun', 'doch', 'mal', 'ganz',
            'so', 'also', 'schon', 'noch', 'wieder', 'immer',
            // Both umlaut and ASCII transliteration: text normalize() keeps
            // unicode letters intact, so "über" stays "über" and used to
            // miss the stopword check entirely.
            'über', 'ueber',
        ];
    }
}

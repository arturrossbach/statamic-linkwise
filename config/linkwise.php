<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Language
    |--------------------------------------------------------------------------
    |
    | Primary content language. Determines which stopwords are used.
    | Options: 'en', 'de', 'en_de'
    |
    */
    'language' => 'en_de',

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    |
    | Which collections to index. Empty array = all collections.
    |
    */
    'collections' => [],

    /*
    |--------------------------------------------------------------------------
    | Target Collections
    |--------------------------------------------------------------------------
    |
    | Which collections suggestions should point TO. Empty = same as collections.
    |
    */
    'target_collections' => [],

    /*
    |--------------------------------------------------------------------------
    | Entry Status
    |--------------------------------------------------------------------------
    |
    | Which entries to include: 'published' (default) or 'all' (incl. drafts).
    |
    */
    'entry_status' => 'published',

    /*
    |--------------------------------------------------------------------------
    | Max Suggestions
    |--------------------------------------------------------------------------
    |
    | Maximum number of suggestions to display in the sidebar panel.
    |
    */
    'max_suggestions' => 10,

    /*
    |--------------------------------------------------------------------------
    | Open in New Tab
    |--------------------------------------------------------------------------
    |
    | Add target="_blank" to inserted links.
    |
    */
    'open_in_new_tab' => false,

    /*
    |--------------------------------------------------------------------------
    | Minimum Phrase Words
    |--------------------------------------------------------------------------
    |
    | Minimum number of words a match phrase must have to be considered.
    |
    */
    'min_phrase_words' => 2,

    /*
    |--------------------------------------------------------------------------
    | Minimum Score
    |--------------------------------------------------------------------------
    |
    | Minimum relevance score (0-1) for title-based suggestions.
    |
    */
    'min_score' => 0.4,

    /*
    |--------------------------------------------------------------------------
    | Max Keywords Per Entry
    |--------------------------------------------------------------------------
    |
    | Maximum number of TF-IDF keywords to store per entry in the index.
    |
    */
    'max_keywords_per_entry' => 20,

    /*
    |--------------------------------------------------------------------------
    | Minimum Keyword Overlap Score
    |--------------------------------------------------------------------------
    |
    | Minimum TF-IDF keyword overlap score (0-1) for keyword-based suggestions.
    |
    */
    'min_keyword_score' => 0.15,

    /*
    |--------------------------------------------------------------------------
    | Enable Auto-Keyword Matches
    |--------------------------------------------------------------------------
    |
    | When enabled, the SuggestionEngine also produces matches based on TF-IDF
    | keyword overlap (beyond title/stem matches). Produces more suggestions but
    | with higher noise level. Disabled by default — title/stem/custom matches
    | cover the high-signal cases and are more reliable.
    |
    */
    'enable_keyword_matches' => false,

    /*
    |--------------------------------------------------------------------------
    | Prevent Two-Way Linking
    |--------------------------------------------------------------------------
    |
    | If A links to B, don't suggest B linking to A.
    |
    */
    'prevent_two_way' => false,

    /*
    |--------------------------------------------------------------------------
    | Auto-Apply Rules on Entry Save
    |--------------------------------------------------------------------------
    |
    | Master switch. When true, AutoLinkOnEntrySaveSubscriber listens for
    | EntrySaved and applies every rule whose `auto_apply_on_save` per-rule
    | flag is also true. Both must be true — opt-in on both layers.
    |
    */
    'auto_apply_on_save_enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Exclusions
    |--------------------------------------------------------------------------
    */
    'excluded_entries' => [],
    'excluded_collections' => [],
    'title_blacklist' => '',
    'orphaned_ignore' => [],
    'ignored_links' => '',
    /*
    |--------------------------------------------------------------------------
    | Custom Stopwords
    |--------------------------------------------------------------------------
    |
    | Additional stopwords (one per line) added to the language defaults.
    |
    */
    'custom_stopwords' => '',

    /*
    |--------------------------------------------------------------------------
    | Broken Link Checker
    |--------------------------------------------------------------------------
    */
    'broken_links' => [
        'timeout' => 10,
        'retries' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Provider (optional, BYOK)
    |--------------------------------------------------------------------------
    |
    | Enable AI-powered semantic matching by providing your own API key.
    | Supported providers: null (disabled), 'openai', 'anthropic'
    |
    */
    'ai' => [
        'provider' => env('LINKWISE_AI_PROVIDER', null),
        'api_key' => env('LINKWISE_AI_API_KEY', null),
        'model' => env('LINKWISE_AI_MODEL', null),
    ],

];

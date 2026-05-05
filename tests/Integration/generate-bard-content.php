#!/usr/bin/env php
<?php
/**
 * Bard content generator for Linkwise integration tests.
 * Generates ~30 Statamic page entries with realistic ProseMirror/Bard content
 * inside Peak's page_builder (Replicator) → article set → article (Bard) structure.
 *
 * Usage: cd /path/to/statamic-app && php /path/to/statamic-linkwise/tests/Integration/generate-bard-content.php
 *        To remove: php ... --cleanup
 */

$cleanup = in_array('--cleanup', $argv ?? []);
$pagesDir = getcwd() . '/content/collections/pages';
$articlesDir = getcwd() . '/content/collections/articles';
$prefix = 'bard-'; // All generated files start with this

if ($cleanup) {
    $files = glob("$pagesDir/{$prefix}*.md");
    foreach ($files as $f) unlink($f);
    echo "Cleaned up " . count($files) . " bard-test page entries.\n";
    exit(0);
}

if (!is_dir($pagesDir)) {
    echo "Error: $pagesDir not found. Run from the Statamic app root.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Load existing article entry IDs for internal cross-linking
// ---------------------------------------------------------------------------
$articleIds = [];
if (is_dir($articlesDir)) {
    foreach (glob("$articlesDir/*.md") as $file) {
        $content = file_get_contents($file);
        if (preg_match('/^id:\s*(.+)$/m', $content, $m)) {
            $id = trim($m[1]);
            // Only use UUID-style IDs (skip slugs like "home")
            if (preg_match('/^[0-9a-f]{8}-/', $id)) {
                $articleIds[] = $id;
            }
        }
    }
}

// Also collect existing page IDs
$existingPageIds = [];
foreach (glob("$pagesDir/*.md") as $file) {
    $content = file_get_contents($file);
    if (preg_match('/^id:\s*(.+)$/m', $content, $m)) {
        $id = trim($m[1]);
        if (preg_match('/^[0-9a-f]{8}-/', $id)) {
            $existingPageIds[] = $id;
        }
    }
}

echo "Found " . count($articleIds) . " article IDs and " . count($existingPageIds) . " existing page IDs for cross-linking.\n";

// ---------------------------------------------------------------------------
// Pre-generate all 30 entry UUIDs so we can cross-reference between them
// ---------------------------------------------------------------------------
$generatedIds = [];
for ($i = 0; $i < 30; $i++) {
    $generatedIds[] = generateUuid();
}

// All IDs available for internal linking
$allLinkTargets = array_merge($articleIds, $existingPageIds, $generatedIds);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function generateUuid(): string {
    return sprintf(
        '%08x-%04x-%04x-%04x-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffff),
        (random_int(0, 0x0fff) | 0x4000), // version 4
        (random_int(0, 0x3fff) | 0x8000), // variant
        random_int(0, 0xffffffffffff)
    );
}

function randomId(): string {
    // Short random ID like Statamic generates for replicator sets
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
    $len = random_int(8, 21);
    $id = '';
    for ($i = 0; $i < $len; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

function pickTarget(): string {
    global $allLinkTargets;
    return $allLinkTargets[array_rand($allLinkTargets)];
}

function internalHref(?string $targetId = null): string {
    return 'statamic://entry::' . ($targetId ?? pickTarget());
}

function externalHref(): string {
    $urls = [
        'https://laravel.com/docs/11.x/eloquent',
        'https://statamic.dev/extending/addons',
        'https://vuejs.org/guide/essentials/reactivity-fundamentals.html',
        'https://developer.mozilla.org/en-US/docs/Web/HTML/Element/a',
        'https://www.php.net/manual/en/function.array-map.php',
        'https://tailwindcss.com/docs/flex',
        'https://github.com/statamic/cms',
        'https://de.wikipedia.org/wiki/Suchmaschinenoptimierung',
        'https://ahrefs.com/blog/internal-linking/',
        'https://moz.com/learn/seo/internal-link',
        'https://www.semrush.com/blog/internal-linking/',
        'https://web.dev/articles/vitals',
        'https://schema.org/Article',
    ];
    return $urls[array_rand($urls)];
}

// ---------------------------------------------------------------------------
// ProseMirror node builders
// ---------------------------------------------------------------------------

function textNode(string $text, array $marks = []): array {
    $node = ['type' => 'text', 'text' => $text];
    if (!empty($marks)) {
        $node['marks'] = $marks;
    }
    return $node;
}

function linkMark(string $href, ?string $rel = null, ?string $target = null, ?string $title = null): array {
    return [
        'type' => 'link',
        'attrs' => [
            'href' => $href,
            'rel' => $rel,
            'target' => $target,
            'title' => $title,
        ],
    ];
}

function boldMark(): array {
    return ['type' => 'bold'];
}

function italicMark(): array {
    return ['type' => 'italic'];
}

function paragraph(array $content = []): array {
    $node = ['type' => 'paragraph'];
    if (!empty($content)) {
        $node['content'] = $content;
    }
    return $node;
}

function heading(int $level, array $content): array {
    return [
        'type' => 'heading',
        'attrs' => ['level' => $level],
        'content' => $content,
    ];
}

function bulletList(array $items): array {
    return [
        'type' => 'bulletList',
        'content' => array_map(fn($itemContent) => [
            'type' => 'listItem',
            'content' => [paragraph($itemContent)],
        ], $items),
    ];
}

function orderedList(array $items, int $start = 1): array {
    return [
        'type' => 'orderedList',
        'attrs' => ['start' => $start, 'type' => null],
        'content' => array_map(fn($itemContent) => [
            'type' => 'listItem',
            'content' => [paragraph($itemContent)],
        ], $items),
    ];
}

function blockquote(array $paragraphs): array {
    return [
        'type' => 'blockquote',
        'content' => $paragraphs,
    ];
}

function codeBlock(string $code, ?string $language = null): array {
    $node = [
        'type' => 'codeBlock',
        'content' => [
            ['type' => 'text', 'text' => $code],
        ],
    ];
    if ($language) {
        $node['attrs'] = ['language' => $language];
    }
    return $node;
}

function imageNode(string $src, string $alt): array {
    return [
        'type' => 'image',
        'attrs' => [
            'src' => $src,
            'alt' => $alt,
        ],
    ];
}

function tableNode(array $rows, bool $hasHeader = true): array {
    $tableRows = [];
    foreach ($rows as $ri => $cells) {
        $tableCells = [];
        foreach ($cells as $cellContent) {
            $cellType = ($hasHeader && $ri === 0) ? 'tableHeader' : 'tableCell';
            $tableCells[] = [
                'type' => $cellType,
                'content' => [paragraph($cellContent)],
            ];
        }
        $tableRows[] = [
            'type' => 'tableRow',
            'content' => $tableCells,
        ];
    }
    return [
        'type' => 'table',
        'content' => $tableRows,
    ];
}

function setNode(string $type, array $extraValues = []): array {
    return [
        'type' => 'set',
        'attrs' => [
            'id' => randomId(),
            'enabled' => true,
            'values' => array_merge(['type' => $type], $extraValues),
        ],
    ];
}

function hardBreak(): array {
    return ['type' => 'hardBreak'];
}

// ---------------------------------------------------------------------------
// YAML serialization for Statamic flat-file format
// ---------------------------------------------------------------------------

/**
 * Indent a multi-line YAML string by $spaces.
 */
function indentYaml(string $yaml, int $spaces): string {
    $prefix = str_repeat(' ', $spaces);
    $lines = explode("\n", $yaml);
    $result = [];
    foreach ($lines as $i => $line) {
        // Don't indent empty lines
        $result[] = ($line === '') ? '' : $prefix . $line;
    }
    return implode("\n", $result);
}

/**
 * Convert a PHP value to YAML suitable for Statamic flat files.
 * This handles the specific formatting Statamic expects.
 */
/**
 * Render a multi-line string as a YAML literal block scalar (|) at the given indent.
 * Returns the full "|\n  line1\n  line2\n..." string.
 */
function toLiteralBlock(string $value, int $indent): string {
    $prefix = str_repeat(' ', $indent);
    $lines = explode("\n", $value);
    $result = "|\n";
    foreach ($lines as $line) {
        $result .= $prefix . $line . "\n";
    }
    return $result;
}

function toYaml($value, int $indent = 0): string {
    if (is_null($value)) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    if (is_string($value)) {
        // Determine if we need quoting
        if ($value === '') {
            return "''";
        }
        // Multi-line strings MUST use literal block scalar
        if (str_contains($value, "\n")) {
            // Caller must handle this — return a marker that won't be used inline
            // This case is handled by toLiteralBlock() in renderTextNode
            // For safety, fall through to quoting
        }
        // Quote if contains special chars
        $needsQuote = false;
        if (preg_match('/[:{}\[\]&*?|>!%@`#,]/', $value) ||
            str_starts_with($value, "'") ||
            str_starts_with($value, '"') ||
            str_starts_with($value, ' ') ||
            str_ends_with($value, ' ') ||
            str_contains($value, "\n") ||
            is_numeric($value) ||
            in_array(strtolower($value), ['true', 'false', 'null', 'yes', 'no', 'on', 'off'], true)
        ) {
            $needsQuote = true;
        }
        if ($needsQuote) {
            // Multi-line: use literal block scalar
            if (str_contains($value, "\n")) {
                return toLiteralBlock($value, $indent);
            }
            // Use single quotes, escape internal single quotes
            $escaped = str_replace("'", "''", $value);
            return "'" . $escaped . "'";
        }
        return $value;
    }

    // Array handling
    if (is_array($value)) {
        if (empty($value)) {
            return '[]';
        }

        // Check if sequential array
        $isSequential = array_keys($value) === range(0, count($value) - 1);

        if ($isSequential) {
            $lines = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $inner = toYamlMap($item, $indent + 2);
                    $lines[] = "-\n" . $inner;
                } else {
                    $lines[] = "- " . toYaml($item, $indent + 2);
                }
            }
            return implode("\n", $lines);
        } else {
            return toYamlMap($value, $indent);
        }
    }

    return (string)$value;
}

/**
 * Convert an associative array to YAML key: value pairs.
 */
function toYamlMap(array $map, int $indent): string {
    $prefix = str_repeat(' ', $indent);
    $lines = [];
    foreach ($map as $key => $val) {
        if (is_array($val) && !empty($val)) {
            $isSequential = array_keys($val) === range(0, count($val) - 1);
            if ($isSequential) {
                $lines[] = $prefix . $key . ':';
                foreach ($val as $item) {
                    if (is_array($item)) {
                        $inner = toYamlMap($item, $indent + 4);
                        $lines[] = $prefix . '  -';
                        $lines[] = $inner;
                    } else {
                        $lines[] = $prefix . '  - ' . toYaml($item, $indent + 4);
                    }
                }
            } else {
                $lines[] = $prefix . $key . ':';
                $inner = toYamlMap($val, $indent + 2);
                $lines[] = $inner;
            }
        } else {
            $lines[] = $prefix . $key . ': ' . toYaml($val, $indent);
        }
    }
    return implode("\n", $lines);
}

/**
 * Render a full Statamic page entry as YAML with Bard content inside page_builder.
 */
function renderEntry(string $id, string $title, array $bardNodes): string {
    // We'll build the YAML manually to match Statamic's exact format
    $yaml = "---\n";
    $yaml .= "id: " . $id . "\n";
    $yaml .= "blueprint: page\n";
    $yaml .= "title: " . toYaml($title) . "\n";
    $yaml .= "page_builder:\n";
    $yaml .= "  -\n";
    $yaml .= "    id: " . randomId() . "\n";
    $yaml .= "    article:\n";

    foreach ($bardNodes as $node) {
        $yaml .= renderBardNode($node, 6);
    }

    $yaml .= "    type: article\n";
    $yaml .= "    enabled: true\n";
    $yaml .= "---\n";

    return $yaml;
}

/**
 * Render a single ProseMirror node as YAML at the given indent level.
 */
function renderBardNode(array $node, int $indent): string {
    $prefix = str_repeat(' ', $indent);
    $yaml = $prefix . "-\n";
    $yaml .= $prefix . "  type: " . $node['type'] . "\n";

    // Attrs
    if (isset($node['attrs'])) {
        $yaml .= $prefix . "  attrs:\n";
        foreach ($node['attrs'] as $key => $val) {
            if (is_array($val)) {
                $yaml .= $prefix . "    " . $key . ":\n";
                foreach ($val as $vk => $vv) {
                    if (is_array($vv)) {
                        // Nested arrays in set values (e.g. nested data)
                        $yaml .= $prefix . "      " . $vk . ":\n";
                        $yaml .= renderNestedValue($vv, $indent + 8);
                    } else {
                        $yaml .= $prefix . "      " . $vk . ": " . toYaml($vv) . "\n";
                    }
                }
            } else {
                $yaml .= $prefix . "    " . $key . ": " . toYaml($val) . "\n";
            }
        }
    }

    // Content (child nodes)
    if (isset($node['content'])) {
        $yaml .= $prefix . "  content:\n";
        foreach ($node['content'] as $child) {
            if ($child['type'] === 'text') {
                $yaml .= renderTextNode($child, $indent + 4);
            } else {
                $yaml .= renderBardNode($child, $indent + 4);
            }
        }
    }

    // Marks (for text nodes rendered at this level — shouldn't happen but safety)
    if (isset($node['marks'])) {
        $yaml .= renderMarks($node['marks'], $indent + 2);
    }

    return $yaml;
}

/**
 * Render a text node.
 */
function renderTextNode(array $node, int $indent): string {
    $prefix = str_repeat(' ', $indent);
    $yaml = $prefix . "-\n";
    $yaml .= $prefix . "  type: text\n";

    // Marks come before text in Statamic's format
    if (isset($node['marks']) && !empty($node['marks'])) {
        $yaml .= $prefix . "  marks:\n";
        foreach ($node['marks'] as $mark) {
            $yaml .= $prefix . "    -\n";
            $yaml .= $prefix . "      type: " . $mark['type'] . "\n";
            if (isset($mark['attrs'])) {
                $yaml .= $prefix . "      attrs:\n";
                foreach ($mark['attrs'] as $k => $v) {
                    $yaml .= $prefix . "        " . $k . ": " . toYaml($v) . "\n";
                }
            }
        }
    }

    $text = $node['text'];
    if (str_contains($text, "\n")) {
        // Multi-line: use literal block scalar
        $yaml .= $prefix . "  text: " . toLiteralBlock($text, $indent + 4);
    } else {
        $yaml .= $prefix . "  text: " . toYaml($text) . "\n";
    }

    return $yaml;
}

/**
 * Render marks array.
 */
function renderMarks(array $marks, int $indent): string {
    $prefix = str_repeat(' ', $indent);
    $yaml = $prefix . "marks:\n";
    foreach ($marks as $mark) {
        $yaml .= $prefix . "  -\n";
        $yaml .= $prefix . "    type: " . $mark['type'] . "\n";
        if (isset($mark['attrs'])) {
            $yaml .= $prefix . "    attrs:\n";
            foreach ($mark['attrs'] as $k => $v) {
                $yaml .= $prefix . "      " . $k . ": " . toYaml($v) . "\n";
            }
        }
    }
    return $yaml;
}

/**
 * Render nested values (for set node values).
 */
function renderNestedValue($value, int $indent): string {
    $prefix = str_repeat(' ', $indent);
    $yaml = '';
    if (is_array($value)) {
        $isSequential = array_keys($value) === range(0, count($value) - 1);
        if ($isSequential) {
            foreach ($value as $item) {
                if (is_array($item)) {
                    $yaml .= $prefix . "-\n";
                    foreach ($item as $k => $v) {
                        if (is_array($v)) {
                            $yaml .= $prefix . "  " . $k . ":\n";
                            $yaml .= renderNestedValue($v, $indent + 4);
                        } else {
                            $yaml .= $prefix . "  " . $k . ": " . toYaml($v) . "\n";
                        }
                    }
                } else {
                    $yaml .= $prefix . "- " . toYaml($item) . "\n";
                }
            }
        } else {
            foreach ($value as $k => $v) {
                if (is_array($v)) {
                    $yaml .= $prefix . $k . ":\n";
                    $yaml .= renderNestedValue($v, $indent + 2);
                } else {
                    $yaml .= $prefix . $k . ": " . toYaml($v) . "\n";
                }
            }
        }
    }
    return $yaml;
}


// ===========================================================================
// Entry definitions
// ===========================================================================

$entries = [];

// ---------------------------------------------------------------------------
// 1. SEO Basics — paragraphs with internal + external links
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Internal Linking Best Practices for SEO',
    'nodes' => [
        heading(2, [textNode('Why Internal Links Matter')]),
        paragraph([
            textNode('Internal linking is one of the most powerful yet underutilized '),
            textNode('SEO strategies', [boldMark()]),
            textNode('. By connecting related content across your site, you help search engines understand your content hierarchy and distribute '),
            textNode('page authority', [linkMark(internalHref())]),
            textNode(' effectively.'),
        ]),
        paragraph([
            textNode('According to '),
            textNode('Moz', [linkMark('https://moz.com/learn/seo/internal-link')]),
            textNode(', sites with strong internal linking structures rank significantly higher for competitive keywords. The key is creating '),
            textNode('contextual links', [italicMark()]),
            textNode(' that add genuine value for the reader.'),
        ]),
        heading(3, [textNode('Anchor Text Optimization')]),
        paragraph([
            textNode('Your anchor text should be descriptive and relevant. Avoid generic phrases like "click here" — instead, use keywords that describe the '),
            textNode('target page content', [linkMark(internalHref())]),
            textNode('. This helps both users and search engines understand what to expect.'),
        ]),
        paragraph([
            textNode('For more on '),
            textNode('content strategy', [linkMark(externalHref())]),
            textNode(', see our comprehensive guide to '),
            textNode('keyword research', [linkMark(internalHref())]),
            textNode('.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 2. Laravel article — headings with links, code blocks
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Getting Started with Laravel Middleware',
    'nodes' => [
        heading(2, [
            textNode('Understanding '),
            textNode('Laravel Middleware', [linkMark('https://laravel.com/docs/11.x/middleware')]),
        ]),
        paragraph([
            textNode('Middleware provides a convenient mechanism for inspecting and filtering HTTP requests entering your application. Laravel includes several middleware out of the box, including middleware for '),
            textNode('authentication', [linkMark(internalHref())]),
            textNode(' and '),
            textNode('CSRF protection', [boldMark(), linkMark(internalHref())]),
            textNode('.'),
        ]),
        codeBlock("<?php\n\nnamespace App\\Http\\Middleware;\n\nuse Closure;\nuse Illuminate\\Http\\Request;\n\nclass EnsureTokenIsValid\n{\n    public function handle(Request \$request, Closure \$next)\n    {\n        if (\$request->input('token') !== 'my-secret-token') {\n            return redirect('home');\n        }\n\n        return \$next(\$request);\n    }\n}", 'php'),
        paragraph([
            textNode('The code above shows a simple middleware that checks for a valid token. In production, you would use '),
            textNode('Laravel Sanctum', [linkMark(externalHref())]),
            textNode(' or '),
            textNode('Passport', [linkMark(externalHref())]),
            textNode(' for proper API authentication.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 3. German SEO article — bullet list with links
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Suchmaschinenoptimierung: Der ultimative Leitfaden',
    'nodes' => [
        heading(2, [textNode('Was ist SEO?')]),
        paragraph([
            textNode('Suchmaschinenoptimierung (SEO) umfasst alle Maßnahmen, die dazu dienen, die Sichtbarkeit einer Website in den organischen Suchergebnissen zu verbessern. Dabei spielen sowohl '),
            textNode('technische Faktoren', [boldMark()]),
            textNode(' als auch '),
            textNode('inhaltliche Qualität', [italicMark()]),
            textNode(' eine entscheidende Rolle.'),
        ]),
        heading(3, [textNode('Die wichtigsten SEO-Faktoren')]),
        bulletList([
            [
                textNode('Interne Verlinkung', [boldMark()]),
                textNode(' — Verknüpfen Sie verwandte Inhalte, um die '),
                textNode('Seitenautorität', [linkMark(internalHref())]),
                textNode(' zu verteilen'),
            ],
            [
                textNode('Content-Qualität', [boldMark()]),
                textNode(' — Erstellen Sie hochwertige Inhalte, die '),
                textNode('Mehrwert für den Leser', [linkMark(externalHref())]),
                textNode(' bieten'),
            ],
            [
                textNode('Technische Optimierung', [boldMark()]),
                textNode(' — Sorgen Sie für schnelle Ladezeiten und '),
                textNode('Core Web Vitals', [linkMark('https://web.dev/articles/vitals')]),
            ],
            [
                textNode('Mobile-First', [boldMark()]),
                textNode(' — Über 60% aller Suchanfragen erfolgen auf mobilen Geräten'),
            ],
            [
                textNode('Backlinks', [boldMark()]),
                textNode(' — Qualitativ hochwertige '),
                textNode('externe Verlinkungen', [linkMark(externalHref())]),
                textNode(' stärken Ihre Domain-Autorität'),
            ],
        ]),
        paragraph([
            textNode('Für weiterführende Informationen empfehlen wir unseren Artikel über '),
            textNode('Keyword-Recherche', [linkMark(internalHref())]),
            textNode(' sowie den '),
            textNode('Ahrefs-Blog', [linkMark('https://ahrefs.com/blog/internal-linking/')]),
            textNode('.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 4. Table with links — CMS comparison
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'CMS Comparison: Statamic vs WordPress vs Craft',
    'nodes' => [
        heading(2, [textNode('Choosing the Right CMS')]),
        paragraph([
            textNode('Selecting a content management system is a critical decision. Here we compare three popular options for their '),
            textNode('internal linking capabilities', [linkMark(internalHref())]),
            textNode('.'),
        ]),
        tableNode([
            // Header row
            [
                [textNode('Feature')],
                [textNode('Statamic', [linkMark('https://statamic.dev')])],
                [textNode('WordPress', [linkMark('https://wordpress.org')])],
                [textNode('Craft CMS')],
            ],
            // Data rows
            [
                [textNode('Internal Linking')],
                [textNode('Native with '), textNode('Linkwise', [boldMark(), linkMark(internalHref())])],
                [textNode('Via '), textNode('Link Whisper', [linkMark('https://linkwhisper.com')])],
                [textNode('Manual only')],
            ],
            [
                [textNode('Content Storage')],
                [textNode('Flat-file YAML')],
                [textNode('MySQL Database')],
                [textNode('MySQL/PostgreSQL')],
            ],
            [
                [textNode('Performance')],
                [textNode('Excellent', [boldMark()])],
                [textNode('Requires '), textNode('caching plugins', [linkMark(externalHref())])],
                [textNode('Good')],
            ],
            [
                [textNode('Pricing')],
                [textNode('$259/site (Pro)')],
                [textNode('Free (hosting extra)')],
                [textNode('$299/project')],
            ],
        ], true),
        paragraph([
            textNode('For Statamic users, '),
            textNode('Linkwise', [boldMark()]),
            textNode(' provides automated internal link suggestions similar to what Link Whisper does for WordPress.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 5. Blockquote with links
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Content Strategy: Why Quality Beats Quantity',
    'nodes' => [
        heading(2, [textNode('The Content Quality Debate')]),
        paragraph([
            textNode('In the world of content marketing, there has always been a tension between publishing frequently and publishing well. The data is clear: '),
            textNode('quality content', [boldMark()]),
            textNode(' outperforms thin content every time.'),
        ]),
        blockquote([
            paragraph([
                textNode('The best content strategy is one that prioritizes depth over breadth. A single comprehensive article that covers a topic thoroughly will generate more organic traffic than ten shallow posts.'),
            ]),
            paragraph([
                textNode('— From '),
                textNode('Content Marketing Institute', [linkMark('https://contentmarketinginstitute.com')]),
                textNode(', as cited in our '),
                textNode('content strategy overview', [linkMark(internalHref())]),
            ]),
        ]),
        paragraph([
            textNode('This principle directly applies to '),
            textNode('internal linking', [linkMark(internalHref())]),
            textNode('. When your content is comprehensive, there are more natural opportunities to create meaningful links between articles.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 6. Ordered list with links — step-by-step guide
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'How to Audit Your Internal Link Structure',
    'nodes' => [
        heading(2, [textNode('Internal Link Audit: Step by Step')]),
        paragraph([
            textNode('A thorough '),
            textNode('internal link audit', [boldMark()]),
            textNode(' reveals orphan pages, broken links, and missed linking opportunities. Follow these steps:'),
        ]),
        orderedList([
            [
                textNode('Crawl your site', [boldMark()]),
                textNode(' using a tool like '),
                textNode('Screaming Frog', [linkMark('https://www.screamingfrog.co.uk/seo-spider/')]),
                textNode(' or '),
                textNode('Sitebulb', [linkMark('https://sitebulb.com')]),
            ],
            [
                textNode('Identify '),
                textNode('orphan pages', [boldMark(), italicMark()]),
                textNode(' — pages with zero internal links pointing to them'),
            ],
            [
                textNode('Check for '),
                textNode('broken internal links', [linkMark(internalHref())]),
                textNode(' that lead to 404 pages'),
            ],
            [
                textNode('Review '),
                textNode('anchor text distribution', [linkMark(internalHref())]),
                textNode(' to ensure variety'),
            ],
            [
                textNode('Map your '),
                textNode('content clusters', [boldMark()]),
                textNode(' and ensure each cluster has a pillar page linking to all related content'),
            ],
        ]),
        paragraph([
            textNode('With '),
            textNode('Linkwise for Statamic', [linkMark(internalHref())]),
            textNode(', steps 2-4 are automated through the Links Report dashboard.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 7. Image-only entry (edge case)
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Infographic: Internal Linking Hierarchy',
    'nodes' => [
        imageNode('asset::assets/images/internal-linking-hierarchy.png', 'Internal linking hierarchy diagram showing pillar pages and cluster content'),
    ],
];

// ---------------------------------------------------------------------------
// 8. Bold + italic + link combined marks
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Advanced ProseMirror Content Patterns',
    'nodes' => [
        heading(2, [textNode('Complex Text Formatting')]),
        paragraph([
            textNode('When working with rich text editors, you may need to combine '),
            textNode('bold, italic, and linked text', [boldMark(), italicMark(), linkMark(internalHref())]),
            textNode(' in a single text node. This tests the mark handling capabilities of content parsers.'),
        ]),
        paragraph([
            textNode('Consider this example: '),
            textNode('the Laravel documentation', [boldMark(), linkMark('https://laravel.com/docs/11.x/eloquent')]),
            textNode(' explains Eloquent in detail. You might also want '),
            textNode('emphasized links', [italicMark(), linkMark(internalHref())]),
            textNode(' for secondary references.'),
        ]),
        heading(3, [
            textNode('Heading with '),
            textNode('bold link inside', [boldMark(), linkMark(externalHref())]),
        ]),
        paragraph([
            textNode('The heading above contains a link with bold formatting — a common pattern in real-world content.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 9. Adjacent text nodes with same link (merged anchor edge case)
// ---------------------------------------------------------------------------
$mergedLinkTarget = internalHref();
$entries[] = [
    'title' => 'Testing Adjacent Link Nodes',
    'nodes' => [
        heading(2, [textNode('Adjacent Link Segments')]),
        paragraph([
            textNode('We all '),
            textNode('love ', [linkMark($mergedLinkTarget)]),
            textNode('coffee', [linkMark($mergedLinkTarget)]),
            textNode('. This is a pattern where adjacent text nodes share the same link target, as seen in real Bard content.'),
        ]),
        paragraph([
            textNode('Another example: '),
            textNode('internal', [boldMark(), linkMark($mergedLinkTarget)]),
            textNode(' linking', [boldMark(), linkMark($mergedLinkTarget)]),
            textNode(' strategy', [linkMark($mergedLinkTarget)]),
            textNode(' — three consecutive nodes all linking to the same entry.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 10. Self-referencing link (edge case)
// ---------------------------------------------------------------------------
$selfRefIndex = 9; // This will be entry index 9 (0-based)
$entries[] = [
    'title' => 'Self-Referencing Link Test Page',
    'nodes' => [
        heading(2, [textNode('Circular Reference Detection')]),
        paragraph([
            textNode('This page intentionally contains a link to '),
            textNode('itself', [linkMark(internalHref($generatedIds[$selfRefIndex]))]),
            textNode('. Self-referencing links are an SEO anti-pattern that tools like Linkwise should detect and flag.'),
        ]),
        paragraph([
            textNode('A page linking to '),
            textNode('its own URL', [boldMark(), linkMark(internalHref($generatedIds[$selfRefIndex]))]),
            textNode(' wastes link equity and confuses users. Always link to '),
            textNode('other relevant content', [linkMark(internalHref())]),
            textNode(' instead.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 11. Empty paragraphs + hard break (edge case)
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Edge Case: Empty Paragraphs and Hard Breaks',
    'nodes' => [
        heading(2, [textNode('Whitespace Handling')]),
        paragraph([]),
        paragraph([
            textNode('This content has empty paragraphs around it. The '),
            textNode('text extractor', [linkMark(internalHref())]),
            textNode(' must handle these gracefully without crashing.'),
        ]),
        paragraph([]),
        paragraph([
            textNode('Here is a paragraph with a hard break'),
            hardBreak(),
            textNode('and text continues on the next line within the same paragraph.'),
        ]),
        paragraph([hardBreak()]),
        paragraph([
            textNode('Content after the hard-break-only paragraph.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 12. Deeply nested list (edge case)
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Nested List Structure Test',
    'nodes' => [
        heading(2, [textNode('Complex List Nesting')]),
        // Bullet list with complex items
        bulletList([
            [
                textNode('First-level item with '),
                textNode('a link', [linkMark(internalHref())]),
            ],
            [
                textNode('First-level item containing '),
                textNode('bold text', [boldMark()]),
                textNode(' and '),
                textNode('italic text', [italicMark()]),
            ],
            [
                textNode('Item referencing '),
                textNode('Laravel middleware', [linkMark(internalHref())]),
                textNode(', '),
                textNode('Statamic addons', [linkMark(externalHref())]),
                textNode(', and '),
                textNode('Vue.js components', [linkMark(externalHref())]),
            ],
        ]),
        orderedList([
            [
                textNode('Step one: Configure '),
                textNode('your environment', [linkMark(externalHref())]),
            ],
            [
                textNode('Step two: Install '),
                textNode('dependencies', [boldMark(), linkMark(internalHref())]),
            ],
            [
                textNode('Step three: Run '),
                textNode('the test suite', [italicMark()]),
            ],
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 13. Table with many links (stress test)
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'SEO Tools Comparison Matrix',
    'nodes' => [
        heading(2, [textNode('SEO Tools Overview')]),
        tableNode([
            [
                [textNode('Tool')],
                [textNode('Type')],
                [textNode('Internal Linking')],
                [textNode('Price')],
            ],
            [
                [textNode('Ahrefs', [linkMark('https://ahrefs.com')])],
                [textNode('All-in-one SEO')],
                [textNode('Site Audit', [linkMark('https://ahrefs.com/site-audit')])],
                [textNode('$99/mo')],
            ],
            [
                [textNode('Semrush', [linkMark('https://www.semrush.com')])],
                [textNode('All-in-one SEO')],
                [textNode('Internal Link Audit', [linkMark('https://www.semrush.com/blog/internal-linking/')])],
                [textNode('$129/mo')],
            ],
            [
                [textNode('Link Whisper', [linkMark('https://linkwhisper.com')])],
                [textNode('WordPress Plugin')],
                [textNode('Auto-suggestions', [boldMark()])],
                [textNode('$77/yr')],
            ],
            [
                [textNode('Linkwise', [boldMark(), linkMark(internalHref())])],
                [textNode('Statamic Addon', [linkMark(internalHref())])],
                [textNode('AI-powered', [boldMark(), italicMark()])],
                [textNode('TBD')],
            ],
            [
                [textNode('Screaming Frog', [linkMark('https://www.screamingfrog.co.uk')])],
                [textNode('Desktop Crawler')],
                [textNode('Link analysis')],
                [textNode('$259/yr')],
            ],
        ], true),
        paragraph([
            textNode('Each tool has different strengths. For Statamic sites, '),
            textNode('Linkwise', [boldMark()]),
            textNode(' is the only native solution for '),
            textNode('automated internal link suggestions', [linkMark(internalHref())]),
            textNode('.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 14. German article — Barrierefreiheit
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Barrierefreiheit im Web: WCAG-Richtlinien verstehen',
    'nodes' => [
        heading(2, [textNode('Warum Barrierefreiheit wichtig ist')]),
        paragraph([
            textNode('Die '),
            textNode('Web Content Accessibility Guidelines (WCAG)', [linkMark('https://www.w3.org/WAI/standards-guidelines/wcag/')]),
            textNode(' definieren Standards für barrierefreie Webinhalte. Ab 2025 sind viele Unternehmen in der EU gesetzlich verpflichtet, ihre Websites barrierefrei zu gestalten.'),
        ]),
        bulletList([
            [
                textNode('Perceivable', [boldMark()]),
                textNode(' — Informationen müssen wahrnehmbar sein (z.B. '),
                textNode('Alt-Texte für Bilder', [linkMark(internalHref())]),
                textNode(')'),
            ],
            [
                textNode('Operable', [boldMark()]),
                textNode(' — Die Bedienung muss möglich sein (Tastaturnavigation)'),
            ],
            [
                textNode('Understandable', [boldMark()]),
                textNode(' — Inhalte müssen verständlich sein'),
            ],
            [
                textNode('Robust', [boldMark()]),
                textNode(' — Inhalte müssen mit verschiedenen '),
                textNode('Browsern und Hilfstechnologien', [linkMark(externalHref())]),
                textNode(' funktionieren'),
            ],
        ]),
        blockquote([
            paragraph([
                textNode('Barrierefreiheit ist kein Feature — sie ist eine Grundvoraussetzung für ein inklusives Web.'),
            ]),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 15. Bard set with image + pull quote
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Visual Content Integration with Bard Sets',
    'nodes' => [
        heading(2, [textNode('Embedding Rich Media')]),
        paragraph([
            textNode('Statamic Bard fields support embedded sets for media and widgets. Here we demonstrate '),
            textNode('image sets', [linkMark(internalHref())]),
            textNode(' and '),
            textNode('pull quotes', [italicMark()]),
            textNode(' within article content.'),
        ]),
        setNode('image', [
            'image' => 'peak-mountains.jpg',
            'size' => 'xl',
            'caption' => 'A stunning mountain landscape demonstrating responsive image handling.',
        ]),
        paragraph([
            textNode('The image above is rendered via a Bard set, not an inline ProseMirror image node. This is the more common pattern in Peak-based sites.'),
        ]),
        setNode('pull_quote', [
            'quote' => 'Content is king, but internal linking is the kingdom\'s infrastructure.',
            'author' => 'SEO Proverb',
            'size' => 'md',
        ]),
        paragraph([
            textNode('Pull quotes break up long content and highlight key takeaways. They can contain '),
            textNode('formatted text', [boldMark()]),
            textNode(' and are rendered by the theme.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 16. Vue.js / Frontend article
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Building Reactive Interfaces with Vue.js 3',
    'nodes' => [
        heading(2, [textNode('The Composition API')]),
        paragraph([
            textNode('Vue.js 3 introduced the '),
            textNode('Composition API', [linkMark('https://vuejs.org/guide/essentials/reactivity-fundamentals.html')]),
            textNode(' as an alternative to the Options API. It provides better TypeScript support and more flexible code organization for '),
            textNode('complex components', [linkMark(internalHref())]),
            textNode('.'),
        ]),
        codeBlock("<script setup>\nimport { ref, computed } from 'vue'\n\nconst count = ref(0)\nconst doubled = computed(() => count.value * 2)\n\nfunction increment() {\n  count.value++\n}\n</script>", 'vue'),
        paragraph([
            textNode('The '),
            textNode('ref()', [boldMark()]),
            textNode(' function creates a reactive reference. When its value changes, Vue automatically updates the DOM. This is fundamentally different from how '),
            textNode('React handles state', [linkMark(externalHref())]),
            textNode('.'),
        ]),
        heading(3, [textNode('Component Communication')]),
        paragraph([
            textNode('Props and emits remain the primary communication mechanism. For complex state management, consider '),
            textNode('Pinia', [linkMark('https://pinia.vuejs.org')]),
            textNode(' — the official state management library for Vue 3.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 17. Database article with ordered list
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Database Indexing Strategies for Performance',
    'nodes' => [
        heading(2, [textNode('Why Indexes Matter')]),
        paragraph([
            textNode('A '),
            textNode('database index', [boldMark()]),
            textNode(' is a data structure that improves the speed of data retrieval operations. Without proper indexes, queries on large tables can take seconds instead of milliseconds.'),
        ]),
        heading(3, [textNode('Types of Indexes')]),
        orderedList([
            [
                textNode('B-Tree Indexes', [boldMark()]),
                textNode(' — The default index type in '),
                textNode('MySQL', [linkMark('https://dev.mysql.com/doc/refman/8.0/en/innodb-index-types.html')]),
                textNode(' and '),
                textNode('PostgreSQL', [linkMark(internalHref())]),
            ],
            [
                textNode('Hash Indexes', [boldMark()]),
                textNode(' — Optimal for exact-match lookups, not range queries'),
            ],
            [
                textNode('Full-Text Indexes', [boldMark()]),
                textNode(' — Essential for '),
                textNode('search functionality', [linkMark(internalHref())]),
            ],
            [
                textNode('Composite Indexes', [boldMark()]),
                textNode(' — Cover multiple columns, order matters!'),
            ],
        ]),
        codeBlock("-- Create a composite index for common queries\nCREATE INDEX idx_entries_collection_status\n  ON entries (collection, status, published_at DESC);\n\n-- Analyze query performance\nEXPLAIN ANALYZE\n  SELECT * FROM entries\n  WHERE collection = 'articles'\n  AND status = 'published'\n  ORDER BY published_at DESC\n  LIMIT 20;", 'sql'),
        paragraph([
            textNode('Always use '),
            textNode('EXPLAIN ANALYZE', [boldMark()]),
            textNode(' to verify your indexes are being used. See our guide on '),
            textNode('query optimization', [linkMark(internalHref())]),
            textNode(' for more details.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 18. Statamic addon development
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Developing Statamic Addons: A Complete Guide',
    'nodes' => [
        heading(2, [textNode('Addon Architecture')]),
        paragraph([
            textNode('Statamic addons extend the CMS with custom functionality. They follow Laravel\'s service provider pattern and can add '),
            textNode('fieldtypes', [linkMark('https://statamic.dev/extending/fieldtypes')]),
            textNode(', '),
            textNode('tags', [linkMark('https://statamic.dev/extending/tags')]),
            textNode(', '),
            textNode('widgets', [linkMark('https://statamic.dev/extending/widgets')]),
            textNode(', and more.'),
        ]),
        heading(3, [
            textNode('Building a '),
            textNode('Custom Fieldtype', [italicMark()]),
        ]),
        paragraph([
            textNode('A fieldtype consists of a PHP class and a Vue component. The PHP side handles '),
            textNode('data processing', [boldMark()]),
            textNode(' while Vue handles the '),
            textNode('CP interface', [boldMark()]),
            textNode('. See our '),
            textNode('ProseMirror integration guide', [linkMark(internalHref())]),
            textNode(' for advanced Bard extensions.'),
        ]),
        bulletList([
            [
                textNode('Register via '),
                textNode('service provider', [linkMark(externalHref())]),
            ],
            [
                textNode('Define '),
                textNode('config fields', [boldMark()]),
                textNode(' in the fieldtype class'),
            ],
            [
                textNode('Create the Vue component with '),
                textNode('Statamic UI components', [linkMark(internalHref())]),
            ],
            [
                textNode('Write '),
                textNode('PHPUnit tests', [linkMark(internalHref())]),
                textNode(' for server-side logic'),
            ],
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 19. Docker / DevOps
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Docker for PHP Development: Local to Production',
    'nodes' => [
        heading(2, [textNode('Containerized PHP Workflows')]),
        paragraph([
            textNode('Docker simplifies PHP development by providing consistent environments. Whether you use '),
            textNode('Laravel Sail', [linkMark('https://laravel.com/docs/11.x/sail')]),
            textNode(', '),
            textNode('DDEV', [linkMark('https://ddev.readthedocs.io')]),
            textNode(', or custom Dockerfiles, containers eliminate the "works on my machine" problem.'),
        ]),
        codeBlock("# Multi-stage build for production\nFROM php:8.3-fpm-alpine AS base\nRUN apk add --no-cache icu-dev \\\n    && docker-php-ext-install intl opcache\n\nFROM base AS production\nCOPY --from=composer:latest /usr/bin/composer /usr/bin/composer\nCOPY . /app\nWORKDIR /app\nRUN composer install --no-dev --optimize-autoloader", 'dockerfile'),
        paragraph([
            textNode('For Statamic specifically, you need to handle the '),
            textNode('flat-file storage', [linkMark(internalHref())]),
            textNode(' differently than database-backed CMS solutions. Volume mounts for the content directory are essential.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 20. German: Datenbankoptimierung
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Datenbankoptimierung: MySQL vs PostgreSQL',
    'nodes' => [
        heading(2, [textNode('Welche Datenbank für Ihr Projekt?')]),
        paragraph([
            textNode('Die Wahl zwischen '),
            textNode('MySQL', [linkMark('https://www.mysql.com')]),
            textNode(' und '),
            textNode('PostgreSQL', [linkMark('https://www.postgresql.org')]),
            textNode(' hängt von den spezifischen Anforderungen Ihres Projekts ab. Beide Systeme haben ihre Stärken.'),
        ]),
        tableNode([
            [
                [textNode('Kriterium')],
                [textNode('MySQL')],
                [textNode('PostgreSQL')],
            ],
            [
                [textNode('JSON-Support')],
                [textNode('Grundlegend')],
                [textNode('Erweitert (JSONB)', [boldMark()])],
            ],
            [
                [textNode('Volltextsuche')],
                [textNode('Eingebaut')],
                [textNode('Sehr leistungsfähig', [linkMark(externalHref())])],
            ],
            [
                [textNode('Replikation')],
                [textNode('Master-Slave', [linkMark(internalHref())])],
                [textNode('Streaming Replication')],
            ],
        ], true),
        paragraph([
            textNode('Für '),
            textNode('Laravel-Anwendungen', [linkMark(internalHref())]),
            textNode(' ist MySQL oft die einfachere Wahl, während PostgreSQL bei komplexen Abfragen und JSON-Verarbeitung überlegen ist.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 21. Core Web Vitals
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Core Web Vitals: Measuring What Matters',
    'nodes' => [
        heading(2, [textNode('The Three Pillars of Web Performance')]),
        paragraph([
            textNode('Google\'s '),
            textNode('Core Web Vitals', [boldMark(), linkMark('https://web.dev/articles/vitals')]),
            textNode(' are a set of user-centric performance metrics that directly impact your '),
            textNode('search rankings', [linkMark(internalHref())]),
            textNode('.'),
        ]),
        bulletList([
            [
                textNode('LCP (Largest Contentful Paint)', [boldMark()]),
                textNode(' — Measures loading performance. Should be under 2.5 seconds.'),
            ],
            [
                textNode('INP (Interaction to Next Paint)', [boldMark()]),
                textNode(' — Measures interactivity. Should be under 200ms.'),
            ],
            [
                textNode('CLS (Cumulative Layout Shift)', [boldMark()]),
                textNode(' — Measures visual stability. Should be under 0.1.'),
            ],
        ]),
        heading(3, [textNode('Optimization Strategies')]),
        paragraph([
            textNode('Image optimization is often the quickest win. Use '),
            textNode('modern formats', [linkMark(internalHref())]),
            textNode(' (WebP, AVIF) and implement '),
            textNode('lazy loading', [boldMark()]),
            textNode(' for below-the-fold content.'),
        ]),
        imageNode('asset::assets/images/core-web-vitals-chart.png', 'Core Web Vitals performance metrics chart'),
    ],
];

// ---------------------------------------------------------------------------
// 22. Content clusters / pillar pages
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Content Clusters: The Modern SEO Architecture',
    'nodes' => [
        heading(2, [textNode('What Are Content Clusters?')]),
        paragraph([
            textNode('A '),
            textNode('content cluster', [boldMark(), italicMark()]),
            textNode(' is a group of interlinked pages centered around a '),
            textNode('pillar page', [linkMark(internalHref())]),
            textNode('. The pillar covers a broad topic, while cluster pages dive deep into subtopics — all connected via '),
            textNode('internal links', [linkMark(internalHref())]),
            textNode('.'),
        ]),
        heading(3, [
            textNode('Example: '),
            textNode('SEO Pillar Page', [linkMark(internalHref())]),
        ]),
        bulletList([
            [
                textNode('Pillar: "Complete SEO Guide" → links to all cluster pages'),
            ],
            [
                textNode('Cluster: "'),
                textNode('Keyword Research', [linkMark(internalHref())]),
                textNode('" → links back to pillar'),
            ],
            [
                textNode('Cluster: "'),
                textNode('Technical SEO', [linkMark(internalHref())]),
                textNode('" → links to pillar + related clusters'),
            ],
            [
                textNode('Cluster: "'),
                textNode('Link Building', [linkMark(internalHref())]),
                textNode('" → links to pillar + related clusters'),
            ],
        ]),
        blockquote([
            paragraph([
                textNode('Without proper internal linking, even the best content cluster falls apart. '),
                textNode('Linkwise', [boldMark()]),
                textNode(' automates the connection between your cluster pages and pillar content.'),
            ]),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 23. Security article with code blocks
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Web Security Essentials: OWASP Top 10',
    'nodes' => [
        heading(2, [textNode('Protecting Your Application')]),
        paragraph([
            textNode('The '),
            textNode('OWASP Top 10', [linkMark('https://owasp.org/www-project-top-ten/')]),
            textNode(' lists the most critical web application security risks. Every developer should understand these threats and implement proper mitigations.'),
        ]),
        heading(3, [textNode('XSS Prevention')]),
        paragraph([
            textNode('Cross-Site Scripting (XSS) remains one of the most common vulnerabilities. Always escape user input and use '),
            textNode('Content Security Policy', [linkMark(internalHref())]),
            textNode(' headers.'),
        ]),
        codeBlock("// Laravel automatically escapes output in Blade\n{{ \$userInput }}  {{-- Safe: HTML entities escaped --}}\n{!! \$userInput !!}  {{-- Dangerous: raw HTML output --}}\n\n// For API responses, validate input:\n\$validated = \$request->validate([\n    'name' => 'required|string|max:255',\n    'email' => 'required|email',\n]);", 'php'),
        paragraph([
            textNode('For a deeper dive, see our articles on '),
            textNode('CSRF protection', [linkMark(internalHref())]),
            textNode(' and '),
            textNode('SQL injection prevention', [linkMark(internalHref())]),
            textNode('.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 24. E-Commerce SEO (German)
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'E-Commerce SEO: Produktseiten richtig optimieren',
    'nodes' => [
        heading(2, [textNode('Interne Verlinkung im Online-Shop')]),
        paragraph([
            textNode('Für '),
            textNode('E-Commerce-Websites', [boldMark()]),
            textNode(' ist eine durchdachte '),
            textNode('interne Verlinkungsstruktur', [linkMark(internalHref())]),
            textNode(' besonders wichtig. Kategorieseiten, Produktseiten und Blogartikel müssen sinnvoll miteinander verknüpft werden.'),
        ]),
        orderedList([
            [
                textNode('Kategorieseiten', [boldMark()]),
                textNode(' verlinken auf alle enthaltenen Produkte'),
            ],
            [
                textNode('Produktseiten verlinken auf '),
                textNode('verwandte Produkte', [linkMark(internalHref())]),
            ],
            [
                textNode('Blog-Artikel verlinken auf relevante '),
                textNode('Kategorien und Produkte', [linkMark(internalHref())]),
            ],
            [
                textNode('Die '),
                textNode('Startseite', [linkMark(internalHref())]),
                textNode(' verlinkt auf die wichtigsten Kategorien'),
            ],
        ]),
        paragraph([
            textNode('Lesen Sie auch unseren Artikel über '),
            textNode('Schema Markup für Produkte', [linkMark(externalHref())]),
            textNode(' und '),
            textNode('strukturierte Daten', [boldMark(), italicMark(), linkMark('https://schema.org/Product')]),
            textNode('.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 25. Headless CMS article with multiple link types
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Headless CMS Architecture: API-First Content',
    'nodes' => [
        heading(2, [textNode('What Makes a CMS "Headless"?')]),
        paragraph([
            textNode('A '),
            textNode('headless CMS', [boldMark()]),
            textNode(' decouples the content repository from the presentation layer. Content is delivered via '),
            textNode('APIs', [linkMark(internalHref())]),
            textNode(' (REST or '),
            textNode('GraphQL', [linkMark(externalHref())]),
            textNode(') and consumed by any frontend framework.'),
        ]),
        heading(3, [textNode('Statamic as a Headless CMS')]),
        paragraph([
            textNode('Statamic can operate in '),
            textNode('headless mode', [linkMark('https://statamic.dev/rest-api')]),
            textNode(' with its built-in REST API. This makes it possible to use '),
            textNode('Next.js', [linkMark('https://nextjs.org')]),
            textNode(', '),
            textNode('Nuxt', [linkMark('https://nuxt.com')]),
            textNode(', or any other frontend while keeping Statamic\'s powerful '),
            textNode('content editing experience', [linkMark(internalHref())]),
            textNode('.'),
        ]),
        setNode('buttons', [
            'buttons' => [
                [
                    'id' => randomId(),
                    'label' => 'View REST API Docs',
                    'target_blank' => true,
                    'link_type' => 'url',
                    'url' => 'https://statamic.dev/rest-api',
                    'button_type' => 'button',
                    'type' => 'button',
                    'enabled' => true,
                ],
            ],
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 26. Testing article
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Test-Driven Development in Laravel Projects',
    'nodes' => [
        heading(2, [textNode('Why TDD?')]),
        paragraph([
            textNode('Test-driven development forces you to think about your code\'s behavior before writing implementation. It leads to '),
            textNode('cleaner APIs', [linkMark(internalHref())]),
            textNode(', fewer bugs, and more maintainable code.'),
        ]),
        heading(3, [textNode('The Red-Green-Refactor Cycle')]),
        orderedList([
            [
                textNode('Red', [boldMark()]),
                textNode(' — Write a failing test that defines expected behavior'),
            ],
            [
                textNode('Green', [boldMark()]),
                textNode(' — Write the minimum code to make the test pass'),
            ],
            [
                textNode('Refactor', [boldMark()]),
                textNode(' — Improve the code while keeping tests green'),
            ],
        ]),
        codeBlock("class SuggestionEngineTest extends TestCase\n{\n    /** @test */\n    public function it_finds_matching_anchor_text()\n    {\n        \$engine = new SuggestionEngine();\n        \$suggestions = \$engine->suggest(\$entry);\n\n        \$this->assertNotEmpty(\$suggestions);\n        \$this->assertEquals('Laravel', \$suggestions[0]->anchorText);\n    }\n}", 'php'),
        paragraph([
            textNode('For more on testing Statamic addons, see the '),
            textNode('official testing guide', [linkMark('https://statamic.dev/extending/testing')]),
            textNode(' and our '),
            textNode('PHPUnit best practices', [linkMark(internalHref())]),
            textNode(' article.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 27. Mixed English/German — Multilingual content
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Multilingual SEO: Hreflang und internationale Optimierung',
    'nodes' => [
        heading(2, [textNode('Managing Multilingual Content')]),
        paragraph([
            textNode('When running a multilingual website, '),
            textNode('hreflang tags', [boldMark()]),
            textNode(' tell search engines which language version to show users. Incorrect implementation can lead to '),
            textNode('duplicate content issues', [linkMark(internalHref())]),
            textNode('.'),
        ]),
        heading(3, [textNode('Implementierung in Statamic')]),
        paragraph([
            textNode('Statamic\'s '),
            textNode('Multi-Site-Funktion', [linkMark('https://statamic.dev/multi-site')]),
            textNode(' ermöglicht die Verwaltung mehrerer Sprachversionen. Jede Version kann eigene '),
            textNode('interne Verlinkungen', [linkMark(internalHref())]),
            textNode(' haben, die von Linkwise separat analysiert werden.'),
        ]),
        bulletList([
            [
                textNode('English: '),
                textNode('/blog/internal-linking-guide', [linkMark(internalHref())]),
            ],
            [
                textNode('Deutsch: '),
                textNode('/de/blog/interne-verlinkung-leitfaden', [linkMark(internalHref())]),
            ],
            [
                textNode('Français: '),
                textNode('/fr/blog/guide-liens-internes', [linkMark(internalHref())]),
            ],
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 28. Performance / Caching
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Caching Strategies for Statamic Sites',
    'nodes' => [
        heading(2, [textNode('Why Cache?')]),
        paragraph([
            textNode('Statamic\'s '),
            textNode('flat-file architecture', [boldMark()]),
            textNode(' is already fast, but caching can take performance to the next level. The '),
            textNode('Static Caching', [linkMark('https://statamic.dev/static-caching')]),
            textNode(' feature serves pre-rendered HTML, bypassing PHP entirely.'),
        ]),
        heading(3, [textNode('Cache Invalidation')]),
        paragraph([
            textNode('"There are only two hard things in computer science: cache invalidation and naming things." When content changes, caches must be cleared. Linkwise handles this by '),
            textNode('automatically re-indexing', [linkMark(internalHref())]),
            textNode(' when entries are saved.'),
        ]),
        blockquote([
            paragraph([
                textNode('A well-configured cache can reduce server load by 90% while improving '),
                textNode('Core Web Vitals', [linkMark(internalHref())]),
                textNode(' scores dramatically.'),
            ]),
        ]),
        paragraph([
            textNode('For production deployment, combine '),
            textNode('static caching', [linkMark(internalHref())]),
            textNode(' with a '),
            textNode('CDN like Cloudflare', [linkMark('https://www.cloudflare.com')]),
            textNode(' for optimal performance worldwide.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 29. Broken links + edge cases entry
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'Link Health: Detecting and Fixing Broken Links',
    'nodes' => [
        heading(2, [textNode('Why Broken Links Hurt Your SEO')]),
        paragraph([
            textNode('Broken links create a poor user experience and waste '),
            textNode('crawl budget', [boldMark()]),
            textNode('. Search engines may lower your rankings if they encounter too many '),
            textNode('404 errors', [linkMark(internalHref())]),
            textNode(' during crawling.'),
        ]),
        heading(3, [textNode('Common Causes')]),
        bulletList([
            [
                textNode('Deleted pages without '),
                textNode('redirects', [linkMark(internalHref())]),
            ],
            [
                textNode('Typos in URLs (e.g., '),
                textNode('this broken link', [linkMark('https://this-domain-does-not-exist-404.com/page')]),
                textNode(')'),
            ],
            [
                textNode('Changed URL slugs after publishing'),
            ],
            [
                textNode('External sites that went offline — like '),
                textNode('this outdated reference', [linkMark('https://expired-domain-example.invalid/old-article')]),
            ],
        ]),
        paragraph([
            textNode('Linkwise\'s '),
            textNode('broken link checker', [boldMark(), linkMark(internalHref())]),
            textNode(' scans your site regularly and alerts you to any issues in the '),
            textNode('Links Report dashboard', [linkMark(internalHref())]),
            textNode('.'),
        ]),
    ],
];

// ---------------------------------------------------------------------------
// 30. Long-form pillar page with everything
// ---------------------------------------------------------------------------
$entries[] = [
    'title' => 'The Complete Guide to Internal Linking for Statamic',
    'nodes' => [
        heading(2, [textNode('Introduction')]),
        paragraph([
            textNode('Internal linking is the practice of connecting pages within your website through '),
            textNode('hyperlinks', [boldMark()]),
            textNode('. When done correctly, it improves both '),
            textNode('user experience', [linkMark(internalHref())]),
            textNode(' and '),
            textNode('search engine optimization', [linkMark(internalHref())]),
            textNode('.'),
        ]),
        heading(2, [textNode('Why Internal Links Matter')]),
        paragraph([
            textNode('Search engines like Google use internal links to discover new pages and understand the relationship between content. '),
            textNode('PageRank', [italicMark()]),
            textNode(' flows through internal links, distributing authority across your site.'),
        ]),
        blockquote([
            paragraph([
                textNode('Internal links are the highways of your website. Without them, search engines and users get lost.'),
            ]),
        ]),
        heading(2, [textNode('Best Practices')]),
        heading(3, [
            textNode('1. Use '),
            textNode('Descriptive Anchor Text', [linkMark(internalHref())]),
        ]),
        paragraph([
            textNode('Avoid "click here" or "read more." Instead, use anchor text that describes the target page, like "'),
            textNode('our guide to keyword research', [linkMark(internalHref())]),
            textNode('."'),
        ]),
        heading(3, [textNode('2. Link Deep')]),
        paragraph([
            textNode('Don\'t just link to your homepage or category pages. Deep links to specific articles and resources are more valuable for '),
            textNode('SEO', [boldMark()]),
            textNode(' and '),
            textNode('user navigation', [italicMark()]),
            textNode('.'),
        ]),
        heading(3, [textNode('3. Maintain a Logical Structure')]),
        bulletList([
            [
                textNode('Pillar pages', [boldMark()]),
                textNode(' → link to all cluster pages'),
            ],
            [
                textNode('Cluster pages', [boldMark()]),
                textNode(' → link back to pillar + sibling clusters'),
            ],
            [
                textNode('Blog posts → link to '),
                textNode('relevant categories', [linkMark(internalHref())]),
            ],
            [
                textNode('Product pages → link to '),
                textNode('related products', [linkMark(internalHref())]),
                textNode(' and '),
                textNode('buying guides', [linkMark(internalHref())]),
            ],
        ]),
        heading(2, [textNode('Tools for Internal Linking')]),
        tableNode([
            [
                [textNode('Tool')],
                [textNode('Platform')],
                [textNode('Key Feature')],
            ],
            [
                [textNode('Linkwise', [boldMark(), linkMark(internalHref())])],
                [textNode('Statamic')],
                [textNode('AI-powered suggestions')],
            ],
            [
                [textNode('Link Whisper', [linkMark('https://linkwhisper.com')])],
                [textNode('WordPress')],
                [textNode('Automated suggestions')],
            ],
            [
                [textNode('Yoast SEO', [linkMark('https://yoast.com')])],
                [textNode('WordPress')],
                [textNode('Orphan content detection')],
            ],
        ], true),
        heading(2, [textNode('Automating with Linkwise')]),
        paragraph([
            textNode('Linkwise for Statamic automates the entire internal linking workflow. From '),
            textNode('discovering orphan pages', [linkMark(internalHref())]),
            textNode(' to '),
            textNode('suggesting link targets', [linkMark(internalHref())]),
            textNode(', it handles what would otherwise take hours of manual work.'),
        ]),
        setNode('image', [
            'image' => 'linkwise-dashboard.png',
            'size' => 'xl',
            'caption' => 'The Linkwise Links Report dashboard showing internal link health metrics.',
        ]),
        paragraph([
            textNode('Get started by installing Linkwise from the '),
            textNode('Statamic Marketplace', [linkMark('https://statamic.com/addons')]),
            textNode(' and running the initial index. The '),
            textNode('auto-linking feature', [boldMark(), linkMark(internalHref())]),
            textNode(' will immediately suggest improvements.'),
        ]),
    ],
];

// ===========================================================================
// Generate all entries
// ===========================================================================

echo "Generating " . count($entries) . " Bard page entries in $pagesDir...\n\n";

foreach ($entries as $i => $entry) {
    $id = $generatedIds[$i];
    $title = $entry['title'];
    $nodes = $entry['nodes'];

    // Generate slug from title
    $slug = $prefix . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
    $slug = trim($slug, '-');

    $yaml = renderEntry($id, $title, $nodes);
    $filePath = "$pagesDir/$slug.md";
    file_put_contents($filePath, $yaml);

    $linkCount = substr_count($yaml, 'type: link');
    $nodeTypes = [];
    if (strpos($yaml, 'type: heading') !== false) $nodeTypes[] = 'headings';
    if (strpos($yaml, 'type: bulletList') !== false) $nodeTypes[] = 'bulletList';
    if (strpos($yaml, 'type: orderedList') !== false) $nodeTypes[] = 'orderedList';
    if (strpos($yaml, 'type: table') !== false) $nodeTypes[] = 'table';
    if (strpos($yaml, 'type: blockquote') !== false) $nodeTypes[] = 'blockquote';
    if (strpos($yaml, 'type: codeBlock') !== false) $nodeTypes[] = 'codeBlock';
    if (strpos($yaml, 'type: image') !== false) $nodeTypes[] = 'image';
    if (strpos($yaml, 'type: set') !== false) $nodeTypes[] = 'set';
    if (strpos($yaml, 'type: bold') !== false) $nodeTypes[] = 'bold';
    if (strpos($yaml, 'type: italic') !== false) $nodeTypes[] = 'italic';
    if (strpos($yaml, 'type: hardBreak') !== false) $nodeTypes[] = 'hardBreak';

    printf(
        "  [%2d/30] %-55s %2d links  [%s]\n",
        $i + 1,
        mb_substr($title, 0, 55),
        $linkCount,
        implode(', ', $nodeTypes)
    );
}

echo "\nGenerated " . count($entries) . " page entries with Bard/ProseMirror content.\n";
echo "IDs available for linking: " . count($articleIds) . " articles + " . count($existingPageIds) . " existing pages + " . count($generatedIds) . " generated\n";
echo "\nNext steps:\n";
echo "  1. php artisan statamic:stache:clear\n";
echo "  2. php artisan linkwise:index\n";
echo "  3. Verify in CP → Pages that all entries appear\n";
echo "  4. Cleanup: php " . $argv[0] . " --cleanup\n";

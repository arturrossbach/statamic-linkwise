#!/usr/bin/env php
<?php
/**
 * Stress-test content generator for Linkwise.
 * Generates 300+ Statamic entries with diverse content patterns.
 *
 * Usage: cd /path/to/statamic-app && php /path/to/statamic-linkwise/tests/Integration/generate-stress-content.php
 *        To remove: php ... --cleanup
 */

$cleanup = in_array('--cleanup', $argv ?? []);
$articlesDir = getcwd() . '/content/collections/articles';
$prefix = 'stress-'; // All generated files start with this

if ($cleanup) {
    $files = glob("$articlesDir/{$prefix}*.md");
    foreach ($files as $f) unlink($f);
    echo "Cleaned up " . count($files) . " stress-test entries.\n";
    exit(0);
}

if (!is_dir($articlesDir)) {
    echo "Error: $articlesDir not found. Run from the Statamic app root.\n";
    exit(1);
}

// Load existing entry IDs for internal links
$existingIds = [];
foreach (glob("$articlesDir/*.md") as $file) {
    $content = file_get_contents($file);
    if (preg_match('/^id:\s*(.+)$/m', $content, $m)) {
        $existingIds[] = trim($m[1]);
    }
}

$topics = [
    'PHP' => ['Laravel', 'Symfony', 'Composer', 'PHPUnit', 'PHP 8.4', 'Eloquent ORM', 'Middleware', 'Service Container'],
    'JavaScript' => ['React', 'Vue.js', 'TypeScript', 'Node.js', 'Vite', 'Webpack', 'ESLint', 'Prettier'],
    'CSS' => ['Tailwind CSS', 'Flexbox', 'CSS Grid', 'Responsive Design', 'Dark Mode', 'Animations', 'Container Queries'],
    'DevOps' => ['Docker', 'Kubernetes', 'CI/CD', 'GitHub Actions', 'Terraform', 'AWS Lambda', 'Nginx'],
    'Database' => ['MySQL', 'PostgreSQL', 'Redis', 'MongoDB', 'SQLite', 'Migrations', 'Query Optimization'],
    'SEO' => ['Internal Linking', 'Meta Tags', 'Schema Markup', 'Core Web Vitals', 'Sitemap', 'Canonical URLs'],
    'CMS' => ['Statamic', 'WordPress', 'Headless CMS', 'Content Modeling', 'Flat-File CMS', 'API-First'],
    'Security' => ['OWASP', 'XSS Prevention', 'CSRF Protection', 'SQL Injection', 'Rate Limiting', 'Authentication'],
    'Testing' => ['Unit Tests', 'Integration Tests', 'E2E Tests', 'TDD', 'Mocking', 'Code Coverage'],
    'Performance' => ['Caching', 'CDN', 'Lazy Loading', 'Image Optimization', 'HTTP/2', 'Service Workers'],
];

$paragraphs = [
    "Modern web development requires a solid understanding of both frontend and backend technologies. Teams that master the full stack can deliver features faster and with fewer handoffs.",
    "When building scalable applications, it's crucial to consider performance from the start. Database queries, caching strategies, and CDN configuration all play a role.",
    "Security should never be an afterthought. Following OWASP guidelines and implementing proper authentication ensures your application remains protected against common attack vectors.",
    "Test-driven development helps catch bugs early and provides confidence when refactoring. A comprehensive test suite is worth the initial investment in time.",
    "Content management systems have evolved significantly. Modern flat-file CMS solutions like Statamic offer developer-friendly workflows without the overhead of a database.",
    "Internal linking is one of the most effective SEO strategies. It helps search engines understand your site structure and distributes page authority across your content.",
    "Docker containers provide consistent development environments across teams. Combined with CI/CD pipelines, they enable reliable and repeatable deployments.",
    "TypeScript's adoption continues to grow as teams recognize the value of static type checking. It catches errors at compile time rather than in production.",
    "Redis caching can dramatically improve application performance. Whether used for session storage, queue management, or API response caching, it's a versatile tool.",
    "Responsive design is no longer optional. With mobile traffic exceeding desktop, every interface must adapt gracefully to different screen sizes.",
    "Vue.js provides a progressive framework that scales from simple widgets to complex single-page applications. Its reactivity system makes state management intuitive.",
    "PostgreSQL offers advanced features like JSON columns, full-text search, and window functions that make it ideal for complex data requirements.",
    "Kubernetes orchestration handles container scaling, load balancing, and self-healing automatically. It's the industry standard for production container management.",
    "GraphQL provides a flexible alternative to REST APIs. Clients can request exactly the data they need, reducing over-fetching and under-fetching.",
    "Accessibility (WCAG compliance) ensures your application is usable by everyone, including people with disabilities. It's both an ethical obligation and a legal requirement in many jurisdictions.",
];

// German paragraphs for multibyte testing
$germanParagraphs = [
    "Die Suchmaschinenoptimierung (SEO) umfasst viele Aspekte, darunter technische Optimierung, Content-Strategie und Linkbuilding. Interne Verlinkung ist dabei besonders wichtig.",
    "Moderne Webanwendungen nutzen häufig eine Microservice-Architektur. Jeder Service kann unabhängig entwickelt, getestet und bereitgestellt werden.",
    "Bei der Datenbankoptimierung spielen Indizes eine entscheidende Rolle. Ohne passende Indizes können selbst einfache Abfragen bei großen Datenmengen langsam werden.",
    "Die Barrierefreiheit (WCAG-Konformität) stellt sicher, dass Ihre Anwendung für alle nutzbar ist — einschließlich Menschen mit Behinderungen.",
    "Caching-Strategien können die Performance einer Anwendung dramatisch verbessern. Redis, Memcached und CDN-Caching reduzieren die Serverlast erheblich.",
    "Für die Versionskontrolle ist Git unverzichtbar. Feature-Branches, Pull Requests und Code Reviews gehören zum Standard-Workflow.",
];

$generatedIds = [];
$entryCount = 0;

function generateUuid(): string {
    return sprintf('%s-%s-%s-%s-%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6))
    );
}

function randomParagraph(): string {
    global $paragraphs;
    return $paragraphs[array_rand($paragraphs)];
}

function randomGermanParagraph(): string {
    global $germanParagraphs;
    return $germanParagraphs[array_rand($germanParagraphs)];
}

function randomInternalLink(string $anchor): string {
    global $existingIds, $generatedIds;
    $allIds = array_merge($existingIds, $generatedIds);
    if (empty($allIds)) return $anchor;
    $targetId = $allIds[array_rand($allIds)];
    return "[$anchor](statamic://entry::$targetId)";
}

function randomExternalLink(string $anchor): string {
    $domains = ['https://www.example.com', 'https://docs.laravel.com', 'https://vuejs.org',
        'https://www.php.net', 'https://developer.mozilla.org', 'https://www.google.com',
        'https://github.com', 'https://stackoverflow.com', 'https://www.youtube.com',
        'https://de.wikipedia.org/wiki/Suchmaschinenoptimierung'];
    return "[$anchor](" . $domains[array_rand($domains)] . "/page-" . rand(1, 999) . ")";
}

function generateMarkdownEntry(string $title, string $slug, array $options = []): string {
    $id = generateUuid();
    $GLOBALS['generatedIds'][] = $id;

    $selfLink = $options['selfLink'] ?? false;
    $german = $options['german'] ?? false;
    $empty = $options['empty'] ?? false;
    $long = $options['long'] ?? false;
    $manyLinks = $options['manyLinks'] ?? false;
    $duplicateAnchors = $options['duplicateAnchors'] ?? false;
    $noLinks = $options['noLinks'] ?? false;
    $brokenLinks = $options['brokenLinks'] ?? false;
    $linksInLists = $options['linksInLists'] ?? false;
    $linksInHeadings = $options['linksInHeadings'] ?? false;
    $specialChars = $options['specialChars'] ?? false;

    $body = '';

    if ($empty) {
        // Intentionally empty content
    } elseif ($long) {
        // Very long content (3000+ words)
        for ($i = 0; $i < 30; $i++) {
            $body .= "\n\n## Section " . ($i + 1) . "\n\n";
            $body .= $german ? randomGermanParagraph() : randomParagraph();
            $body .= "\n\n" . ($german ? randomGermanParagraph() : randomParagraph());
            if ($i % 5 === 0) {
                $body .= "\n\n" . randomInternalLink("related topic " . ($i + 1));
            }
        }
    } else {
        $paraCount = rand(3, 8);
        for ($i = 0; $i < $paraCount; $i++) {
            $para = $german ? randomGermanParagraph() : randomParagraph();

            // Insert links based on options
            if ($manyLinks && $i < 5) {
                $words = explode(' ', $para);
                $insertAt = rand(3, min(8, count($words) - 1));
                $anchor = $words[$insertAt] . ' ' . ($words[$insertAt + 1] ?? 'tool');
                $words[$insertAt] = rand(0, 1) ? randomInternalLink($anchor) : randomExternalLink($anchor);
                if (isset($words[$insertAt + 1])) unset($words[$insertAt + 1]);
                $para = implode(' ', $words);
            } elseif (!$noLinks && rand(0, 2) === 0) {
                $words = explode(' ', $para);
                if (count($words) > 5) {
                    $insertAt = rand(2, count($words) - 3);
                    $anchor = $words[$insertAt];
                    $words[$insertAt] = rand(0, 1) ? randomInternalLink($anchor) : randomExternalLink($anchor);
                    $para = implode(' ', $words);
                }
            }

            $body .= "\n\n" . $para;
        }

        if ($duplicateAnchors) {
            $anchor = 'performance optimization';
            $body .= "\n\nWhen it comes to " . randomInternalLink($anchor) . ", there are many strategies.";
            $body .= "\n\nAdvanced " . randomExternalLink($anchor) . " requires deep understanding.";
            $body .= "\n\nFor " . randomInternalLink($anchor) . ", start with profiling.";
        }

        if ($selfLink) {
            $body .= "\n\nFor more details, see " . randomInternalLink("this article") . ".";
            // Actually link to self
            $body = str_replace(
                "[this article](statamic://entry::" . ($GLOBALS['generatedIds'][count($GLOBALS['generatedIds']) - 2] ?? $id) . ")",
                "[this article](statamic://entry::$id)",
                $body
            );
        }

        if ($brokenLinks) {
            $body .= "\n\n[click here](statamic://entry::00000000-0000-0000-0000-000000000000)";
            $body .= "\n\n[broken external](https://this-domain-does-not-exist-404.com/page)";
        }

        if ($linksInLists) {
            $body .= "\n\n- First item with " . randomInternalLink("a link") . " inside";
            $body .= "\n- Second item referencing " . randomExternalLink("external resource");
            $body .= "\n- Third item about " . randomInternalLink("another topic");
        }

        if ($linksInHeadings) {
            $body .= "\n\n## " . randomInternalLink("Linked Heading Example");
            $body .= "\n\nContent after the linked heading.";
        }

        if ($specialChars) {
            $body .= "\n\nIt's important to handle special characters — like em-dashes, curly quotes, and Umlaute correctly.";
            $body .= "\n\n" . randomInternalLink('C++ and JavaScript') . ' are both important languages.';
        }
    }

    return "---\ntitle: '$title'\nid: $id\n---\n$body\n";
}

echo "Generating stress-test entries in $articlesDir...\n";

// Category 1: Normal articles (150)
foreach ($topics as $category => $keywords) {
    foreach ($keywords as $i => $keyword) {
        $title = "$keyword Best Practices for Modern Development";
        $slug = $prefix . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
        $content = generateMarkdownEntry($title, $slug);
        file_put_contents("$articlesDir/$slug.md", $content);
        $entryCount++;

        $title = "Getting Started with $keyword in 2026";
        $slug = $prefix . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
        $content = generateMarkdownEntry($title, $slug);
        file_put_contents("$articlesDir/$slug.md", $content);
        $entryCount++;
    }
}

// Category 2: German content (20)
for ($i = 0; $i < 20; $i++) {
    $titles = [
        "Suchmaschinenoptimierung für Einsteiger Teil $i",
        "Datenbankoptimierung mit MySQL und PostgreSQL $i",
        "Barrierefreiheit im Web: WCAG-Richtlinien $i",
        "Moderne PHP-Entwicklung mit Laravel $i",
    ];
    $title = $titles[$i % count($titles)];
    $slug = $prefix . strtolower(preg_replace('/[^a-z0-9äöü]+/iu', '-', $title));
    $content = generateMarkdownEntry($title, $slug, ['german' => true]);
    file_put_contents("$articlesDir/$slug.md", $content);
    $entryCount++;
}

// Category 3: Empty content (5)
for ($i = 0; $i < 5; $i++) {
    $title = "Empty Draft Entry $i";
    $slug = "{$prefix}empty-$i";
    $content = generateMarkdownEntry($title, $slug, ['empty' => true]);
    file_put_contents("$articlesDir/$slug.md", $content);
    $entryCount++;
}

// Category 4: Very long content (10)
for ($i = 0; $i < 10; $i++) {
    $title = "Comprehensive Guide to " . array_keys($topics)[array_rand($topics)] . " Part $i";
    $slug = $prefix . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
    $content = generateMarkdownEntry($title, $slug, ['long' => true]);
    file_put_contents("$articlesDir/$slug.md", $content);
    $entryCount++;
}

// Category 5: Many links (15)
for ($i = 0; $i < 15; $i++) {
    $title = "Link-Heavy Resource Guide $i";
    $slug = "{$prefix}link-heavy-$i";
    $content = generateMarkdownEntry($title, $slug, ['manyLinks' => true]);
    file_put_contents("$articlesDir/$slug.md", $content);
    $entryCount++;
}

// Category 6: Duplicate anchors (10)
for ($i = 0; $i < 10; $i++) {
    $title = "Duplicate Anchor Test Entry $i";
    $slug = "{$prefix}dup-anchor-$i";
    $content = generateMarkdownEntry($title, $slug, ['duplicateAnchors' => true]);
    file_put_contents("$articlesDir/$slug.md", $content);
    $entryCount++;
}

// Category 7: Self-links (5)
for ($i = 0; $i < 5; $i++) {
    $title = "Self-Referencing Article $i";
    $slug = "{$prefix}self-link-$i";
    $content = generateMarkdownEntry($title, $slug, ['selfLink' => true]);
    file_put_contents("$articlesDir/$slug.md", $content);
    $entryCount++;
}

// Category 8: No links (10)
for ($i = 0; $i < 10; $i++) {
    $title = "Orphaned Content Without Links $i";
    $slug = "{$prefix}no-links-$i";
    $content = generateMarkdownEntry($title, $slug, ['noLinks' => true]);
    file_put_contents("$articlesDir/$slug.md", $content);
    $entryCount++;
}

// Category 9: Broken links (5)
for ($i = 0; $i < 5; $i++) {
    $title = "Article With Broken Links $i";
    $slug = "{$prefix}broken-links-$i";
    $content = generateMarkdownEntry($title, $slug, ['brokenLinks' => true]);
    file_put_contents("$articlesDir/$slug.md", $content);
    $entryCount++;
}

// Category 10: Links in lists and headings (10)
for ($i = 0; $i < 10; $i++) {
    $title = "Structured Content With Links $i";
    $slug = "{$prefix}structured-$i";
    $content = generateMarkdownEntry($title, $slug, ['linksInLists' => true, 'linksInHeadings' => true]);
    file_put_contents("$articlesDir/$slug.md", $content);
    $entryCount++;
}

// Category 11: Special characters (10)
for ($i = 0; $i < 10; $i++) {
    $title = "Special Characters: Ümlaute & \"Quotes\" — Em-Dashes $i";
    $slug = "{$prefix}special-chars-$i";
    $content = generateMarkdownEntry($title, $slug, ['specialChars' => true, 'german' => ($i % 2 === 0)]);
    file_put_contents("$articlesDir/$slug.md", $content);
    $entryCount++;
}

// Category 12: Combined edge cases (10)
for ($i = 0; $i < 10; $i++) {
    $title = "Edge Case Combo Entry $i";
    $slug = "{$prefix}edge-combo-$i";
    $content = generateMarkdownEntry($title, $slug, [
        'manyLinks' => true,
        'duplicateAnchors' => true,
        'linksInLists' => true,
        'specialChars' => true,
    ]);
    file_put_contents("$articlesDir/$slug.md", $content);
    $entryCount++;
}

echo "Generated $entryCount stress-test entries.\n";
echo "Total IDs available for linking: " . count($existingIds) . " existing + " . count($generatedIds) . " generated\n";
echo "\nNext steps:\n";
echo "  1. php artisan statamic:stache:clear\n";
echo "  2. php artisan linkwise:index\n";
echo "  3. Run integration tests\n";
echo "  4. Cleanup: php " . $argv[0] . " --cleanup\n";

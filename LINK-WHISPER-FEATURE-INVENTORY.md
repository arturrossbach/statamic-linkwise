# Link Whisper: Complete Feature Inventory for Linkwise

**Compiled:** 2026-03-30
**Sources:** LINK-WHISPER-ANALYSIS.md (577 lines), linkwhisper.com knowledge base, webtimiser.de review, helpscout docs, web search results (2025-2026 reviews)

---

## 1. EDITOR -- Suggestions & Link Insertion

### 1.01 Outbound Link Suggestions (In-Editor Panel)
- **Description:** When editing a post, a panel below the editor scans your content and suggests relevant internal pages to link to. Shows anchor text phrases from your content paired with proposed target pages.
- **Plan:** Both (Free = manual copy-paste; Premium = one-click checkbox insertion)
- **Priority for Statamic clone:** CRITICAL
- **Linkwise status:** DONE

### 1.02 Inbound Link Suggestions (Reverse Linking)
- **Description:** For the current entry, finds sentences across your entire site where a link TO this entry would be relevant. Answers "what other posts should link to THIS post." One-click insertion into the source entry's content.
- **Plan:** Premium
- **Priority for Statamic clone:** CRITICAL
- **Linkwise status:** DONE

### 1.03 One-Click Link Insertion (Checkbox + "Insert Links" Button)
- **Description:** Users check boxes next to desired suggestions, then click a single "Insert Links Into Post" button to insert all selected links at once as standard HTML anchor tags.
- **Plan:** Premium (Free requires manual copy-paste)
- **Priority for Statamic clone:** CRITICAL
- **Linkwise status:** DONE

### 1.04 Anchor Text Editing Before Insertion
- **Description:** Before accepting a suggestion, the user can edit the anchor text to adjust length, wording, or relevance. Changes apply to the inserted link.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 1.05 Sentence Editing In-Plugin
- **Description:** Users can click "Edit Sentence" to modify the surrounding sentence context before link insertion. Link Whisper applies the edited sentence directly to the post content.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 1.06 Sentence Context Display
- **Description:** Each suggestion row shows the full sentence containing the suggested anchor text, with the anchor highlighted/underlined, so users can judge contextual relevance.
- **Plan:** Both
- **Priority for Statamic clone:** CRITICAL
- **Linkwise status:** DONE

### 1.07 Multiple Suggestions Per Sentence
- **Description:** A single sentence may produce multiple link suggestions (different anchor phrases or different target pages). All are shown as separate rows.
- **Plan:** Both
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** DONE

### 1.08 Skip Intro Sentences Setting
- **Description:** "Number of Sentences to Skip" -- configurable count of opening sentences excluded from suggestions to avoid overloading intro paragraphs with links.
- **Plan:** Both
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 1.09 Cross-Site Link Suggestions Section
- **Description:** When editing a post, a second section below the internal suggestions shows link opportunities from connected external sites running Link Whisper.
- **Plan:** Premium
- **Priority for Statamic clone:** SKIP
- **Linkwise status:** N/A

### 1.10 Page Builder Compatibility (Gutenberg, Classic, Elementor, Divi, etc.)
- **Description:** Suggestion panel and link insertion works across all major WordPress editors: Classic, Gutenberg, Beaver Builder, Thrive Architect, Elementor, WooCommerce, Kadence Blocks, Divi.
- **Plan:** Both (Free = suggestions only; Premium = insertion)
- **Priority for Statamic clone:** SKIP (Statamic uses Bard/TipTap exclusively)
- **Linkwise status:** N/A

### 1.11 Outbound Suggestion Toggle
- **Description:** Setting to enable/disable outbound link suggestions entirely in the editor panel.
- **Plan:** Both
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

---

## 2. REPORTS -- Dashboard & Analytics

### 2.01 Internal Links Report (Site-Wide Table)
- **Description:** Full-width sortable table showing every post/page with columns: title, inbound link count, outbound internal link count, outbound external link count. Expandable detail rows per entry.
- **Plan:** Both (Free = basic single report; Premium = full with multiple views)
- **Priority for Statamic clone:** CRITICAL
- **Linkwise status:** DONE

### 2.02 Orphaned Content Detection
- **Description:** Identifies posts with zero inbound internal links ("orphaned pages"). Highlighted in the report and surfaced as actionable items.
- **Plan:** Both (Free = basic count; Premium = advanced with fix tools)
- **Priority for Statamic clone:** CRITICAL
- **Linkwise status:** DONE

### 2.03 Link Health Dashboard (At-a-Glance Metrics)
- **Description:** Card-based dashboard with key metrics: total posts crawled, total links, total internal links, orphaned post count, broken link count, broken video link count, 404 errors, most-linked-to domains, internal vs. external link ratio.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** PARTIAL (basic stats shown on dashboard)

### 2.04 Report Filtering (Post Type, Category, Keyword Search)
- **Description:** Filter the links report by post type, categories/tags, or keyword search to narrow results.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** PARTIAL (collection filter exists)

### 2.05 Report Sorting (Sortable Columns)
- **Description:** Columns in the links report are sortable (by title, inbound count, outbound count) to quickly find under-linked or over-linked content.
- **Plan:** Both
- **Priority for Statamic clone:** CRITICAL
- **Linkwise status:** DONE

### 2.06 Expandable Detail Rows (Per-Entry Link Breakdown)
- **Description:** Click expand on any report row to see: all inbound source posts + anchor texts, all outbound target posts + anchor texts, all external links. Each with delete buttons.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 2.07 Link Deletion from Reports
- **Description:** Delete individual links directly from the report detail view without opening the editor. Removes the link from the source content.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 2.08 "Add Links" Quick Action (Per Entry in Report)
- **Description:** Button on each report row to jump directly to adding inbound or outbound links for that entry, without opening the full editor.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 2.09 Broken Link Checker / Error Report
- **Description:** Scans all internal and external links for broken URLs, 404 errors, and dead links. Automated WordPress cron scans every 10 minutes in batches of 10. Double-checks findings to avoid false positives. Report columns: source post, broken URL, anchor text, sentence context, link type, HTTP status code, discovery timestamp. Actions: edit URL, delete link.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 2.10 Broken Link Report -- Reset Data Button
- **Description:** First-time setup button to initialize the broken link scan data. Resets and restarts the scanning process.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 2.11 Broken Link Report -- Manual Scan Trigger
- **Description:** Ability to manually trigger a broken link scan even when automated checking is disabled.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 2.12 Linked-To Domains Report
- **Description:** Lists all external domains your site links to, with link count per domain and the posts where each domain appears. Allows per-domain configuration of link attributes.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 2.13 Click Tracking / Analytics Report
- **Description:** Tracks which internal links visitors actually click. Shows: total clicks per page for a timeframe, most-clicked link per page, line graph over time, per-link breakdown (URL, anchor text, total clicks, source post).
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 2.14 CSV Data Export
- **Description:** Export all internal linking data (links report, domains report, etc.) to CSV format for external analysis.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 2.15 Monthly Link Report Card
- **Description:** Automated report delivered every 30 days summarizing internal linking performance with actionable improvement recommendations and one-click fixes.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 2.16 AI Visual Sitemap (Topic Cluster Map)
- **Description:** Interactive node-and-edge graph showing how all posts relate topically. Green lines = existing links, blue lines = missing connections. Hover for details, right-click to create link. Requires OpenAI API key. Costs ~$7 for 3,200 articles.
- **Plan:** Premium (requires separate OpenAI API key)
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 2.17 Visual Sitemaps -- Inbound / Outbound / External Variants
- **Description:** Three separate visual sitemap views: inbound link sitemap, outbound link sitemap, external link sitemap. Each shows the respective linking network graphically.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 2.18 Link Relation Scores
- **Description:** Sentence-level relevance scores showing how strongly related two pieces of content are. Optional feature used in AI-powered suggestions.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

---

## 3. AUTOMATION -- Auto-Linking, Auto-Indexing

### 3.01 Auto-Linking (Keyword-to-URL Rules)
- **Description:** Define a keyword and destination URL. Link Whisper automatically finds ALL mentions of that keyword across the entire site (past and future posts) and creates links. Retroactive + forward-looking.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 3.02 Auto-Link Rule: "Only Link Once Per Post"
- **Description:** Toggle (default ON) ensuring each auto-link keyword only creates one link per post, preventing spammy repetition.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 3.03 Auto-Link Rule: "Add Link If Post Already Has This Link"
- **Description:** Toggle to allow/prevent inserting auto-links into posts that already contain a link to the target URL.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 3.04 Auto-Link Rule: "Override One Link Per Sentence"
- **Description:** By default, Link Whisper inserts only one link per sentence. This toggle allows multiple auto-links within a single sentence.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 3.05 Auto-Link Rule: "Select Links Before Inserting" (Preview Mode)
- **Description:** Adds a "Possible Links" column to the report where candidates are stored for manual review before actual insertion. Recommended by Link Whisper for safety.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 3.06 Auto-Link Rule: Priority Level Setting
- **Description:** Set a numeric priority for each auto-link rule. Higher numbers = higher priority. Determines which rule wins when multiple rules match the same text.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 3.07 Auto-Link Rule: "Only Posts Published After Date"
- **Description:** Restrict auto-links to posts published after a specific date, with a date selector. Useful for avoiding retroactive changes to old content.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 3.08 Auto-Link Rule: Case-Sensitive Keyword Matching
- **Description:** Toggle to enforce case-sensitive matching for the auto-link keyword (e.g., "SEO" vs. "seo").
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 3.09 Auto-Link Rule: Restrict to Specific Categories
- **Description:** Limit auto-link insertion to posts within designated categories/tags only.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 3.10 Auto-Link Rule: Cap Total Auto-Links Created
- **Description:** Set a maximum number of total auto-links that a single rule can create across the site.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 3.11 Auto-Link Rule: Prioritize Long-Tail Keyword Matching
- **Description:** Toggle to prefer longer keyword phrase matches over shorter ones when both are possible.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 3.12 Auto-Link Rule: Support External URLs
- **Description:** Auto-linking rules can point to external URLs, not just internal pages. Useful for affiliate links or partner sites.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 3.13 One-Click Setup (Bulk Initial Linking)
- **Description:** Single-button setup that performs: auto-indexing of all content, topical clustering via AI, bulk identification of best link insertion points across the entire content library, native LLM activation.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 3.14 Automatic Background Scan (Cron-Based)
- **Description:** WordPress cron scans 10-20 links every 5 minutes automatically in the background. Alternative to manual scanning. Useful for large sites to avoid timeouts.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 3.15 Auto-Index on Entry Save
- **Description:** Automatically re-indexes an entry when it is saved/published, keeping the suggestion index fresh without manual rebuilds.
- **Plan:** Both
- **Priority for Statamic clone:** CRITICAL
- **Linkwise status:** DONE (via EntryIndexSubscriber)

### 3.16 CLI Command: Rebuild Index
- **Description:** Command-line tool to rebuild the entire link/content index. In Linkwise: `php please linkwise:index`.
- **Plan:** Both
- **Priority for Statamic clone:** CRITICAL
- **Linkwise status:** DONE

### 3.17 Run Link Scan (Manual Trigger from Dashboard)
- **Description:** Button in the report/dashboard to initiate a full site-wide link scan, crawling all pages and building the link inventory.
- **Plan:** Both
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** PARTIAL (CLI only, no dashboard button yet)

---

## 4. SETTINGS -- Configuration & Customization

### 4.01 Language Selection
- **Description:** Choose the language for NLP processing. Affects stop words, stemming, and suggestion quality. 21 languages supported (English, Spanish, French, Portuguese, German, Dutch, Polish, Russian, Danish, Italian, + 11 more).
- **Plan:** Both
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 4.02 Open Links in New Tab Toggle
- **Description:** Global setting to make all inserted links open in a new browser tab (adds target="_blank").
- **Plan:** Both
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 4.03 Ignore Numbers in Anchors Toggle
- **Description:** When enabled, excludes number-containing phrases from anchor text suggestions. When disabled, allows posts with numbers in titles (e.g., "Top 10 Tips") to be suggested.
- **Plan:** Both
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 4.04 Words to Be Ignored (Stop Words List)
- **Description:** Pre-populated array of common words excluded from suggestion matching. Customizable -- users can add or remove words.
- **Plan:** Both
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 4.05 Post Type Selection (Which Types to Process)
- **Description:** Choose which post types Link Whisper processes for suggestions, indexing, and reports. Free version: public types only. Premium: includes non-public/custom types.
- **Plan:** Both (Free = public only; Premium = all types)
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED (processes all collections currently)

### 4.06 Post Status Configuration
- **Description:** Choose which post statuses to include: published, drafts, scheduled. Controls whether unpublished content appears in suggestions.
- **Plan:** Both
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 4.07 Exclude Specific Pages/Posts from Suggestions
- **Description:** Blacklist individual pages or posts so they never appear in suggestions and are excluded from all Link Whisper services.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 4.08 Exclude Specific Categories from Suggestions
- **Description:** Blacklist entire categories so all posts within them are excluded from suggestion generation.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 4.09 Per-Domain Link Attributes (Nofollow / Dofollow / Sponsored)
- **Description:** In the Domain Settings section, configure link attributes per external domain. Set nofollow, dofollow, or sponsored for all links to that domain site-wide.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 4.10 Per-Domain Link Behavior (Same Tab / New Tab)
- **Description:** Configure per external domain whether links open in the same tab or a new tab. Applies globally to all instances.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 4.11 URLs Treated as Internal
- **Description:** Specify URLs that should be treated as internal links even though they are technically external (e.g., cloaked affiliate links, CDN domains, subdomains).
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 4.12 HTML Code Display Toggle
- **Description:** Setting to show/hide raw HTML code in the suggestion panel for debugging or advanced editing.
- **Plan:** Both
- **Priority for Statamic clone:** SKIP
- **Linkwise status:** N/A

### 4.13 Debug Mode Toggle
- **Description:** Enable debugging output for troubleshooting plugin issues. Generates diagnostic data.
- **Plan:** Both
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 4.14 Support Data Export / Debug Info Download
- **Description:** "Download Support Data Export" or "Generate Debug Info" for submitting to support. Includes settings, report data, and diagnostic information.
- **Plan:** Both
- **Priority for Statamic clone:** SKIP
- **Linkwise status:** N/A

### 4.15 Configurable Title Blacklist
- **Description:** Exclude entries with short or generic titles (e.g., "Getting Started", "About") from suggestions to reduce false positives.
- **Plan:** N/A (not in Link Whisper; identified as Linkwise need)
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

---

## 5. ADVANCED -- AI, Cross-Site, Integrations, Specialty Features

### 5.01 AI-Powered Semantic / NLP Suggestions
- **Description:** Since v2.7.0 (August 2025), uses NLP/semantic understanding beyond keyword matching. This is Link Whisper's core differentiator vs. all other link tools which only use categories/tags. Understands context and relevance, discovers link opportunities that pure title-matching misses. Example: "caching layer" matches "Redis Caching Best Practices" even though title words don't appear.
- **Plan:** Premium
- **Priority for Statamic clone:** CRITICAL (This is what makes Link Whisper worth $97/year. Without it, suggestions are limited to exact title matches. V1.1 must have at minimum TF-IDF keyword overlap matching. V2 should have embeddings-based semantic matching.)
- **Linkwise status:** NOT STARTED

### 5.02 AI Credits System
- **Description:** Bundled AI credits for native LLM features. Growth (3 sites): 1,000 credits; Pro (10 sites): 5,000; Agency (50 sites): 10,000. Credits consumed per AI processing run.
- **Plan:** Premium
- **Priority for Statamic clone:** SKIP (different business model)
- **Linkwise status:** N/A

### 5.03 OpenAI API Key Integration (for AI Sitemap)
- **Description:** Input your own OpenAI API key and select ChatGPT model version. Required for the AI Visual Sitemap feature. Separate from native AI credits.
- **Plan:** Premium
- **Priority for Statamic clone:** SKIP (V2+ if at all)
- **Linkwise status:** N/A

### 5.04 "Run AI Processing" Button
- **Description:** Single button to index all site content using AI for semantic understanding. Creates topical clusters and improves suggestion relevance.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 5.05 Target Keywords Management
- **Description:** Define SEO target keywords for each post. Keywords improve suggestion quality -- suggestions mentioning target keywords are prioritized. Bulk editing across all posts.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 5.06 Target Keywords: Import from Yoast SEO
- **Description:** Automatically imports focus keywords from Yoast SEO plugin and uses them as target keywords.
- **Plan:** Premium
- **Priority for Statamic clone:** SKIP (no Yoast in Statamic)
- **Linkwise status:** N/A

### 5.07 Target Keywords: Import from Rank Math
- **Description:** Automatically imports focus keywords from Rank Math plugin.
- **Plan:** Premium
- **Priority for Statamic clone:** SKIP (no Rank Math in Statamic)
- **Linkwise status:** N/A

### 5.08 Target Keywords: Import from All In One SEO / SEOPress
- **Description:** Automatically imports focus keywords from AIOSEO and SEOPress plugins.
- **Plan:** Premium
- **Priority for Statamic clone:** SKIP (no AIOSEO in Statamic)
- **Linkwise status:** N/A

### 5.09 Target Keywords: Google Search Console Integration
- **Description:** Connect Google Search Console to auto-populate target keywords with actual search queries your pages rank for.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 5.10 Target Keywords: Custom Keywords (Manual Entry)
- **Description:** Manually enter target keywords for any post via a form, independent of SEO plugins.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 5.11 URL Changer (Bulk URL Replacement)
- **Description:** Enter old URL and new URL. All internal links pointing to the old URL are updated site-wide immediately. No preview step.
- **Plan:** Premium
- **Priority for Statamic clone:** SKIP (Statamic handles this via entry references / statamic:// URIs)
- **Linkwise status:** N/A

### 5.12 Cross-Site Linking Suggestions
- **Description:** Connect multiple WordPress sites running Link Whisper. Get link suggestions between your different properties. Suggestions appear in a separate section below internal suggestions.
- **Plan:** Premium
- **Priority for Statamic clone:** SKIP
- **Linkwise status:** N/A

### 5.13 Related Posts Widget
- **Description:** Displays a "Related Posts" section on published pages. Options: auto or manual post selection, match by tags/categories/terms, thumbnail support (above/below text, configurable size), margin/spacing settings, number of links, HTML heading tag selection, custom title and description text, enable/disable per post type.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 5.14 Related Posts Widget: Thumbnail Configuration
- **Description:** Enable/disable thumbnails in the related posts widget. Configure placement (above/below), size, and margins.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 5.15 Related Posts Widget: Matching Criteria
- **Description:** Choose how related posts are selected: by tags, categories, or matching terms. Option to avoid showing posts already linked from the current page.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 5.16 Link Intent Analysis
- **Description:** AI feature (2025+) that considers user journey mapping and conversion optimization when making link suggestions, not just SEO relevance.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 5.17 Topical Clustering (AI-Driven Content Grouping)
- **Description:** AI groups related posts into "silos" or topic clusters. Used by the one-click setup and AI sitemap features to identify content relationships.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 5.18 Links Persist After Plugin Removal
- **Description:** All inserted links are standard HTML anchor tags. Deactivating or uninstalling the plugin leaves all links intact. No vendor lock-in.
- **Plan:** Both
- **Priority for Statamic clone:** CRITICAL
- **Linkwise status:** DONE (uses standard Bard links / statamic:// URIs)

### 5.19 Non-Public Post Type Support
- **Description:** Premium version can process and suggest links for non-public/custom post types (e.g., private pages, internal docs).
- **Plan:** Premium (Free = public only)
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 5.20 Bulk Link Operations
- **Description:** Batch operations on links from reports: bulk delete, bulk edit attributes, bulk domain management.
- **Plan:** Premium
- **Priority for Statamic clone:** NICE
- **Linkwise status:** NOT STARTED

### 5.21 Link Statistics in Post Listing Column
- **Description:** Show inbound/outbound link counts directly in the WordPress post listing table, without navigating to the dedicated report.
- **Plan:** Premium
- **Priority for Statamic clone:** IMPORTANT
- **Linkwise status:** NOT STARTED

### 5.22 Memory Monitoring / Performance Warnings
- **Description:** Monitors PHP memory usage. Warns when site approaches limits (256 MB minimum recommended). Relevant for large sites with 200+ posts.
- **Plan:** Both
- **Priority for Statamic clone:** SKIP
- **Linkwise status:** N/A

---

## SUMMARY: Priority Counts

| Priority | Count | Description |
|----------|-------|-------------|
| CRITICAL | 10 | Must have for V1 launch |
| IMPORTANT | 22 | Should have for V1.1 |
| NICE | 24 | V2+ candidates |
| SKIP | 10 | Not relevant for Statamic |

## SUMMARY: Linkwise Status

| Status | Count |
|--------|-------|
| DONE | 10 |
| PARTIAL | 3 |
| NOT STARTED | 40 |
| N/A | 13 |

## CRITICAL Features (V1 Checklist)

| # | Feature | Linkwise Status |
|---|---------|-----------------|
| 1.01 | Outbound Link Suggestions | DONE |
| 1.02 | Inbound Link Suggestions | DONE |
| 1.03 | One-Click Link Insertion | DONE |
| 1.06 | Sentence Context Display | DONE |
| 2.01 | Internal Links Report | DONE |
| 2.02 | Orphaned Content Detection | DONE |
| 2.05 | Report Sorting | DONE |
| 3.15 | Auto-Index on Entry Save | DONE |
| 3.16 | CLI Command: Rebuild Index | DONE |
| 5.18 | Links Persist After Removal | DONE |

**All 10 CRITICAL features are DONE.**

## IMPORTANT Features (V1.1 Roadmap)

| # | Feature | Linkwise Status |
|---|---------|-----------------|
| 1.04 | Anchor Text Editing Before Insertion | NOT STARTED |
| 1.07 | Multiple Suggestions Per Sentence | DONE |
| 1.08 | Skip Intro Sentences Setting | NOT STARTED |
| 2.03 | Link Health Dashboard | PARTIAL |
| 2.04 | Report Filtering | PARTIAL |
| 2.06 | Expandable Detail Rows | NOT STARTED |
| 2.08 | "Add Links" Quick Action | NOT STARTED |
| 2.09 | Broken Link Checker | NOT STARTED |
| 2.10 | Broken Link: Reset Data | NOT STARTED |
| 2.11 | Broken Link: Manual Scan | NOT STARTED |
| 2.14 | CSV Data Export | NOT STARTED |
| 3.01 | Auto-Linking Rules | NOT STARTED |
| 3.02 | Auto-Link: Once Per Post | NOT STARTED |
| 3.03 | Auto-Link: Skip If Link Exists | NOT STARTED |
| 3.05 | Auto-Link: Preview Mode | NOT STARTED |
| 3.09 | Auto-Link: Category Restriction | NOT STARTED |
| 3.14 | Background Scan (Cron) | NOT STARTED |
| 3.17 | Run Link Scan from Dashboard | PARTIAL |
| 4.01 | Language Selection | NOT STARTED |
| 4.02 | Open in New Tab Toggle | NOT STARTED |
| 4.04 | Stop Words List | NOT STARTED |
| 4.05 | Collection/Post Type Selection | NOT STARTED |
| 4.06 | Post Status Configuration | NOT STARTED |
| 4.07 | Exclude Specific Entries | NOT STARTED |
| 4.08 | Exclude Specific Categories | NOT STARTED |
| 4.15 | Title Blacklist | NOT STARTED |
| 5.05 | Target Keywords Management | NOT STARTED |
| 5.10 | Custom Target Keywords | NOT STARTED |
| 5.21 | Link Stats in Collection Listing | NOT STARTED |

---
layout: home

hero:
    name: Linkwise
    text: Internal linking, fixed.
    tagline: Suggestion engine, broken-link finder, domain-level rel governance, and full-site URL rewrite — across every Bard, Replicator, and Markdown field on your Statamic site.
    actions:
        - theme: brand
          text: Get started
          link: /getting-started/installation
        - theme: alt
          text: View on GitHub
          link: https://github.com/arturrossbach/statamic-linkwise

features:
    - icon: 🔗
      title: Suggestion engine
      details: >
          Surfaces internal-link candidates in both directions — inbound and outbound. It matches on title phrases, your custom-keyword anchors, and stemmed title variants, so plurals and inflections still match. High-signal by design — tunable thresholds and a built-in anchor-text editor keep you in control of every link.
    - icon: 🧱
      title: Reads the structured content tree
      details: >
          Most internal-linking tools see the page as flat HTML. Linkwise reads the structured tree — every Bard node and every Replicator set at any nesting depth, including the cards and blocks of page-builder layouts, plus Markdown fields. Same coverage on write: insertions reach into nested sets, not just the top-level field. Plaintext fields (text / textarea) are left untouched by design — Linkwise only works where a link is a real link, never literal `[text](url)` syntax.
    - icon: ⚡
      title: Auto-Linking rules
      details: >
          Keyword to URL rules with case-sensitivity, collection scoping, once-per-post enforcement, and auto-apply-on-save (per-rule and global toggles). New entries get historical anchors retroactively; old entries pick up new keywords on next save.
    - icon: 🔍
      title: Broken Link Finder
      details: >
          Full-site HEAD/GET scan with retries and per-error classification (404, SSL handshake, timeout, DNS, connection refused). Inline replace, ignore, or unlink — no jumping to the entry editor. Cancellable and resumable for sites with thousands of links.
    - icon: 🔄
      title: URL Changer
      details: >
          Bulk-rewrite any URL site-wide in smart-match or exact-match mode. SHA-hash optimistic locking aborts the rewrite for any entry edited by another user since the bulk started — concurrent edits are never silently overwritten.
    - icon: 🌐
      title: Domain Manager
      details: >
          Set rel="nofollow" / "sponsored" / "ugc" once per external domain. Applied at render time via a Bard mark extension — your stored content stays untouched, and changing a domain rule retroactively updates every existing link.
    - icon: 📊
      title: Custom keywords
      details: >
          Give an entry its own anchor terms — brand names, product synonyms, internal codenames — and Linkwise suggests links on those too, not just words from the title. CSV import/export for bulk seeding from existing keyword research.
    - icon: 🛡️
      title: Privacy by design
      details: >
          All link data stays in storage/linkwise/ on your server. Zero telemetry, zero SaaS callbacks — Linkwise runs entirely on your own infrastructure, with no external services in the loop.
---

<style>
.VPHero .name {
    background: linear-gradient(135deg, #10b981 30%, #06b6d4);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
</style>

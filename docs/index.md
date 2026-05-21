---
layout: home

hero:
    name: Linkwise
    text: Internal linking, fixed.
    tagline: Suggestion engine, broken-link finder, domain-level rel governance, and full-site URL rewrite — across every Bard, Replicator, and Markdown field on your Statamic site.
    actions:
        - theme: brand
          text: Get started
          link: /guide/installation
        - theme: alt
          text: View on GitHub
          link: https://github.com/arturrossbach/statamic-linkwise

features:
    - icon: 🔗
      title: Multi-tier suggestion engine
      details: >
          Surfaces internal-link candidates in both directions — inbound and outbound — through four tiers in order. Title phrases, custom-keyword anchors, unordered stem clusters, then TF-IDF keyword overlap. Long news titles, descriptive blog titles, and short product titles each match through the tier they fit. Tunable thresholds, anchor-text editor built in.
    - icon: 🧱
      title: Reads the full content tree
      details: >
          Most internal-linking tools see the page as flat HTML. Linkwise reads the structured tree — every Bard node, every Replicator set at any nesting depth, Peak Cards / Buttons / Accordions, and any addon's custom-field text. Same coverage on write — link insertions reach into nested sets, not just the top-level Bard. UUIDs, asset filenames, and config enums filtered out so noise stays out of anchor candidates.
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
      title: Target Keywords
      details: >
          Per-entry custom keywords (brand terms, product synonyms, internal codenames) boost suggestion ranking on top of TF-IDF auto-extracted content keywords. CSV import/export for bulk seeding from existing keyword research.
    - icon: 🛡️
      title: Privacy by design
      details: >
          All link data stays in storage/linkwise/ on your server. Zero telemetry, zero SaaS callbacks. Optional bring-your-own-key AI calls your OpenAI / Anthropic API directly from your server — Linkwise never sees the key or the embeddings.
---

<style>
.VPHero .name {
    background: linear-gradient(135deg, #10b981 30%, #06b6d4);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
</style>

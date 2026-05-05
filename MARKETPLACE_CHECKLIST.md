# V1.0.0 Marketplace Launch Checklist

Internal launch prep — not part of the addon distribution.
Track here what's done vs what's open before tagging v1.0.0.

## Code & build

- [x] composer.json: `type: statamic-addon`, author + email + homepage + support
- [x] composer.json validates (`composer validate`)
- [x] composer.json declares: PHP `^8.2`, Laravel `^12.0`, Statamic `^6.0`
- [x] ServiceProvider extends `AddonServiceProvider`, uses `bootAddon()`
- [x] Permission registered: `manage linkwise`
- [x] Nav entry under Tools section
- [x] Vite config with `addon.js` entry
- [x] All routes gated by `can:manage linkwise` middleware
- [x] CSV exports work (broken-links / domains / autolink)
- [x] Heavy-bulk pattern with cancel + recovery + completion banner
- [x] Optimistic locking via SafeEntrySaver SHA hash
- [x] Frontend error reporter (Vue + window + promise) → local log
- [x] LogRotator: append-mode for heavy-bulk command logs
- [x] Help dropdown in header → docs link, debug export, version
- [x] Diagnostic ZIP export (privacy-safe + opt-in verbose)
- [x] Dark mode: no `dark:text-dark-*` typos, no orphan light grays
- [x] 313 PHP unit tests green
- [x] 90 Playwright E2E tests green (Overview/Keywords/CSV/Buttons/Stale-Check/Mutations/Debug-Export/Error-Tracking)

## Documentation

- [x] README.md polished for Marketplace audience
- [x] CHANGELOG.md (Keep-A-Changelog format) with v1.0.0 entry
- [x] LICENSE.md (commercial)
- [ ] Privacy snippet for users to paste into their own privacy notice
- [ ] FAQ section / common gotchas (optional V1.1)

## Marketplace prep

- [ ] **Packagist**: register `inkline/statamic-linkwise` package
- [ ] **Statamic seller account**: create at statamic.com/creator/begin, link Stripe
- [ ] **Marketplace listing**: title, description, feature bullets
- [ ] **Screenshots** (8 recommended):
  - Overview tab with Recommendations
  - Links Report with suggestions column
  - Broken Links with inline fix actions
  - Auto-Linking rules + preview
  - URL Changer search + apply
  - Domains tab with attribute dropdown
  - Target Keywords editor
  - Help dropdown showing diagnostic export
- [ ] **Pricing**: 99 € one-time (single site) — bulk-license tier TBD
- [ ] **Demo screencast**: voiceover walk-through (no on-camera)
- [ ] **Trial copy**: 14-day money-back guarantee text

## Release

- [ ] Tag v1.0.0
- [ ] GitHub release with CHANGELOG entry as body
- [ ] Push tag to GitHub
- [ ] Verify Packagist auto-update (or trigger manually)
- [ ] Submit to Marketplace as draft
- [ ] Preview marketplace page → publish

## Post-launch monitoring

- [ ] Monitor first 5 support tickets to validate diagnostic ZIP workflow
- [ ] Monitor `frontend-errors.log` patterns to catch real-world JS issues
- [ ] Track Statamic version distribution among installs (via support tickets)

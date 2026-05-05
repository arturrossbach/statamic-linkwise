# Linkwise — Claude Code Rules

## BLOCKING: Break It Before You Ship It

Before telling the user to test ANY implementation, you MUST:

1. **List 5+ things that could go wrong.** Write them down explicitly.
2. **Write a test for each.** Not just happy path — failure modes, edge cases, concurrent access, duplicate data, empty input, boundary conditions.
3. **Run the tests.** All must pass.
4. **Only then** tell the user to test.

The user should only find UX/visual issues — NEVER functional bugs, data corruption, or logic errors. Those are YOUR job to catch. If the user finds a bug that a test could have caught, that is a failure on your part.

Ask yourself for every feature:
- What if the input is empty? Null? A duplicate?
- What if the data changed between read and write?
- What if the user does this operation twice?
- What if only part of the operation succeeds?
- What if there are multiple items with the same key/identifier?
- What happens at the boundaries (first, last, single item, zero items)?

This is non-negotiable. No exceptions.

## BLOCKING: Statamic UI Components Check

Before writing ANY Vue template code — every button, dropdown, modal, tab, card, table, badge, tooltip, form element — you MUST:

1. Search `vendor/statamic/cms/resources/js/components/ui/index.js` for existing exports
2. Read the component source to understand props/slots
3. Use the Statamic component. No exceptions.

If you skip this step and write custom HTML/CSS for something Statamic already provides, you are wasting the user's time and creating inconsistent UI. This has happened 5+ times and is not acceptable.

### Known Statamic UI Components (check index.js for current list):
- `Tabs`, `TabList`, `TabTrigger`, `TabContent` — Tab navigation
- `Dropdown`, `DropdownItem`, `DropdownSeparator`, `DropdownMenu` — Context menus
- `Modal`, `ModalClose`, `ModalTitle` — Modal dialogs
- `Stack`, `StackHeader`, `StackContent`, `StackFooter` — Panel overlays
- `Header` — Page headers with icon + actions slots
- `Card`, `Panel` — Containers
- `Button` — Action buttons
- `Badge` — Status badges
- `Popover` — Popover tooltips
- `ConfirmationModal` — Confirm/cancel dialogs
- `v-tooltip` — Global directive (auto-registered)

### Import pattern:
```js
import { Dropdown, DropdownItem, Modal, Tabs, TabList } from '@statamic/cms/ui';
import { Link, Head } from '@statamic/cms/inertia';
```

## Implementation Axiom (check BEFORE every implementation)

1. **Statamic first:** Does Statamic already provide a component, directive, or pattern for this?
2. **Browser-native second:** Is there a native HTML/CSS solution?
3. **Custom last:** Only if 1+2 don't apply — and flag it explicitly.

## Workflow Rules

- NEVER implement multiple steps before user tests in browser
- Every step ends with a concrete test instruction
- User tests → confirms → next step
- Update architecture.md memory AFTER EVERY SPRINT
- Before asking user to test in UI: verify via CLI (tinker, curl, or phpunit) that the feature works. The user should only confirm visual/UX aspects, not find functional bugs.

## Link Whisper Benchmark

Before every feature implementation, check: What does Link Whisper do here? Proactively suggest UX details from the Link Whisper analysis. Don't wait for the user to point it out.

## Proactive Improvement Suggestions

During every concept review AND during implementation: actively suggest product/UX improvements. Don't just build what's asked — think about what SHOULD be there. Examples:
- "Should we add a Suggestions column to the Links Report?" (like the user suggested for Sprint 8)
- "Link Whisper shows anchor text in the modal — should we add that?"
- "This table could benefit from a keyword search field"

The user values proactive thinking. If you see an opportunity to make the product better, suggest it before the user has to ask.

## BLOCKING: Think Before You Build

Before implementing ANY change, complete this checklist. No exceptions.

### Before writing code:
1. **Trace the data flow.** Where does this value come from? Where is it displayed? Are there other places showing the same data? List them.
2. **Identify the single source of truth.** If the same data is computed in multiple places, which one is authoritative? The others must use it, not recompute.
3. **Think like a user.** Would an SEO manager understand every label, every number, every color at first glance? If not, rewrite before implementing.
4. **Check for duplicates.** Does another card, column, or section already show this information?

### After writing code, before telling the user to test:
5. **Verify numbers match across views.** If a count appears in Overview AND in a tab — do they show the same number? Check via tinker.
6. **Verify colors match reality.** If something is red, is the situation actually bad? If green, is it actually good?
7. **Read every visible text out loud.** Does it make sense to someone who doesn't know the codebase?
8. **Look at the full page, not just your change.** Did your change create a duplicate? A contradiction? A layout break?

This checklist exists because skipping it has caused bugs in every single session. The user should not have to find inconsistencies — that is your job.

## Code Quality

- DRY: Extract shared components (like HelpIcon) immediately
- No SVG width/height attributes — use CSS
- ProseMirror manipulation only via Bard API
- Type hints on all PHP methods
- Stemming via wamania/php-stemmer (Snowball), never ad-hoc

## Testing

- Write unit tests for EVERY new feature, not just the happy path
- Test edge cases: empty input, already-linked text, word boundaries, case sensitivity
- Before telling the user to test in UI: verify via CLI (tinker/phpunit) that it works
- Auto-Link tests must cover: word boundary, once-per-post, already-linked detection, self-reference, preview consistency
- Run the full test suite before every commit
- NEVER run the full Playwright suite during development — only targeted tests with `--grep`
- When a value appears in multiple views: verify BOTH views show the same number via tinker before asking the user to test

## Data Flow — Single Sources of Truth

When changing anything related to these, trace the full flow first:

- **Suggestion counts**: `InboundEngine::suggest()` is the single source of truth. `DashboardController` calls it per entry and overrides LinkReport's counts. Do NOT add suggestion logic to `LinkReport::suggestionCounts()` — it will diverge.
- **Outbound counts**: `LinkReport` computes internal outbound. `DashboardController` adds external counts for `outbound_total`.
- **Broken links**: `BrokenLinkChecker` writes JSON. `BrokenLinkReport` reads it. Overview reads from the same data.
- **Domain attributes**: `DomainReport` scans entries. Domain attributes stored in `domain-attributes.json`. `LinkwiseLinkMark` reads them for rendering.
- **Auto-link preview**: `AutoLinkApplier::applyRule(preview: true)` is the single source of truth for match_count and linked_count.

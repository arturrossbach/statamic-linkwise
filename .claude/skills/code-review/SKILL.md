Use this skill to review code quality, patterns, and best practices in the current implementation.

## General Principles

- **DRY (Don't Repeat Yourself):** Extract repeated logic into functions, components, or composables. If you copy-paste code, you're doing it wrong.
- **KISS (Keep It Simple):** Prefer the simplest solution that works correctly. No premature abstractions.
- **SRP (Single Responsibility):** Each function, component, and class should do one thing well.
- **No hacks:** NEVER use temporary hacks just to make something work. If a shortcut is truly necessary, mark it with TODO and plan the proper fix in the memory.
- **Ship quality:** Every line of code must be shippable to paying customers. If you wouldn't put it in a code review, don't commit it.

## Vue Best Practices

### Reactivity
- **Reactivity first:** Use Vue's reactivity system (data, computed, props, emit, provide/inject). DOM queries in a Vue component are a code smell.
- **v-model for two-way binding:** If v-model doesn't work, understand WHY before using a workaround. The framework is usually right.
- **Computed over methods for derived state:** If a value depends on reactive data and is read in the template, use computed. Methods are for actions.
- **Watchers sparingly:** Prefer computed properties. Use watchers only for side effects (API calls, localStorage writes).

### Component Design
- **Props down, events up:** Parent components pass data via props, children communicate via $emit. Don't reach up or sideways.
- **Component extraction:** Extract reusable pieces into child components when they have a clear interface (props in, events out). Don't extract just to make files smaller.
- **Key attributes on v-for:** Always use `:key` with a unique, stable identifier. Never use array index as key if the list can change.
- **Local component registration:** Register sub-components in `components: {}`. If they fail to render, check that imports resolve correctly in the build.

### DOM Access
- **No direct DOM manipulation:** Don't use `document.getElementById`, `document.querySelector`, or `innerHTML` in Vue components unless there is genuinely no reactive alternative.
- **Avoid `this.$refs` for data flow:** Refs are for imperative DOM access (focus, scroll), not for reading form values. Use v-model or events instead.
- **Lifecycle awareness:** Know when `mounted()`, `created()`, `updated()` fire. Don't assume DOM exists in `created()`.

### When DOM Access Is Acceptable
Sometimes Vue's reactivity cannot reach outside data (e.g. Statamic's Inertia page props when inject isn't available). In these cases:
- Access the DOM **once** in `mounted()` and store the reference reactively
- Document WHY the DOM access is necessary with a comment
- Prefer reading a reactive reference (like `$page.props`) over reading raw DOM element values
- Never re-query the DOM on every user interaction

## Statamic Addon Patterns

- **Use Statamic's existing UI components and directives** where available (v-tooltip, Button, Panel, etc.)
- **If a Statamic global (directive, component) is not available** in your addon's Vue context, import the underlying library directly and register it locally
- **Fieldtype components** receive props from Statamic's publish form. Use `inject` with the correct key to access publish container values when available.
- **provideToScript data** is available at `window.StatamicConfig`, not via `Statamic.$config.get()`
- **Bard integration:** Use `Statamic.$bard.buttons()` to register custom Bard toolbar buttons. The button receives the editor instance for programmatic TipTap/ProseMirror commands.
- **CP Pages:** Use Inertia pattern: register via `Statamic.$inertia.register('my-addon::Page', Component)`, render via `Inertia::render('my-addon::Page', $data)`. Use Inertia's `<Head>` and `<Link>` components. Avoid Blade views for CP pages unless Inertia is not feasible.
- **UI Components:** Check https://ui.statamic.dev for available components (Header, Panel, Card, Table, Listing, Pagination). Use Statamic's native components before building custom ones.
- **Addon compatibility check (BLOCKING):** Before every implementation, verify: (1) Does Statamic provide an API/component/pattern for this? Check docs at statamic.dev/extending. (2) Does the addon follow Statamic's established patterns (Inertia for CP pages, AddonServiceProvider conventions, Nav::extend for navigation)? (3) Will this break with Statamic updates? Prefer stable, documented APIs over internal implementation details.

## PHP Best Practices

- **Type hints everywhere:** Method parameters and return types should always be typed.
- **Early returns:** Reduce nesting by returning early on error conditions.
- **Value objects for data transfer:** Use DTOs (readonly classes) instead of plain arrays for structured data.
- **Config over magic:** Read settings from config files, not from hardcoded values.
- **Dependency injection:** Let Laravel's container wire dependencies. Don't `new` services inside other services.

## Self-Review Checklist

Before marking anything as done, verify:
- [ ] Is the logic clean and the code readable?
- [ ] Am I using framework patterns correctly (Vue reactivity, Statamic APIs)?
- [ ] Are there DOM queries that should be reactive bindings?
- [ ] Is there duplicated code that should be extracted (DRY)?
- [ ] Is each component/class doing only one thing (SRP)?
- [ ] Will this break on edge cases (empty input, special chars, page refresh, Bard fields)?
- [ ] Are Vue components using props/emit correctly, not reaching into parent/sibling state?
- [ ] Are computed properties used for derived state instead of methods?
- [ ] Are local directives/components imported and registered correctly?
- [ ] Would a senior Vue/Laravel developer approve this approach?
- [ ] Would I be comfortable shipping this to paying customers?
- [ ] Is it conform with Statamic Coding Guidelines for addons? (Check: Inertia for CP pages, Nav::extend for nav, AddonServiceProvider conventions, UI components from ui.statamic.dev)
- [ ] **Implementation Axiom:** For every UI element: (1) Does Statamic already provide this? (2) Is there a browser-native solution? (3) Only then: custom. Flag custom solutions explicitly.
- [ ] **Accessibility:** Are ARIA attributes, keyboard support, and semantic HTML correct? WCAG compliance is mandatory, not optional.
- [ ] **Defense in depth:** Validation/clamping on BOTH server and client.
- [ ] **Kernfeatures brauchen Fundament:** Bei Algorithmen oder fachlichen Konzepten erst recherchieren was der Stand der Technik ist. Nie ad-hoc erfinden.
- [ ] Bist Du mit der Implementierung selbst zufrieden oder gibt es Kritikpunkte?
# Editions

Linkwise ships as a single commercial edition. One purchase unlocks the whole
addon — there is no free/pro split and no feature gating.

## Pricing

Linkwise is a **one-time purchase per production installation** — no
subscription. For the current price, see the
[Statamic Marketplace listing](https://statamic.com/addons), which is the single
source of truth for pricing.

You buy and manage the license through the Marketplace, which also governs the
purchase terms (license grant, refund window, AS-IS warranty). Linkwise adds a
short [supplemental license](https://github.com/arturrossbach/statamic-linkwise/blob/master/LICENSE.md)
that clarifies the points below. A purchase includes updates within the same
major version.

::: tip Try it before you buy
Development and staging use is **free and unlimited**, so you don't need a
license to evaluate Linkwise. We strongly recommend installing it on a local or
staging copy first — run a content scan, look at the suggestions and reports it
produces on your *real* content, and confirm it fits your workflow before
activating a production license.
:::

## What a license covers

A license covers **one production Statamic installation** — a single
`composer.json`-bound codebase under your operational control — **regardless of
how many Statamic Sites, locales, or production hostnames that installation
serves**. A multilingual site with six locales on three domains is still one
installation.

- **Development & staging are free and unlimited.** You only license production.
- **Each additional production installation** (a separate codebase — typically a
  different brand, product, or client project) needs its own license.
- The license is for a **single business entity on its own properties**.
  Reselling Linkwise's functionality as part of a hosted multi-tenant service
  requires a separate enterprise agreement — [contact support](mailto:linkwise.support@gmail.com).

## Refunds

Refunds are handled through the Statamic Marketplace under its standard refund
window. If Linkwise isn't a fit, request a refund there.

## Your data stays yours

Linkwise transmits nothing to the author or any third party. All addon data —
the index, reports, and the forensic snapshots of every bulk operation — lives
in your own `storage/linkwise/` directory. There is no telemetry and no SaaS
callback.

## Requirements

Linkwise runs on Statamic 6. **Statamic Pro is not required** for single-site
use; multilingual features use Statamic's multisite, which does require Pro. See
[Installation](/getting-started/installation#requirements) for the full
requirements list (PHP version, shell functions, writable storage).

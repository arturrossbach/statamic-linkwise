Use this skill to review product and UX decisions for the current implementation.

This review is BLOCKING — it must happen before the next implementation step.

## Review Structure

### 1. Mehrwert
- Does this feature solve a real problem?
- Would a Statamic user pay for this?
- Is it clear what it does at first glance?

### 2. Kritik
- What is not good enough yet?
- What feels confusing, incomplete, or half-baked?
- What would a first-time user struggle with?

### 3. Risiko
- UX risks: Could this confuse or mislead the user?
- Technical risks: False positives, wrong data, broken edge cases?
- Support burden: Will this generate support tickets?

### 4. Wettbewerb
- Does any existing Statamic addon already do this?
- Would this create redundancy with SEO Pro, Redirect by Rias, or other link-related addons?
- If yes: stop and reconsider.

### 5. Benchmark
- How does Link Whisper handle this same feature?
- Are we at least equal in quality, clarity, and usability?
- If not: what specifically must improve before we move on?
- Name concrete differences

### 6. Optimierung
- What should be fixed or improved before the next step?
- Prioritize: what is a must-fix now vs what can wait?
- Be specific: name files, components, or behaviors to change

## After the Review: Bewertung und Einplanung

The review does NOT end with listing findings. Every finding must be:

### Bewertet
- What is the concrete impact of this finding?
- Is it a blocker, a quality gap, or a nice-to-have?

### Entschieden
- **Fix now** — must be resolved before the next implementation step
- **Fix in current sprint** — must be resolved before the sprint ends
- **Fix in specific later sprint** — name the sprint explicitly
- **Defer to V1.1** — acceptable for launch, tracked for post-launch
- **Won't fix** — explicitly rejected with reason

### Eingetragen
- ALL decisions must be written into the architecture.md memory file
- Use checkboxes for pending items
- Place them under the correct sprint

## Rules
- NEVER skip this review, even if the user doesn't ask for it
- NEVER just list findings without bewertung and einplanung
- Be honest, even if it means recommending to rebuild something
- Always compare against Link Whisper as the quality benchmark
- Always check for redundancy with existing Statamic addons (SEO Pro, Redirect by Rias, Entry Relationships)
- After the review: update architecture.md memory with all decisions
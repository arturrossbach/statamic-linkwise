#!/bin/bash
# Stop hook: two reminders before Claude declares done
#  1. Save important findings to memory (architecture.md / sprint progress)
#  2. Run linkwise:audit if any source code or frontend changed —
#     enforces the BLOCKING rule in CLAUDE.md "before kannst testen,
#     run linkwise:audit". Stop hook fires when Claude finishes a turn,
#     so this is the last gate before the user reads what we built.
#
# This is a REMINDER hook — Claude reads the systemMessage and decides
# whether the audit is warranted. Conservative by design: false positives
# (audit run on a doc-only change) are cheap, false negatives (audit
# skipped when code changed) ship corruption.

cat <<'EOF'
{"systemMessage":"Session ending — TWO checks before declaring done:\n  1. Memory: did anything load-bearing change that future sessions need to know about (architecture.md, sprint progress, new bug-classes)?\n  2. linkwise:audit: if you edited PHP/Vue/JS code, run `php artisan linkwise:audit` against ~/Herd/prose-peak-test. CLAUDE.md requires exit 0 (or explicit known-failures docs) before any 'kannst testen' message."}
EOF


#!/bin/bash
# Pre-tool hook: Before git commit, ensure tests were run.
#
# Refined (2026-05-14): inspect staged-diff. If a frontend file is staged,
# remind about npm build + phpunit. If only PHP/non-FE staged, remind
# about phpunit only. Stops false "you forgot npm build" reminders on
# pure-PHP commits.

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('tool_input',{}).get('command',''))" 2>/dev/null)

if [[ "$COMMAND" != *"git commit"* ]]; then
  exit 0
fi

cd /Users/rossbach/statamic-linkwise 2>/dev/null || exit 0
STAGED=$(git diff --cached --name-only 2>/dev/null)

if [[ -z "$STAGED" ]]; then
  exit 0  # amend without staged delta — previous gate covers it
fi

HAS_FE=0
echo "$STAGED" | grep -qE '\.(vue|js|ts|css)$' && HAS_FE=1

if (( HAS_FE )); then
  echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","additionalContext":"Pre-commit: frontend staged. Verify npm run build ran + phpunit Unit-Suite green."}}'
else
  echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","additionalContext":"Pre-commit (PHP-only): verify phpunit Unit-Suite green. Skip npm build (no FE files staged)."}}'
fi

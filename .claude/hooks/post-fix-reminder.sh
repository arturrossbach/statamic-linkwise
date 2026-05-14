#!/bin/bash
# Post-tool hook: After editing PHP/Vue/JS source files, remind to test
# before claiming "fixed".
#
# Throttled (2026-05-14): first touch of a file-path emits the reminder.
# Subsequent edits within 4h are silent — the Pre-Flight discipline in
# CLAUDE.md already governs the test-loop. Reset via
# `rm -rf .claude/.hook-state/post-fix`.

INPUT=$(cat)
FILE_PATH=$(echo "$INPUT" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('tool_input',{}).get('file_path','') or d.get('tool_response',{}).get('filePath',''))" 2>/dev/null)

# Only trigger for source files
if [[ "$FILE_PATH" == *.php ]] || [[ "$FILE_PATH" == *.vue ]] || [[ "$FILE_PATH" == *.js ]]; then
  # Skip test files, config, vendor, memory, settings, hooks
  if [[ "$FILE_PATH" == *test* ]] || [[ "$FILE_PATH" == *Test* ]] || [[ "$FILE_PATH" == *vendor* ]] || [[ "$FILE_PATH" == *memory* ]] || [[ "$FILE_PATH" == *settings* ]] || [[ "$FILE_PATH" == *hooks* ]] || [[ "$FILE_PATH" == *dist* ]]; then
    exit 0
  fi

  # Per-path throttle (4h TTL).
  STATE_DIR="/Users/rossbach/statamic-linkwise/.claude/.hook-state/post-fix"
  mkdir -p "$STATE_DIR" 2>/dev/null
  HASH=$(printf '%s' "$FILE_PATH" | shasum -a 1 | awk '{print $1}')
  MARKER="$STATE_DIR/$HASH"

  if [[ -f "$MARKER" ]]; then
    AGE_SECONDS=$(( $(date +%s) - $(stat -f %m "$MARKER" 2>/dev/null || echo 0) ))
    if (( AGE_SECONDS < 14400 )); then
      exit 0
    fi
  fi

  touch "$MARKER"
  echo '{"hookSpecificOutput":{"hookEventName":"PostToolUse","additionalContext":"REMINDER: Source touched. Before claiming gefixt/kannst testen, run the relevant tests + verify mutating changes via tinker/curl on real data. Pre-Flight in CLAUDE.md is binding."}}'
fi

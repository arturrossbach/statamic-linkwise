#!/bin/bash
# Pre-tool hook: Before editing source files in src/ or resources/js/,
# remind to do a systematic blast-radius check for related callers and
# patterns. Triggers on PHP source files and JS/Vue components.
#
# Memory rationale: feedback_blast_radius_check.md — 2026-05-09 multiple
# fixes shipped with too narrow scope, user had to ask "are there other
# places this affects?" — should have been proactive.
#
# Throttled (2026-05-14): first touch of a file-path emits the reminder.
# Subsequent edits within 4h are silent — the reminder is already in
# context. Reset via `rm -rf .claude/.hook-state/blast-radius`.

INPUT=$(cat)
FILE_PATH=$(echo "$INPUT" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('tool_input',{}).get('file_path',''))" 2>/dev/null)

# Skip non-source paths (tests, docs, vendor, configs, memory, etc.)
case "$FILE_PATH" in
  */src/*.php|*/resources/js/*.vue|*/resources/js/*.js)
    : # fall through
    ;;
  *)
    exit 0
    ;;
esac

# Skip vendor, node_modules, dist (built assets), and test files
case "$FILE_PATH" in
  *vendor*|*node_modules*|*resources/dist*|*tests/*)
    exit 0
    ;;
esac

# Per-path throttle (4h TTL). Marker-file mtime tracks first touch.
STATE_DIR="/Users/rossbach/statamic-linkwise/.claude/.hook-state/blast-radius"
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
echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","additionalContext":"Blast-radius: grep callers, post table. feedback_blast_radius_check.md."}}'

#!/bin/bash
# Pre-tool hook: Before editing source files in src/ or resources/js/,
# remind to do a systematic blast-radius check for related callers and
# patterns. Triggers on PHP source files and JS/Vue components.
#
# Memory rationale: feedback_blast_radius_check.md — 2026-05-09 multiple
# fixes shipped with too narrow scope, user had to ask "are there other
# places this affects?" — should have been proactive.

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

echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","additionalContext":"Blast-radius: grep callers, post table. feedback_blast_radius_check.md."}}'
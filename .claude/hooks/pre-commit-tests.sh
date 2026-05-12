#!/bin/bash
# Pre-tool hook: Before git commit, ensure tests were run
INPUT=$(cat)
COMMAND=$(echo "$INPUT" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('tool_input',{}).get('command',''))" 2>/dev/null)

if [[ "$COMMAND" == *"git commit"* ]]; then
  echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","additionalContext":"Pre-commit: phpunit + npm build if .vue/.js touched."}}'
fi

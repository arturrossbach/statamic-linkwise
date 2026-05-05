#!/bin/bash
# Pre-tool hook: Before git commit, ensure tests were run
INPUT=$(cat)
COMMAND=$(echo "$INPUT" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('tool_input',{}).get('command',''))" 2>/dev/null)

if [[ "$COMMAND" == *"git commit"* ]]; then
  echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","additionalContext":"PRE-COMMIT CHECK: Before committing, you MUST have run ./vendor/bin/phpunit tests/Unit/ in this session and confirmed all tests pass. If you have not, run them NOW before proceeding with the commit. Also run npm run build if any .vue/.js files were changed."}}'
fi

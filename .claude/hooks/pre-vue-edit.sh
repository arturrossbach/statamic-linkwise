#!/bin/bash
# Pre-tool hook: Before editing Vue files, remind to check Statamic UI components
INPUT=$(cat)
FILE_PATH=$(echo "$INPUT" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('tool_input',{}).get('file_path',''))" 2>/dev/null)

if [[ "$FILE_PATH" == *.vue ]] && [[ "$FILE_PATH" != *vendor* ]] && [[ "$FILE_PATH" != *node_modules* ]]; then
  echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","additionalContext":"UI-check: Statamic component? CLAUDE.md."}}'
fi

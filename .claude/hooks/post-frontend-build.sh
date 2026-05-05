#!/bin/bash
# Post-tool hook: After editing Vue/JS files, remind to build
INPUT=$(cat)
FILE_PATH=$(echo "$INPUT" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('tool_input',{}).get('file_path','') or d.get('tool_response',{}).get('filePath',''))" 2>/dev/null)

if [[ "$FILE_PATH" == *.vue ]] || [[ "$FILE_PATH" == resources/js/*.js ]]; then
  if [[ "$FILE_PATH" != *vendor* ]] && [[ "$FILE_PATH" != *node_modules* ]] && [[ "$FILE_PATH" != *dist* ]]; then
    echo '{"hookSpecificOutput":{"hookEventName":"PostToolUse","additionalContext":"BUILD NEEDED: You edited a frontend file. Run: source ~/.nvm/nvm.sh && nvm use 22 && npm run build — Do this BEFORE telling the user to test. Unbuild changes are invisible to the user."}}'
  fi
fi

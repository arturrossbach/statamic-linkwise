#!/bin/bash
# Post-tool hook: After editing PHP/Vue/JS source files, remind to test before claiming "fixed"

INPUT=$(cat)
FILE_PATH=$(echo "$INPUT" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('tool_input',{}).get('file_path','') or d.get('tool_response',{}).get('filePath',''))" 2>/dev/null)

# Only trigger for source files
if [[ "$FILE_PATH" == *.php ]] || [[ "$FILE_PATH" == *.vue ]] || [[ "$FILE_PATH" == *.js ]]; then
  # Skip test files, config, vendor, memory, settings, hooks
  if [[ "$FILE_PATH" == *test* ]] || [[ "$FILE_PATH" == *Test* ]] || [[ "$FILE_PATH" == *vendor* ]] || [[ "$FILE_PATH" == *memory* ]] || [[ "$FILE_PATH" == *settings* ]] || [[ "$FILE_PATH" == *hooks* ]] || [[ "$FILE_PATH" == *dist* ]]; then
    exit 0
  fi

  echo '{"hookSpecificOutput":{"hookEventName":"PostToolUse","additionalContext":"REMINDER: You just edited source code. Before telling the user it is fixed, you MUST: (1) Run the relevant unit tests, (2) If the change affects data shown in the UI, verify with a tinker/curl check on real data that the numbers match, (3) Think about what could go wrong — edge cases, stale data, race conditions. Do NOT say gefixt or neu laden until you have verified."}}'
fi

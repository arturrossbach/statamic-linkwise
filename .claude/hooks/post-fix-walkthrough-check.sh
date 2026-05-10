#!/bin/bash
# PostToolUse hook: when a Bash command's commit-message or output includes
# "kannst testen" / "fertig" / "fixed" / "is gefixt" — i.e. signals the
# user can now go test — remind to walk through the user-path table from
# feedback_user_path_walkthrough.md BEFORE handing it back to the user.
#
# Triggers on Bash tool only (commit-messages, completion echo'ed back,
# etc.). Does not fire on every Bash call, only when the output looks
# like a "done" signal.

INPUT=$(cat)

# Extract the tool_response output (Bash commands return stdout there)
OUTPUT=$(echo "$INPUT" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    out = d.get('tool_response', {}).get('output', '') or d.get('tool_response', {}).get('stdout', '')
    print(out[:8000])
except Exception:
    pass
" 2>/dev/null)

# Match the done-signal phrases anywhere in commit-messages or echo output.
# These are what I (Claude) tend to say to the user when wrapping up.
case "$OUTPUT" in
  *"kannst testen"*|*"is gefixt"*|*"ist gefixt"*|*"jetzt fertig"*|*"alles grün"*|*"feature complete"*)
    : # fall through
    ;;
  *)
    exit 0
    ;;
esac

cat <<'EOF'
{"hookSpecificOutput":{"hookEventName":"PostToolUse","additionalContext":"USER-PFAD WALKTHROUGH FÄLLIG (BLOCKING — see feedback_user_path_walkthrough.md): Bevor du dem User \"kannst testen\" / \"fertig\" / \"fixed\" sagst, MUSS die 10-Punkte-Tabelle gepostet sein:\n\n| # | Szenario | Betroffen? | Verhalten |\n|---|---|---|---|\n| 1 | Stale data nach parallel-edit | | |\n| 2 | Multi-tab race | | |\n| 3 | Re-open ohne refresh | | |\n| 4 | Position-mismatch | | |\n| 5 | Counter-konsistenz | | |\n| 6 | Empty / 0 / null | | |\n| 7 | Async outlives UI | | |\n| 8 | Conflict + retry | | |\n| 9 | Doppel-action | | |\n| 10 | Visual truth | | |\n\nWenn du nicht weißt: schreibe Test (tinker oder Playwright) statt zu raten. Wenn alle 10 = nicht-betroffen, mindestens 5 schriftlich begründen WARUM nicht (verhindert Stempel-Pflicht). Erst dann \"kannst testen\" sagen."}}
EOF

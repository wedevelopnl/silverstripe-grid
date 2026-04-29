#!/usr/bin/env bash
# Pre-push QA gate — intercepts `git push` commands and runs linters first.
# Full test suite runs in CI (GitHub Actions), so only fast checks here.
# Used as a Claude Code PreToolUse hook on the Bash tool.

set -euo pipefail

input="$(cat)"
command="$(echo "$input" | jq -r '.tool_input.command // empty')"

# Only intercept git push commands
if [[ ! "$command" =~ ^git\ push ]]; then
  exit 0
fi

echo "Pre-push QA gate: running linters before push..." >&2

cd "$(git rev-parse --show-toplevel 2>/dev/null || echo "${CLAUDE_PROJECT_DIR:-.}")"

if ! npm run lint; then
  echo "Lint failed — push blocked." >&2
  exit 2
fi

if ! npm run format:check; then
  echo "Format check failed — push blocked." >&2
  exit 2
fi

if ! npm run typecheck; then
  echo "Typecheck failed — push blocked." >&2
  exit 2
fi

if ! npx vite build; then
  echo "Vite build failed — push blocked." >&2
  exit 2
fi

if ! git diff --quiet -- client/dist; then
  echo "client/dist is out of sync with source — rebuild and commit before pushing." >&2
  echo "Run: npm run build && git add client/dist && git commit" >&2
  exit 2
fi

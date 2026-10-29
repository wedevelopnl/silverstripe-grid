#!/usr/bin/env bash
# Pre-push QA gate — intercepts `git push` commands and runs linters first.
# Full test suite runs in CI (GitHub Actions), so only fast checks here.
# Used as a Claude Code PreToolUse hook on the Bash tool.

set -euo pipefail

input="$(cat)"
command="$(echo "$input" | jq -r '.tool_input.command // empty')"

# Only intercept git push commands, also where one follows `&&`, `||`, `;`,
# `|`, `(` or a newline; `git push-x` and `git pushx` are other commands
push_pattern=$'(^|[;&|(\n])[[:space:]]*git[[:space:]]+push([[:space:];&|)]|$)'
if [[ ! "$command" =~ $push_pattern ]]; then
  exit 0
fi

project_root="$(git rev-parse --show-toplevel 2>/dev/null || echo "${CLAUDE_PROJECT_DIR:-.}")"

# A push after a `cd` elsewhere, as in `(cd ~/other-repo && git push)`, is that
# repository's push: replay the cds before it and gate only pushes of this one
before_push="${command%%"${BASH_REMATCH[0]}"*}"
cd_pattern=$'(^|[;&|(\n])[[:space:]]*cd[[:space:]]+([^;&|)\n]+)'
cd_targets=()
while [[ "$before_push" =~ $cd_pattern ]]; do
  target="${BASH_REMATCH[2]}"
  target="${target%"${target##*[![:space:]]}"}"
  target="${target#[\"\']}"
  target="${target%[\"\']}"
  if [[ "$target" == "~"* ]]; then
    target="$HOME${target#\~}"
  fi
  cd_targets+=("$target")
  before_push="${before_push#*"${BASH_REMATCH[0]}"}"
done
if ((${#cd_targets[@]} > 0)); then
  push_root="$(
    for target in "${cd_targets[@]}"; do
      cd "$target" 2>/dev/null || exit 0
    done
    git rev-parse --show-toplevel 2>/dev/null || true
  )"
  if [[ "$push_root" != "$project_root" ]]; then
    exit 0
  fi
fi

echo "Pre-push QA gate: running linters before push..." >&2

cd "$project_root"

# vite/esbuild output is not byte-identical across Node majors. The dist diff
# check below is only meaningful when the local Node major matches CI's
# (.nvmrc). Try `nvm use` first; only bail if the pinned Node isn't installed.
if [[ -f .nvmrc ]]; then
  pinned_major="$(cut -d. -f1 .nvmrc | tr -d '[:space:]v')"
  current_major="$(node --version | sed 's/^v//' | cut -d. -f1)"
  if [[ "$pinned_major" != "$current_major" ]]; then
    nvm_sh=""
    for candidate in "${NVM_DIR:-$HOME/.nvm}/nvm.sh" /opt/homebrew/opt/nvm/nvm.sh /usr/local/opt/nvm/nvm.sh; do
      if [[ -f "$candidate" ]]; then
        nvm_sh="$candidate"
        break
      fi
    done
    if [[ -n "$nvm_sh" ]]; then
      set +u
      # shellcheck source=/dev/null
      . "$nvm_sh"
      nvm use >/dev/null 2>&1 || true
      set -u
      current_major="$(node --version | sed 's/^v//' | cut -d. -f1)"
    fi
    if [[ "$pinned_major" != "$current_major" ]]; then
      echo "Node major mismatch: running v$current_major, .nvmrc pins v$pinned_major." >&2
      echo "Tried 'nvm use' but the pinned Node is unavailable — run 'nvm install' (or switch manually), then push." >&2
      exit 2
    fi
    echo "Switched Node to $(node --version) via nvm to match .nvmrc." >&2
  fi
fi

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

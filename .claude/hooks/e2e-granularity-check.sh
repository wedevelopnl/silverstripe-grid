#!/usr/bin/env bash
# E2E test granularity check — warns when a spec file being written to tests/E2E/specs/
# is not a multi-step journey: a single test() with fewer than three test.step() phases
# (the e2e-test-reference skill's shape is one test() made of test.step() phases).
set -euo pipefail

input="$(cat)"
tool="$(echo "$input" | jq -r '.tool_name // empty')"

# Only a Write carries the whole file; an Edit's fragment cannot be counted
if [[ "$tool" != "Write" ]]; then
  exit 0
fi

file_path="$(echo "$input" | jq -r '.tool_input.file_path // empty')"

if [[ ! "$file_path" =~ tests/E2E/specs/.*\.spec\.ts$ ]]; then
  exit 0
fi

content="$(echo "$input" | jq -r '.tool_input.content // empty')"
test_count=$(echo "$content" | grep -cE '^\s*test\(' || true)
step_count=$(echo "$content" | grep -cE 'test\.step\(' || true)

if [[ "$test_count" -le 1 && "$step_count" -lt 3 ]]; then
  # stderr on exit 0 never reaches Claude; additionalContext does. Never blocks.
  jq -n --arg ctx "E2E test granularity warning for ${file_path}: the spec has ${test_count} test() block(s) and ${step_count} test.step() phase(s). E2E specs in this project validate a complete user journey (3+ distinct user actions as test.step() phases). Options: extend it into a journey, test it at the unit or integration level, or fold it into a related journey spec. Conventions: the e2e-test-reference skill." \
    '{hookSpecificOutput: {hookEventName: "PreToolUse", additionalContext: $ctx}}'
fi

exit 0

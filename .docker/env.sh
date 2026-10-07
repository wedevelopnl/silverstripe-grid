#!/bin/sh
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
WORKTREE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
DIR_NAME="$(basename "$WORKTREE_DIR")"
ENV_FILE="$SCRIPT_DIR/.env"

HASH=$(printf '%s' "$DIR_NAME" | cksum | awk '{print $1}')
OFFSET=$((HASH % 1000))

WEB_PORT=$((8000 + OFFSET))
MODULES_WEB_PORT=$((18000 + OFFSET))
DB_PORT=$((13000 + OFFSET))

# The ports are derived, but the adapter is the developer's choice: keep it
# when regenerating a file that predates a newly added variable.
SS_GRID_ADAPTER=$(sed -n 's/^SS_GRID_ADAPTER=//p' "$ENV_FILE" 2>/dev/null || true)
SS_GRID_ADAPTER=${SS_GRID_ADAPTER:-tailwind}

cat > "$ENV_FILE" <<EOF
COMPOSE_PROJECT_NAME=${DIR_NAME}
WEB_PORT=${WEB_PORT}
MODULES_WEB_PORT=${MODULES_WEB_PORT}
DB_PORT=${DB_PORT}
SS_GRID_ADAPTER=${SS_GRID_ADAPTER}
EOF

echo "Generated .docker/.env:"
sed 's/^/  /' "$ENV_FILE"

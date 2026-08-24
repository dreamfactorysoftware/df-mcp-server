#!/bin/bash
set -euo pipefail

DAEMON_DIR="${DF_MCP_DAEMON_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../daemon" && pwd)}"

[[ -d "${DAEMON_DIR}" ]] || { echo "[mcp-daemon] Missing daemon dir: ${DAEMON_DIR}" >&2; exit 1; }

if [[ ! -f "${DAEMON_DIR}/dist/server.js" ]]; then
  echo "[mcp-daemon] dist/server.js missing; running npm run build..."
  (cd "${DAEMON_DIR}" && npm install && npm run build) || {
    echo "[mcp-daemon] Failed to build daemon" >&2
    exit 1
  }
fi

cd "${DAEMON_DIR}"

: "${MCP_DAEMON_HOST:=127.0.0.1}"
: "${MCP_DAEMON_PORT:=8006}"
: "${NODE_ENV:=production}"

export MCP_DAEMON_HOST MCP_DAEMON_PORT NODE_ENV

# Point the daemon at the same shared-secret file the PHP side writes. The
# daemon and the PHP proxy both default to <app-root>/storage/app/mcp_internal_key.
# When this package sits in the standard vendor layout we can resolve the app
# root confidently (its artisan file is the marker) and pin the path so the two
# sides agree even if the daemon cannot infer it. If unset here, the daemon
# falls back to a path computed relative to its own location.
if [[ -z "${MCP_INTERNAL_KEY_FILE:-}" ]]; then
  APP_ROOT="$(cd "${DAEMON_DIR}/../../../.." 2>/dev/null && pwd || true)"
  if [[ -n "${APP_ROOT}" && -f "${APP_ROOT}/artisan" && -d "${APP_ROOT}/storage/app" ]]; then
    export MCP_INTERNAL_KEY_FILE="${APP_ROOT}/storage/app/mcp_internal_key"
  fi
fi

echo "[mcp-daemon] Starting on ${MCP_DAEMON_HOST}:${MCP_DAEMON_PORT}"
exec node dist/server.js "$@"

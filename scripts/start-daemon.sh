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
# daemon and the PHP proxy both default to <app-root>/storage/framework/mcp_internal_key
# (not storage/app -- that is the stock "files" service root and is served over REST).
# When this package sits in the standard vendor layout we can resolve the app
# root confidently (its artisan file is the marker) and pin the path so the two
# sides agree even if the daemon cannot infer it. If unset here, the daemon
# falls back to a path computed relative to its own location.
if [[ -z "${MCP_INTERNAL_KEY_FILE:-}" ]]; then
  # Walk up looking for the app root instead of counting directories: the
  # package is five levels down in the stock vendor layout, but not when it is
  # symlinked in for development. artisan next to storage/ is the marker.
  APP_ROOT="${DF_APP_ROOT:-}"
  if [[ -z "${APP_ROOT}" ]]; then
    CANDIDATE="${DAEMON_DIR}"
    for _ in 1 2 3 4 5 6 7 8; do
      CANDIDATE="$(dirname "${CANDIDATE}")"
      [[ "${CANDIDATE}" == "/" ]] && break
      if [[ -f "${CANDIDATE}/artisan" && -d "${CANDIDATE}/storage/framework" ]]; then
        APP_ROOT="${CANDIDATE}"
        break
      fi
    done
  fi
  if [[ -n "${APP_ROOT}" && -f "${APP_ROOT}/artisan" && -d "${APP_ROOT}/storage/framework" ]]; then
    export MCP_INTERNAL_KEY_FILE="${APP_ROOT}/storage/framework/mcp_internal_key"
  else
    echo "[mcp-daemon] Could not locate the DreamFactory app root; set DF_APP_ROOT or MCP_INTERNAL_KEY_FILE" >&2
  fi
fi

echo "[mcp-daemon] Starting on ${MCP_DAEMON_HOST}:${MCP_DAEMON_PORT}"
exec node dist/server.js "$@"

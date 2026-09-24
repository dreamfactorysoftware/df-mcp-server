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
# Stateless (no session IDs, any node can answer any request) is the default.
# Set MCP_STATELESS=false for single-node installs that want warm sessions.
: "${MCP_STATELESS:=true}"

export MCP_DAEMON_HOST MCP_DAEMON_PORT NODE_ENV MCP_STATELESS

# Point the daemon at the shared-secret file DreamFactory writes:
# <app-root>/storage/framework/mcp_internal_key (not storage/app, which the stock
# "files" service serves over REST). Walk up for artisan beside storage/ rather
# than counting directories, so a symlinked dev checkout resolves too. The daemon
# user must be able to read storage/framework. MCP_INTERNAL_KEY, if set, wins.
if [[ -z "${MCP_INTERNAL_KEY:-}" && -z "${MCP_INTERNAL_KEY_FILE:-}" ]]; then
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
  if [[ -n "${APP_ROOT}" && -d "${APP_ROOT}/storage/framework" ]]; then
    export MCP_INTERNAL_KEY_FILE="${APP_ROOT}/storage/framework/mcp_internal_key"
  else
    echo "[mcp-daemon] Could not locate the DreamFactory app root; every /mcp call will be rejected. Set DF_APP_ROOT, MCP_INTERNAL_KEY_FILE or MCP_INTERNAL_KEY." >&2
  fi
fi

echo "[mcp-daemon] Starting on ${MCP_DAEMON_HOST}:${MCP_DAEMON_PORT}"
exec node dist/server.js "$@"

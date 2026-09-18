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

echo "[mcp-daemon] Starting on ${MCP_DAEMON_HOST}:${MCP_DAEMON_PORT}"
exec node dist/server.js "$@"

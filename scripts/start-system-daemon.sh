#!/bin/bash
# Starts the System API MCP daemon (dreamfactory/df-system-mcp-server) on this host,
# next to DreamFactory, for the system_mcp service type. It listens on loopback and
# calls DreamFactory back locally, matching this package's default
# MCP_SYSTEM_DAEMON_URL=http://127.0.0.1:3700, so no .env change is needed.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Composer installs it as a sibling package: vendor/dreamfactory/df-system-mcp-server.
# Trim path segments as text: a ../.. lookup resolves through a symlinked df-mcp-server
# (composer path repositories, dev links) and lands outside vendor/.
PACKAGE_DIR="${SCRIPT_DIR%/*}"
SYSTEM_DIR="${DF_SYSTEM_MCP_DIR:-${PACKAGE_DIR%/*}/df-system-mcp-server}"

if [[ ! -f "${SYSTEM_DIR}/package.json" ]]; then
  echo "[mcp-system-daemon] df-system-mcp-server not found at ${SYSTEM_DIR}." \
       "Install dreamfactory/df-system-mcp-server with composer or set DF_SYSTEM_MCP_DIR." >&2
  exit 1
fi
cd "${SYSTEM_DIR}"

# npm ci installs exactly what package-lock.json records and never rewrites it, so
# every host runs the same dependency versions. Only a checkout without a lockfile
# falls back to npm install.
npm_install() {
  if [[ -f package-lock.json ]]; then
    npm ci "$@" --no-audit --no-fund
  else
    npm install "$@" --no-audit --no-fund
  fi
}

# The package ships build/, so a normal start only needs the runtime dependencies.
if [[ ! -d node_modules/@modelcontextprotocol/sdk ]]; then
  echo "[mcp-system-daemon] node_modules missing; installing runtime dependencies..."
  npm_install --omit=dev || {
    echo "[mcp-system-daemon] Failed to install daemon dependencies" >&2
    exit 1
  }
fi

if [[ ! -f build/index.js ]]; then
  echo "[mcp-system-daemon] build/index.js missing; running npm run build..."
  { npm_install && npm run build; } || {
    echo "[mcp-system-daemon] Failed to build daemon" >&2
    exit 1
  }
fi

: "${MCP_SYSTEM_DAEMON_HOST:=127.0.0.1}"
: "${MCP_SYSTEM_DAEMON_PORT:=3700}"
# DreamFactory as seen from this host; DreamFactory's X-Mcp-Base-Url overrides it per session.
internal_base="${MCP_INTERNAL_BASE_URL:-http://127.0.0.1}"
: "${DREAMFACTORY_URL:=${internal_base%/}/api/v2}"

echo "[mcp-system-daemon] Starting on ${MCP_SYSTEM_DAEMON_HOST}:${MCP_SYSTEM_DAEMON_PORT} (DreamFactory: ${DREAMFACTORY_URL})"
# Hand the daemon only the settings it reads, not DreamFactory's whole environment
# (APP_KEY, DB_PASSWORD, ...).
exec env -i \
  PATH="${PATH}" \
  HOME="${HOME:-/tmp}" \
  NODE_ENV="${NODE_ENV:-production}" \
  MCP_SYSTEM_DAEMON_HOST="${MCP_SYSTEM_DAEMON_HOST}" \
  MCP_SYSTEM_DAEMON_PORT="${MCP_SYSTEM_DAEMON_PORT}" \
  DREAMFACTORY_URL="${DREAMFACTORY_URL}" \
  MCP_INTERNAL_KEY="${MCP_INTERNAL_KEY:-}" \
  MCP_ALLOWED_BASE_URLS="${MCP_ALLOWED_BASE_URLS:-}" \
  MCP_TRUST_LOOPBACK="${MCP_TRUST_LOOPBACK:-}" \
  SESSION_TTL_SECONDS="${SESSION_TTL_SECONDS:-1800}" \
  MCP_EXPOSE_API_KEYS="${MCP_EXPOSE_API_KEYS:-}" \
  node build/index.js "$@"

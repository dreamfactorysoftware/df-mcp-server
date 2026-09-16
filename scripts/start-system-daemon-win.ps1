$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

# Starts the System API MCP daemon (dreamfactory/df-system-mcp-server) next to
# DreamFactory for the system_mcp service type. Composer installs it as a sibling
# package; set DF_SYSTEM_MCP_DIR to use another location.

# Paths
$SCRIPT_DIR = Split-Path -Parent $MyInvocation.MyCommand.Path
if ($env:DF_SYSTEM_MCP_DIR) {
    $SYSTEM_DIR = $env:DF_SYSTEM_MCP_DIR
} else {
    # Parent of this package's directory, not "..\..": avoids resolving through a symlinked package.
    $SYSTEM_DIR = Join-Path (Split-Path -Parent (Split-Path -Parent $SCRIPT_DIR)) "df-system-mcp-server"
}

if (-not (Test-Path (Join-Path $SYSTEM_DIR "package.json"))) {
    Write-Error "[mcp-system-daemon] df-system-mcp-server not found at $SYSTEM_DIR. Install dreamfactory/df-system-mcp-server with composer or set DF_SYSTEM_MCP_DIR."
    exit 1
}
$SYSTEM_DIR = (Resolve-Path $SYSTEM_DIR).Path

Push-Location $SYSTEM_DIR
try {
    if (-not (Test-Path "build\index.js")) {
        Write-Host "[mcp-system-daemon] build\index.js missing; running npm run build..."
        npm install --no-audit --no-fund
        if ($LASTEXITCODE -ne 0) { throw "[mcp-system-daemon] npm install failed" }
        npm run build
        if ($LASTEXITCODE -ne 0) { throw "[mcp-system-daemon] npm run build failed" }
    }
    if (-not (Test-Path "node_modules\@modelcontextprotocol\sdk")) {
        Write-Host "[mcp-system-daemon] Installing production dependencies..."
        npm install --omit=dev --no-audit --no-fund
        if ($LASTEXITCODE -ne 0) { throw "[mcp-system-daemon] npm install failed" }
    }
} finally {
    Pop-Location
}

# Default environment variables
if (-not $env:MCP_SYSTEM_DAEMON_HOST) { $env:MCP_SYSTEM_DAEMON_HOST = "127.0.0.1" }
if (-not $env:MCP_SYSTEM_DAEMON_PORT) { $env:MCP_SYSTEM_DAEMON_PORT = "3700" }
if (-not $env:DREAMFACTORY_URL) {
    if ($env:MCP_INTERNAL_BASE_URL) { $base = $env:MCP_INTERNAL_BASE_URL.TrimEnd('/') } else { $base = "http://127.0.0.1" }
    $env:DREAMFACTORY_URL = "$base/api/v2"
}
if (-not $env:NODE_ENV) { $env:NODE_ENV = "production" }

Write-Host "[mcp-system-daemon] Starting on $($env:MCP_SYSTEM_DAEMON_HOST):$($env:MCP_SYSTEM_DAEMON_PORT) (DreamFactory: $($env:DREAMFACTORY_URL))"

# Start daemon in background
$process = Start-Process -FilePath "node" -ArgumentList "build\index.js" `
    -WorkingDirectory $SYSTEM_DIR -WindowStyle Hidden -PassThru

Write-Host "[mcp-system-daemon] Daemon started (PID: $($process.Id))"
exit 0

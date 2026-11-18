$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

# Paths
$SCRIPT_DIR = Split-Path -Parent $MyInvocation.MyCommand.Path
$DAEMON_DIR = Resolve-Path (Join-Path $SCRIPT_DIR "..\daemon")

# Ensure daemon folder exists
if (-not (Test-Path $DAEMON_DIR)) {
    Write-Error "[mcp-daemon] Missing daemon dir: $DAEMON_DIR"
    exit 1
}

# Install runtime dependencies from the lockfile (dev-only packages are not needed to run dist/)
Write-Host "[mcp-daemon] Installing runtime dependencies..."
Push-Location $DAEMON_DIR
if (Test-Path (Join-Path $DAEMON_DIR "package-lock.json")) {
    npm ci --omit=dev --no-audit --no-fund
} else {
    npm install --omit=dev --no-audit --no-fund
}
if ($LASTEXITCODE -ne 0) {
    Write-Error "[mcp-daemon] npm dependency install failed"
    exit 1
}
Pop-Location

# Default environment variables
if (-not $env:MCP_DAEMON_HOST) { $env:MCP_DAEMON_HOST = "127.0.0.1" }
if (-not $env:MCP_DAEMON_PORT) { $env:MCP_DAEMON_PORT = "8006" }
if (-not $env:NODE_ENV)        { $env:NODE_ENV        = "production" }

Write-Host "[mcp-daemon] Starting daemon via node dist/server.js..."

# Start daemon in background
$process = Start-Process -FilePath "node" -ArgumentList "dist/server.js" `
    -WorkingDirectory $DAEMON_DIR -WindowStyle Hidden -PassThru

Write-Host "[mcp-daemon] Daemon started (PID: $($process.Id))"
exit 0

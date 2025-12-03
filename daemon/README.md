# MCP Daemon Server (Node.js)

TypeScript implementation of the MCP Daemon Server for DreamFactory. This daemon maintains persistent MCP Server instances to avoid the PHP-FPM worker isolation problem.

## Features

- Long-lived process with cached MCP Server instances
- HTTP API compatible with Laravel's `McpDaemonClient`
- SSE (Server-Sent Events) support for streaming
- Health check and cache management endpoints
- All DreamFactory database tools using MCP SDK's `server.tool()` pattern
- Comprehensive error handling with user-friendly messages
- Full support for tables, records, stored procedures, and functions

## Installation

```bash
cd daemon
npm install
```

## Configuration

Set environment variables or use defaults:

```bash
export MCP_DAEMON_HOST=127.0.0.1
export MCP_DAEMON_PORT=8006
```

## Running

### Development
```bash
npm run dev
```

### Production
```bash
npm start
```

## Endpoints

- `GET /health` - Health check with cached services list
- `GET /ping` - Alias for `/health`
- `POST /mcp/cache/clear` - Clear cache (body: `{"service": "serviceName"}` or `{}` for all)
- `GET /mcp/{serviceName}` - SSE endpoint (requires `Accept: text/event-stream`)
- `POST /mcp/{serviceName}` - JSON-RPC endpoint

### Required Headers

- `X-Mcp-Config`: JSON with `{"api_name": "...", "api_key": "..."}`
- `X-Mcp-Base-Url`: Base URL for DreamFactory API

## DreamFactory Configuration

Update DreamFactory `.env`:

```env
MCP_DAEMON_ENABLED=true
MCP_DAEMON_URL=http://127.0.0.1:8006
```

The Laravel controller will proxy all MCP requests to this Node daemon.


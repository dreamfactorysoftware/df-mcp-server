# MCP Daemon Server (Node.js)

TypeScript implementation of the MCP Daemon Server for DreamFactory. This daemon maintains persistent MCP Server instances to avoid the PHP-FPM worker isolation problem.

## Features

- Long-lived process with cached MCP Server instances
- HTTP API compatible with Laravel's `McpDaemonClient`
- Streamable HTTP transport with session management
- Health check and cache management endpoints
- OAuth-based authentication via DreamFactory session tokens
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
# Lazy tool loading (per-service lazy_mode auto|on|off is set in DreamFactory):
export MCP_LAZY_PASSTHROUGH=codex,grok,hermes   # clients that always get the full catalog
export MCP_LAZY_THRESHOLD_BYTES=32768           # auto: facade above this serialized tools/list size
export MCP_LAZY_PAGE_CHARS=6000                 # page tool results longer than this
```

Tests: `npm test` (node --test via tsx).

## Running

### Development
```bash
npm run dev
```

### Production
```bash
npm start
```

## DreamFactory Configuration

Update DreamFactory `.env`:

```env
MCP_DAEMON_ENABLED=true
MCP_DAEMON_URL=http://127.0.0.1:8006
```

The Laravel controller will proxy all MCP requests to this Node daemon, passing the authenticated user's session token via the `X-DreamFactory-Session-Token` header.

## Authentication Flow

1. User authenticates with DreamFactory via OAuth
2. DreamFactory validates the request and obtains a session token
3. The Laravel controller forwards MCP requests to the daemon with the session token
4. The daemon uses the session token to make authenticated API calls to DreamFactory

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/health` | Health check with active sessions list |
| `GET` | `/ping` | Alias for `/health` |
| `POST` | `/mcp/cache/clear` | Clear session cache (body: `{"service": "serviceName"}` or `{}` for all) |
| `ALL` | `/mcp/{serviceName}` | MCP protocol endpoint (JSON-RPC) |

### Required Headers

| Header | Description |
|--------|-------------|
| `X-DreamFactory-Session-Token` | DreamFactory session token (required for authentication) |
| `X-Mcp-Base-Url` | Base URL for DreamFactory API (e.g., `https://host/api/v2`) |

### Optional Headers

| Header | Description |
|--------|-------------|
| `Mcp-Session-Id` | Session ID for existing MCP sessions |

## Available MCP Tools

The daemon exposes the following tools via the MCP protocol:

### Schema Tools
| Tool | Description |
|------|-------------|
| `get_tables` | List all tables available in the database |
| `get_table_schema` | Retrieve the full schema of a specific table |
| `get_table_fields` | Get field definitions for a table |
| `get_table_relationships` | Get relationship definitions for a table |
| `get_database_resources` | List all resources available in the database service |

### Data Tools
| Tool | Description |
|------|-------------|
| `get_table_data` | Retrieve table data with filtering, pagination, and sorting |
| `create_records` | Create one or more records in a table |
| `update_records` | Update (patch) records in a table |
| `delete_records` | Delete records from a table |

### Stored Procedures & Functions
| Tool | Description |
|------|-------------|
| `get_stored_procedures` | List stored procedures available in the database |
| `call_stored_procedure` | Execute a stored procedure with parameters |
| `get_stored_functions` | List stored functions available in the database |
| `call_stored_function` | Execute a stored function with parameters |

### Connector Stubs
| Tool | Description |
|------|-------------|
| `search` | Stub search implementation for connectors that require it |
| `fetch` | Stub fetch implementation for connectors that require it |

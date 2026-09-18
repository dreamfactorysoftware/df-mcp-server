# MCP Daemon Server (Node.js)

TypeScript implementation of the MCP Daemon Server for DreamFactory. This daemon maintains persistent MCP Server instances to avoid the PHP-FPM worker isolation problem.

## Features

- Long-lived process with cached MCP Server instances
- HTTP API compatible with Laravel's `McpDaemonClient`
- Streamable HTTP transport, stateless by default (any node can answer any request); `MCP_STATELESS=false` for process-pinned sessions
- Health check and cache management endpoints
- **Dual authentication modes**: OAuth session tokens OR API-key-only authentication
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
# Session mode: stateless (default) issues no Mcp-Session-Id and keeps no state, so
# load-balanced nodes need no stickiness. false = warm sessions pinned to this process.
export MCP_STATELESS=true
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

The Laravel controller will proxy all MCP requests to this Node daemon, passing authentication credentials via headers.

## Authentication

The daemon accepts two credential kinds. **At least one is required**; the PHP proxy authenticates every request first and forwards the credentials that were validated.

| Mode | Headers | Use Case |
|------|---------|----------|
| **Session Token (OAuth)** | `X-DreamFactory-Session-Token` | User-based authentication via the OAuth flow |
| **API Key Only** | `X-DreamFactory-API-Key` | App-based authentication (service must enable `allow_api_key_auth`; app must be active with a role assigned) |
| **Both** | Both headers | Session token supplies the user identity; the API key supplies app context |

### OAuth Flow (Session Token)

1. User authenticates with DreamFactory via OAuth
2. DreamFactory validates the request and obtains a session token
3. The Laravel controller forwards MCP requests to the daemon with the session token
4. The daemon uses the session token to make authenticated API calls to DreamFactory

### API Key Only Flow

1. Client sends requests with the `X-DreamFactory-API-Key` header (64-char hex key)
2. The Laravel controller validates the key: the service's `allow_api_key_auth` flag must be on, and the key's app must exist, be active, and have a role assigned
3. The controller forwards the key to the daemon, which attaches it to DreamFactory API calls
4. Access is controlled by the app's assigned role permissions (same as key-only REST calls)

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
| `X-Mcp-Base-Url` | Base URL for DreamFactory API (e.g., `https://host/api/v2`) |

### Authentication Headers (at least one required)

| Header | Description |
|--------|-------------|
| `X-DreamFactory-Session-Token` | DreamFactory session token (OAuth flow, or layered on an API key for user RBAC) |
| `X-DreamFactory-API-Key` | DreamFactory API key (API-key-only auth; the key's app must have a role) |

### Optional Headers

| Header | Description |
|--------|-------------|
| `Mcp-Session-Id` | Session ID for existing MCP sessions (`MCP_STATELESS=false` only; ignored in stateless mode) |
| `X-Mcp-Client-Name` | Client name the PHP proxy resolved (OAuth client registration or API-key app); decides lazy passthrough when the request carries no `initialize` |

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

Prefixed verb tools (`{api}_{verb}`) share one Zod schema per verb; descriptions are short and the full query-syntax guide lives in the server `instructions` so `tools/list` does not repeat a ~400-token essay once per database. Cross-service aggregators (`all_get_tables`, `all_find_table`, `all_list_files`, …) are registered only when two or more services of that category are in the connection's catalog.

Which backend services appear in the catalog is decided by PHP (`AvailableServices`) before the daemon sees the list. Admins pick them on **Exposed Services** (API Generation & Connections → MCP Server). An empty list means no auto DB/file tools — the daemon will not rediscover every service on the instance. Set `MCP_SCOPE_TOOLS=false` to restore the instance-wide catalog when the list is also empty.

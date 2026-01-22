# MCP Daemon Server (Node.js)

TypeScript implementation of the MCP Daemon Server for DreamFactory. This daemon maintains persistent MCP Server instances to avoid the PHP-FPM worker isolation problem.

## Features

- Long-lived process with cached MCP Server instances
- HTTP API compatible with Laravel's `McpDaemonClient`
- Streamable HTTP transport with session management
- Health check and cache management endpoints
- **Dual authentication modes**: OAuth session tokens OR API-key-only authentication
- All DreamFactory database tools using MCP SDK's `server.tool()` pattern
- Comprehensive error handling with sanitized, user-friendly messages
- Automatic session TTL and cleanup (1-hour default)
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

## DreamFactory Configuration

Update DreamFactory `.env`:

```env
MCP_DAEMON_ENABLED=true
MCP_DAEMON_URL=http://127.0.0.1:8006
```

The Laravel controller will proxy all MCP requests to this Node daemon, passing authentication credentials via headers.

## Authentication

The daemon supports two authentication modes. **At least one is required**.

### Authentication Modes

| Mode | Headers Required | Use Case |
|------|------------------|----------|
| **Session Token (OAuth)** | `X-DreamFactory-Session-Token` | User-based authentication via OAuth flow |
| **API Key Only** | `X-DreamFactory-API-Key` | App-based authentication (app must have role assigned) |
| **Both** | Both headers | User identity with app context |

### Authentication Precedence

When **both** session token and API key are provided:
- DreamFactory uses the **session token** for user identity and permissions
- The **API key** provides app context for logging and app-specific settings
- Both headers are forwarded to DreamFactory API calls

### OAuth Flow (Session Token)

1. User authenticates with DreamFactory via OAuth
2. DreamFactory validates the request and obtains a session token
3. The Laravel controller forwards MCP requests to the daemon with the session token
4. The daemon uses the session token to make authenticated API calls to DreamFactory

### API Key Only Flow

1. Client sends request with `X-DreamFactory-API-Key` header
2. DreamFactory validates the API key and checks the app has a role assigned
3. The daemon uses the API key to make authenticated API calls to DreamFactory
4. Access is controlled by the app's assigned role permissions

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
| `X-Mcp-Config` | JSON with `{"api_name": "..."}` |
| `X-Mcp-Base-Url` | Base URL for DreamFactory API |

### Authentication Headers (at least one required)

| Header | Description |
|--------|-------------|
| `X-DreamFactory-Session-Token` | DreamFactory session token (for OAuth authentication) |
| `X-DreamFactory-API-Key` | DreamFactory API key (for API-key-only auth, app must have role) |

### Optional Headers

| Header | Description |
|--------|-------------|
| `Mcp-Session-Id` | Session ID for existing MCP sessions |

## Session Management

The daemon automatically manages session lifecycle:

- **Session TTL**: Sessions expire after 1 hour of inactivity (configurable)
- **Automatic Cleanup**: Expired sessions are cleaned up every 10 minutes
- **Graceful Shutdown**: All sessions are properly closed on SIGINT

The `/health` endpoint includes session statistics for monitoring.

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

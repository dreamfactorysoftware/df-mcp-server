## DreamFactory MCP Service

> **Note:** This repository contains the mcp service features of the DreamFactory platform. If you want the full DreamFactory platform, visit the main [DreamFactory repository](https://github.com/dreamfactorysoftware/dreamfactory).

## Overview

DreamFactory is a secure, self-hosted enterprise data access platform that provides governed API access to any data source, connecting enterprise applications and on-prem LLMs with role-based access and identity passthrough.

## Installation

Edit your project’s composer.json to require the following package.

	“require”:{
		"dreamfactory/df-mcp-server": "~1.1.0"
	}

Save your composer.json and do a "composer update" to install the package.

### MCP Daemon process
The Laravel package proxies every MCP request through a persistent Node.js daemon that keeps long-lived MCP server instances warm.

1. Install dependencies
   ```
   cd daemon
   npm install
   ```
2. Configure the daemon host/port (or use defaults) and point DreamFactory to it by adding the following to your `.env` file:
   ```
   MCP_DAEMON_ENABLED=true
   MCP_DAEMON_URL=http://127.0.0.1:8006
   ```
3. Start the daemon (choose the mode you need):
   ```
   # Development
   npm run dev

   # Production
   npm start
   ```

Once the daemon is online, the MCP routes in DreamFactory automatically forward traffic to it.

### Running multiple DreamFactory nodes (stateless mode)

By default the daemon keeps each MCP session in memory, so every request for a session must reach the same node. Behind a load balancer this breaks: MCP clients do not return affinity cookies, so requests round-robin and hit a node that has never seen the session, which fails with `Bad Request: Server not initialized`.

If you run more than one DreamFactory node behind a load balancer, start the daemon in **stateless mode**:

```
MCP_STATELESS=true
```

No session IDs are issued and no session state is kept — every request carries everything the daemon needs, so any node can answer any request. No load-balancer stickiness or shared cache is required. `GET /health` reports the active mode.

Trade-off: the server-initiated SSE stream is unavailable (`GET` returns `405`), and the MCP server is rebuilt per request. Leave this unset for single-node installs, where the default warm-session behavior is faster.

### Configuration

Set `APP_URL` in your DreamFactory `.env` to the **external URL clients use to reach DreamFactory** — the public address (e.g. `https://df.example.com`), **not** `http://localhost`. The MCP server uses `APP_URL` to build its OAuth discovery and callback URLs and to validate session tokens server-side. If it is left as `localhost` (or any address clients can't reach), MCP OAuth fails. After changing it, run `php artisan config:clear`.

### Authentication

The MCP service uses OAuth-based authentication. Users must authenticate with DreamFactory via OAuth to obtain a session token. The Laravel controller validates requests and passes the session token to the daemon via the `X-DreamFactory-Session-Token` header.

See `daemon/README.md` for advanced options, available tools, and management endpoints.

## System API MCP Server

Besides the data-plane `mcp` service type, this package registers a second service type,
**`system_mcp` ("System API MCP Server")**. Create it from the MCP group of the service
type list. Instead of exposing tables/schemas/files it exposes DreamFactory's own
**System API** (`/api/v2/system/*`) as MCP tools — list/create/update/delete services,
roles, apps/API keys, admins, and read the environment — so an AI client (Claude Desktop,
Cursor, ChatGPT, DreamFactory's AI chat) can *administer the instance*.

The `system_mcp` type reuses everything the `mcp` type has (same `/mcp/{service}` OAuth 2.1
front door, same `POST /api/v2/{service}/rpc` session-token bridge, same audit log,
`disabled_tools`), but proxies to a separate daemon,
[`df-system-mcp-server`](https://github.com/dreamfactorysoftware/df-system-mcp-server),
instead of the bundled data daemon. Custom tools are not supported on the system server.

### Environment variables

| Variable | Default | Purpose |
| --- | --- | --- |
| `MCP_SYSTEM_DAEMON_ENABLED` | `true` | Gate the `system_mcp` type. When false, requests get a 503 naming this variable. |
| `MCP_SYSTEM_DAEMON_URL` | `http://127.0.0.1:3700` | Base URL of the running `df-system-mcp-server`. In Docker use the service name, e.g. `http://df-system-mcp:3700`. |
| `MCP_INTERNAL_KEY` | *(unset)* | Optional shared secret. When set, DreamFactory sends `X-Mcp-Internal-Key` to **both** daemons; set the same value on the daemons so they reject direct callers. |
| `MCP_INTERNAL_BASE_URL` | *(unset)* | Already used by the data daemon; also used here as the URL the system daemon calls back into DreamFactory with (e.g. `http://web`). |

Run `php artisan config:clear` after changing any of these.

### Running `df-system-mcp-server`

Docker (same network as DreamFactory):

```
git clone https://github.com/dreamfactorysoftware/df-system-mcp-server && cd df-system-mcp-server
docker build -t df-system-mcp .
docker run -d --name df-system-mcp --network dreamfactory_default \
  -e DREAMFACTORY_URL=http://web/api/v2 \
  -e MCP_INTERNAL_KEY=<same value as DreamFactory> \
  -p 3700:3700 df-system-mcp
# or use the repo's docker-compose.example.yml
```

Bare Node (20+):

```
git clone https://github.com/dreamfactorysoftware/df-system-mcp-server && cd df-system-mcp-server
npm ci
PORT=3700 DREAMFACTORY_URL=http://127.0.0.1/api/v2 scripts/start-daemon.sh
```

Then in DreamFactory's `.env`: `MCP_SYSTEM_DAEMON_URL=http://127.0.0.1:3700` (or the
Docker service URL) and create a service of type **System API MCP Server**, e.g. `sysmcp`.
Its MCP endpoint is `https://<your-df>/mcp/sysmcp`.

### Security

Every tool call runs **as the OAuth'd (or session-authenticated) DreamFactory user**, under
that user's role. The daemon forwards the user's session token (and the service's API key)
to `/api/v2/system/*`; DreamFactory's RBAC decides what is allowed. Only administrators can
create/modify services, roles, apps and admins — a non-admin user connecting to a
`system_mcp` service can only do what their role permits. Use `disabled_tools` in the service
config to remove destructive tools (e.g. `delete_service`) entirely, and set
`MCP_INTERNAL_KEY` so nothing on the host network can talk to the daemon directly.

### Example client configuration

Claude Desktop / Cursor (`mcpServers`):

```json
{
  "mcpServers": {
    "dreamfactory-admin": {
      "url": "https://df.example.com/mcp/sysmcp"
    }
  }
}
```

The client discovers the OAuth endpoints via `/.well-known/oauth-authorization-server/mcp/sysmcp`,
registers dynamically, and opens the DreamFactory login page; after login it receives a bearer
token and can call `tools/list` / `tools/call`.

First-party callers with a DreamFactory session token can skip OAuth:

```
curl -X POST https://df.example.com/api/v2/sysmcp/rpc \
  -H "X-DreamFactory-Session-Token: <token>" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

## Feedback and Contributions

* Feedback is welcome in the form of pull requests and/or issues.
* Contributions should generally follow the strategy outlined in ["Contributing to a project"](https://help.github.com/articles/fork-a-repo#contributing-to-a-project)
* All pull requests must be in a ["git flow"](https://github.com/nvie/gitflow) feature branch and formatted as [PSR-2 compliant](http://www.php-fig.org/psr/psr-2/) to be considered.

### License

The DreamFactory scripting script repository is open-sourced software available for use under the [Apache Version 2.0 license](http://www.apache.org/licenses/LICENSE-2.0).

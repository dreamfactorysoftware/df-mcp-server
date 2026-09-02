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

### Scoping `tools/list` to the connected service

MCP services only advertise the database and file services you attach to them. Connecting to `/mcp/storefront` no longer dumps every other DB on the instance into `tools/list`.

**In the admin UI:** API Generation & Connections → your MCP Server service → **Exposed Services**. Multi-select the database and file services this endpoint should wrap, then save. Reconnect the MCP client.

Empty always means none: custom tools, `search`/`fetch`, and global tools only — no auto-generated table/file verbs. Pick at least one service if this endpoint should query a database.

On upgrade, existing MCP services are backfilled with every database and file service that existed at migrate time, so their `tools/list` does not shrink. New databases created later are not advertised until you add them to Exposed Services.

To restore the instance-wide catalog (every accessible DB/file, including ones created later):

```env
MCP_SCOPE_TOOLS=false
```

Verb schemas are still sent per prefixed tool. MCP clients require a full `inputSchema` (`type: "object"`) on each tool, so JSON Schema `$ref` sharing is not used. Descriptions are short; query syntax lives once in the server instructions. Cross-service `all_*` tools register only when two or more services of that category are in the catalog.

### Authentication

The MCP service uses OAuth-based authentication. Users must authenticate with DreamFactory via OAuth to obtain a session token. The Laravel controller validates requests and passes the session token to the daemon via the `X-DreamFactory-Session-Token` header.

See `daemon/README.md` for advanced options, available tools, and management endpoints.

## Feedback and Contributions

* Feedback is welcome in the form of pull requests and/or issues.
* Contributions should generally follow the strategy outlined in ["Contributing to a project"](https://help.github.com/articles/fork-a-repo#contributing-to-a-project)
* All pull requests must be in a ["git flow"](https://github.com/nvie/gitflow) feature branch and formatted as [PSR-2 compliant](http://www.php-fig.org/psr/psr-2/) to be considered.

### License

The DreamFactory scripting script repository is open-sourced software available for use under the [Apache Version 2.0 license](http://www.apache.org/licenses/LICENSE-2.0).

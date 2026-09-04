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

Trade-off: the MCP server is rebuilt per request. Leave this unset for single-node installs, where the default warm-session behavior is faster.

### PHP-FPM sizing

`GET /mcp/{service}` (the server-initiated SSE stream) always returns `405`; clients fall back to POST-only as the MCP spec allows. The daemon never pushes notifications on that stream, and proxying it pinned one PHP-FPM worker per MCP session for the full daemon timeout, which starved small pools.

Every proxied MCP call still needs **two** PHP-FPM workers from the same pool: one holds the client's request open while the daemon works, and the daemon's own REST call back into DreamFactory needs another. Size `pm.max_children` for at least twice the expected number of concurrent MCP calls (the Debian default of 5 is exhausted by 3 concurrent calls), or point `MCP_INTERNAL_BASE_URL` at a separate FPM pool for daemon-originated traffic.

```
# Seconds one PHP worker waits for the daemon to answer a proxied MCP call (default 300).
# The daemon caps each of its own REST sub-calls at 30s; lower this only if no tool chains many sub-calls.
MCP_DAEMON_TIMEOUT=300
```

A call that exceeds it returns `504` with `MCP daemon did not respond within Ns` rather than holding the worker.

### Lazy tool loading (search / describe / call facade)

A DreamFactory MCP service over a few hundred tables produces a tool catalog no AI client can carry cheaply: every turn re-sends every tool schema. Each MCP service has a **Lazy Tool Loading** setting (`lazy_mode`: `auto` / `on` / `off`, default `auto`):

- `off` — every tool is advertised, exactly as before.
- `on` — `tools/list` returns four facade tools (`search_tools`, `describe_tool`, `call_tool`, `fetch_more`) plus up to 8 "hot" tools this service's recent sessions actually used. The full catalog stays callable through `call_tool`.
- `auto` — the facade is used only when the full catalog would exceed ~8k tokens (32 KB); small catalogs are served in full because they lose under a facade.

Clients that already defer tool schemas themselves (`initialize.clientInfo.name` containing `codex`, `grok` or `hermes`) always get the full catalog. Override the list with `MCP_LAZY_PASSTHROUGH=codex,grok,hermes` and the threshold with `MCP_LAZY_THRESHOLD_BYTES` on the daemon.

When the facade is active, tool results are also minified, stripped of PHP stack traces, and paged above 6,000 characters (`MCP_LAZY_PAGE_CHARS`); `fetch_more(handle, offset)` returns the rest. The tool list is fixed for the life of a session — the daemon never sends `notifications/tools/list_changed`, since that invalidates the client's prompt cache.

Every request row in `mcp_request_log` records what lazy mode saved (`mode`, `catalog_tokens`, `preamble_saved_per_turn`, `result_chars_withheld`, `facade_calls`), and the usage aggregate exposes `tokens_saved`.

### Configuration

Set `APP_URL` in your DreamFactory `.env` to the **external URL clients use to reach DreamFactory** — the public address (e.g. `https://df.example.com`), **not** `http://localhost`. The MCP server uses `APP_URL` to build its OAuth discovery and callback URLs. If it is left as `localhost` (or any address clients can't reach), MCP OAuth fails. Login and session validation run in-process and do not depend on it. After changing it, run `php artisan config:clear`.

### Authentication

The MCP service uses OAuth-based authentication. Users must authenticate with DreamFactory via OAuth to obtain a session token. The Laravel controller validates requests and passes the session token to the daemon via the `X-DreamFactory-Session-Token` header.

See `daemon/README.md` for advanced options, available tools, and management endpoints.

## Feedback and Contributions

* Feedback is welcome in the form of pull requests and/or issues.
* Contributions should generally follow the strategy outlined in ["Contributing to a project"](https://help.github.com/articles/fork-a-repo#contributing-to-a-project)
* All pull requests must be in a ["git flow"](https://github.com/nvie/gitflow) feature branch and formatted as [PSR-2 compliant](http://www.php-fig.org/psr/psr-2/) to be considered.

### License

The DreamFactory scripting script repository is open-sourced software available for use under the [Apache Version 2.0 license](http://www.apache.org/licenses/LICENSE-2.0).

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

Exposed Services applies only to the data-plane `mcp` type. The `system_mcp` type (see [System API MCP Server](#system-api-mcp-server)) exposes the System API itself and has no DB/file tool catalog, so the picker is hidden there and `exposed_services` / `scope_tools` / `MCP_SCOPE_TOOLS` are ignored.

### Read-only servers (Allow writes)

Each MCP service has an **Allow writes** switch (`allow_writes`, default on). Turn it off to guarantee the server can never change data, whatever the connected role or per-tool settings allow:

- The write tools are not registered at all — `create_records`, `update_records`, `delete_records`, `call_stored_procedure`, `call_stored_function`, `create_file`, `create_folder` and `delete_file` disappear from `tools/list` in both the prefixed and the merged tool style, and the lazy facade's `call_tool` / `describe_tool` / `search_tools` cannot reach them either.
- Reads, `aggregate_data`, `list_apis` and the `all_*` aggregators are unaffected. Custom tools are kept only if they are API tools using `GET`; non-GET API tools and function tools are hidden too (the daemon logs which, and the instructions say how many).
- The server instructions tell the model the server is read-only, so it does not hunt for write tools.

Clients must reconnect to pick up a change. The switch is hidden for `system_mcp` services, whose daemon has no DB/file write verbs.

#### Role denials are permission errors

When DreamFactory refuses a tool call because the session's role lacks access (a `403`, or the `401 "User is not authenticated"` df-core returns for a key-only role with no access), the tool result is `isError: true` with a message starting `Permission Error: the session's role may not <tool>` and a note that re-authenticating will not help. It is never reported as an authentication error, so the model does not waste turns retrying login.

### Authentication

By default the MCP service uses OAuth-based authentication. Users authenticate with DreamFactory via OAuth to obtain a session token; the Laravel controller validates requests and passes the session token to the daemon via the `X-DreamFactory-Session-Token` header.

#### API key authentication (per-service opt-in)

Each MCP service can additionally accept a static DreamFactory API key by enabling **Allow API Key Authentication** (`allow_api_key_auth`, default off) in its config. When enabled, clients may send:

| Header | Description |
|--------|-------------|
| `X-DreamFactory-API-Key` | A DreamFactory app API key (64-char hex). The app must be **active** and have a **role assigned**; that role scopes every tool call. |
| `X-DreamFactory-Session-Token` | Optional DF session JWT layered on top of the key to add user identity and user-specific RBAC. |

Rules:

- An `Authorization: Bearer ...` header **always wins** — such requests go through the unchanged OAuth path, regardless of any API-key headers.
- With the flag off (the default), behavior is exactly as before: requests without a Bearer token get `401` with OAuth discovery info.
- Key-only requests run under the key app's role (same as API-key-only calls to the REST API). Key + session-token requests use the token's user identity on top of the app context.
- API-key-authenticated calls are recorded in the `mcp_request_log` audit table like OAuth calls (with the app/role attribution and no OAuth client).
- The flag applies to `system_mcp` ([System API MCP Server](#system-api-mcp-server)) services too — the config model is shared, so an admin can opt a System API MCP endpoint into key auth the same way. It stays OAuth-only until then (the flag defaults off), and a key-authenticated session's role gates the System API calls the daemon makes exactly as it gates them through the REST API.

Example key-only call:

```bash
curl -X POST https://df.example.com/mcp/my-mcp \
  -H 'Content-Type: application/json' \
  -H 'X-DreamFactory-API-Key: <64-char-hex-app-key>' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"curl","version":"1.0"}}}'
```

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
`disabled_tools`), but proxies to a second Node daemon,
[`df-system-mcp-server`](https://github.com/dreamfactorysoftware/df-system-mcp-server),
instead of the bundled data daemon. Like the data daemon it normally runs on the DreamFactory
host; it can also run as a separate container. Custom tools are not supported on the system server.

### Environment variables

| Variable | Default | Purpose |
| --- | --- | --- |
| `MCP_SYSTEM_DAEMON_ENABLED` | `true` | Gate the `system_mcp` type. When false, requests get a 503 naming this variable. |
| `MCP_SYSTEM_DAEMON_URL` | `http://127.0.0.1:3700` | Base URL of `df-system-mcp-server`. Keep the default for the daemon on this host; for a sidecar container use its service URL, e.g. `http://df-system-mcp:3700`. |
| `MCP_SYSTEM_DAEMON_BASE_URL` | *(unset)* | DreamFactory URL the system daemon calls back (sent as `X-Mcp-Base-Url`). Leave unset when the daemon runs on this host. For a sidecar set an address it can reach, e.g. `http://web`. Falls back to `MCP_INTERNAL_BASE_URL`, then to the incoming request's origin. |
| `MCP_INTERNAL_KEY` | *(unset)* | Optional shared secret. When set, DreamFactory sends `X-Mcp-Internal-Key` to **both** daemons; set the same value on the daemons so they reject direct callers. |
| `MCP_INTERNAL_BASE_URL` | *(unset)* | Already used by the data daemon; also used here as the URL the system daemon calls back into DreamFactory with (e.g. `http://web`). |

Run `php artisan config:clear` after changing any of these.

### Running `df-system-mcp-server`

**On the DreamFactory host (default).** Install `dreamfactory/df-system-mcp-server` with
composer alongside this package, so it lands in `vendor/dreamfactory/df-system-mcp-server`.
Then start it with this package's launcher, the same way as the data daemon (Node 20+):

```
vendor/dreamfactory/df-mcp-server/scripts/start-system-daemon.sh          # Linux / Docker
vendor\dreamfactory\df-mcp-server\scripts\start-system-daemon-win.ps1     # Windows
```

The launcher installs the daemon's production dependencies on first run, listens on
`127.0.0.1:3700`, and calls DreamFactory back on `MCP_INTERNAL_BASE_URL` or
`http://127.0.0.1`. Because the daemon listens on loopback, it accepts DreamFactory's callback
URL without `MCP_INTERNAL_KEY`, like the data daemon. The launcher reads
`MCP_SYSTEM_DAEMON_HOST`, `MCP_SYSTEM_DAEMON_PORT` (keep `MCP_SYSTEM_DAEMON_URL` in step),
`DREAMFACTORY_URL` and `DF_SYSTEM_MCP_DIR`, and passes the daemon only its own settings, not
the rest of DreamFactory's environment. On a VM, run it from a systemd unit with
`Restart=on-failure`, next to the data daemon's unit. No `.env` change is needed.

**Sidecar container** (same Docker network as DreamFactory):

```
git clone https://github.com/dreamfactorysoftware/df-system-mcp-server && cd df-system-mcp-server
docker build -t df-system-mcp .
docker run -d --name df-system-mcp --network dreamfactory_default \
  -e DREAMFACTORY_URL=http://web/api/v2 \
  -e MCP_INTERNAL_KEY=<same value as DreamFactory> \
  -p 3700:3700 df-system-mcp
# or use the repo's docker-compose.example.yml
```

Then in DreamFactory's `.env` set `MCP_SYSTEM_DAEMON_URL=http://df-system-mcp:3700`,
`MCP_SYSTEM_DAEMON_BASE_URL=http://web` and the same `MCP_INTERNAL_KEY`, since the sidecar
listens on a network interface.

Either way, create a service of type **System API MCP Server**, e.g. `sysmcp`. Its MCP
endpoint is `https://<your-df>/mcp/sysmcp`.

### Security

Every tool call runs **as the OAuth'd (or session-authenticated) DreamFactory user**, under
that user's role. The daemon forwards the user's session token (and the service's API key)
to `/api/v2/system/*`; DreamFactory's RBAC decides what is allowed. Only administrators can
create/modify services, roles, apps and admins — a non-admin user connecting to a
`system_mcp` service can only do what their role permits. Use `disabled_tools` in the service
config to remove destructive tools (e.g. `delete_service`) entirely. When the daemon listens
on a network interface (a sidecar), set `MCP_INTERNAL_KEY` so nothing else on that network can
talk to it directly.

The daemon masks credentials in tool results. Its name rules can't know every service type, so
DreamFactory also sends it each installed type's secret config fields: the fields the type's
config model encrypts or protects, and fields its schema types as a password or certificate
(`username` and `account_name` stay readable). The list (`Support\SecretFieldManifest`) is
cached for an hour; after installing a package that adds service types, `php artisan cache:clear`
picks them up sooner.

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

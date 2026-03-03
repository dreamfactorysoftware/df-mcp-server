# Custom Tools

Custom tools allow administrators to define MCP tools from the admin UI that make HTTP requests to external REST APIs. Each tool maps to a single HTTP endpoint and becomes available to MCP clients alongside the built-in DreamFactory database and file tools.

## Overview

The feature extends the existing MCP tool infrastructure. Custom tools are:

- Defined per MCP service (each service has its own set of custom tools)
- Stored in the `mcp_custom_tools` database table
- Managed via the admin UI's service config page
- Registered in the MCP daemon alongside built-in tools
- Subject to the same `disabled_tools` filtering as built-in tools

## Data Model

### Table: `mcp_custom_tools`

| Column | Type | Description |
|--------|------|-------------|
| `id` | auto-increment PK | |
| `service_id` | unsigned int, FK | References `service.id`, cascade delete |
| `name` | string(100) | Tool identifier (e.g. `get_cat_facts`) |
| `description` | string(1000) | Shown to the LLM to explain what the tool does |
| `http_method` | string(10) | `GET`, `POST`, `PUT`, `PATCH`, or `DELETE` |
| `url` | string(2048) | Endpoint URL, supports `{param}` placeholders for path parameters |
| `parameters` | text, nullable | JSON array of parameter definitions (see below) |
| `headers` | text, nullable | JSON object of static headers sent with every request |
| `enabled` | boolean | Default `true`. Disabled tools are not registered in the daemon |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |
| `deleted_at` | timestamp, nullable | Soft delete |

Unique constraint on `(service_id, name)`.

### Parameter Definition

Each entry in the `parameters` JSON array:

```json
{
  "name": "query",
  "type": "string",
  "in": "query",
  "required": true,
  "description": "Search query"
}
```

| Field | Values | Description |
|-------|--------|-------------|
| `name` | any string | Parameter name, used as the key in the Zod schema and for substitution |
| `type` | `string`, `number`, `integer`, `boolean` | Maps to Zod types. `number` allows decimals, `integer` enforces whole numbers via `z.number().int()` |
| `in` | `query`, `path`, `body`, `header` | Where the parameter value is placed in the HTTP request |
| `required` | `true` / `false` | Required parameters use `z.string()`, optional use `z.string().optional()` |
| `description` | any string | Shown to the LLM to explain the parameter |

### Parameter Locations

- **`path`** -- Value is substituted into the URL template. `https://api.example.com/users/{userId}` with `userId=42` becomes `https://api.example.com/users/42`
- **`query`** -- Appended as URL query parameters. `?name=value`
- **`body`** -- Collected into a JSON request body. Multiple body params are merged into one JSON object. Ignored for `GET` requests.
- **`header`** -- Sent as dynamic HTTP headers. These are set per-call by the LLM, unlike static headers which are fixed.

### Static Headers

The `headers` field is a JSON object of key-value pairs sent with every invocation:

```json
{
  "Authorization": "Bearer sk-abc123",
  "X-Api-Key": "my-secret-key"
}
```

Use static headers for values the LLM should never see or control:
- API keys and auth tokens
- Content type overrides (`Accept: application/json`)
- Tenant/account identifiers (`X-Tenant-Id: acme-corp`)

Static headers are merged with any dynamic headers from parameters. Dynamic headers take precedence if there's a name conflict. If the request has a JSON body and no `Content-Type` is set, `application/json` is added automatically.

## Architecture

### Data Flow

```
[Admin UI] --CRUD--> [mcp_custom_tools table]
                           |
[McpServerConfig::getConfig()] includes custom_tools array
                           |
[McpDaemonClient] sends via X-Mcp-Config header
                           |
[server.ts] parses custom_tools, filters by enabled
                           |
[createServer()] --> registerCustomTools()
                           |
[custom-tools.service.ts] builds Zod schema + registers HTTP handler per tool
```

### Files

#### PHP (Laravel)

| File | Purpose |
|------|---------|
| `database/migrations/2026_02_25_000000_create_mcp_custom_tools_table.php` | Creates the `mcp_custom_tools` table |
| `src/Models/McpCustomTool.php` | Eloquent model with `getToolsForService()`, `getAllForService()`, `syncToolsForService()`, `toToolDefinition()` |
| `src/Models/McpServerConfig.php` | `getConfig()` includes all custom tools; `setConfig()` syncs them on save |

#### Daemon (TypeScript)

| File | Purpose |
|------|---------|
| `daemon/src/types.ts` | `CustomToolParameter` and `CustomToolDefinition` types |
| `daemon/src/services/custom-tools.service.ts` | `buildZodSchema()`, `executeCustomToolRequest()`, `registerCustomTools()` |
| `daemon/src/server.ts` | Parses `custom_tools` from config header, filters by `enabled` |
| `daemon/src/utils/utils.ts` | `createServer()` accepts and wires up custom tools |

#### Admin UI (Angular)

| File | Purpose |
|------|---------|
| `df-service-details.component.ts` | Custom tools state, form, CRUD methods, save integration |
| `df-service-details.component.html` | Custom Tools expansion panel with table and add/edit form |
| `df-service-details.component.scss` | Styles for custom tools section |

### Key Implementation Details

**Daemon: `buildZodSchema()`** converts parameter definitions to a Zod object schema. Type mapping:
- `string` --> `z.string()`
- `number` --> `z.number()`
- `integer` --> `z.number().int()`
- `boolean` --> `z.boolean()`

**Daemon: `executeCustomToolRequest()`** is a generic HTTP handler that:
1. Substitutes `{param}` placeholders in URL for path params
2. Builds query string for query params
3. Builds JSON body for body params
4. Sets dynamic headers for header params
5. Merges static headers from tool definition
6. Calls `fetch()` with a 1MB response size limit
7. Returns JSON (pretty-printed) or plain text via `respond()`/`respondError()`

**Daemon: `registerCustomTools()`** iterates tool definitions, builds the Zod schema for each, and registers via `createToolRegistrar()` -- the same registrar used by built-in tools, so `disabled_tools` filtering applies.

**PHP: `McpServerConfig::getConfig()`** wraps the custom tools query in try/catch so it returns `[]` gracefully if the migration hasn't been run yet.

**PHP: `McpCustomTool::syncToolsForService()`** handles save operations from the admin UI:
- Tools with an `id` that exists in the DB are updated
- Tools without an `id` are created
- Existing tools not present in the payload are soft-deleted

## Admin UI

The Custom Tools section appears on the MCP service edit page as an expansion panel below the MCP Tools panel. It is only visible when editing (not creating) an MCP service.

### Tools Table

Shows all custom tools with columns:
- **Enabled** -- slide toggle to enable/disable without deleting
- **Name** -- tool identifier shown as `code`
- **Method** -- HTTP method
- **URL** -- endpoint URL (truncated with ellipsis if long)
- **Description** -- tool description
- **Actions** -- edit (pen icon) and delete (trash icon) buttons

### Add/Edit Form

Collapsible inline form with fields:
- **Tool Name** -- validated: required, alphanumeric + underscores only
- **HTTP Method** -- dropdown: GET, POST, PUT, PATCH, DELETE
- **URL** -- text input, supports `{param}` placeholders
- **Description** -- textarea, shown to the LLM
- **Parameters** -- inline table with add/remove rows, each row has: name, type (dropdown), location (dropdown), required (checkbox), description
- **Static Headers** -- JSON textarea
- **Cancel / Add|Update** buttons

Edit and delete buttons are disabled while the form is open.

## Examples

### Get Random Cat Facts

```
Name:        get_cat_facts
Method:      GET
URL:         https://cat-fact.herokuapp.com/facts/random
Description: Get one or more random cat (or other animal) facts
```

Parameters:

| Name | Type | In | Required | Description |
|------|------|----|----------|-------------|
| `animal_type` | string | query | no | Animal type, e.g. "cat", "dog". Defaults to "cat" |
| `amount` | integer | query | no | Number of facts to return (max 500). Defaults to 1 |

Headers: `{}`

### Get Fact by ID

```
Name:        get_cat_fact_by_id
Method:      GET
URL:         https://cat-fact.herokuapp.com/facts/{factID}
Description: Get a specific cat fact by its unique ID
```

Parameters:

| Name | Type | In | Required | Description |
|------|------|----|----------|-------------|
| `factID` | string | path | yes | The unique ID of the fact |

Headers: `{}`

### Search GitHub Repositories

```
Name:        search_github_repos
Method:      GET
URL:         https://api.github.com/search/repositories
Description: Search GitHub repositories by keyword
```

Parameters:

| Name | Type | In | Required | Description |
|------|------|----|----------|-------------|
| `q` | string | query | yes | Search query (e.g. "language:python stars:>1000") |
| `sort` | string | query | no | Sort by: stars, forks, or updated |
| `per_page` | integer | query | no | Results per page (max 100) |

Headers:
```json
{
  "Accept": "application/vnd.github+json"
}
```

### Send Slack Message

```
Name:        send_slack_message
Method:      POST
URL:         https://slack.com/api/chat.postMessage
Description: Send a message to a Slack channel
```

Parameters:

| Name | Type | In | Required | Description |
|------|------|----|----------|-------------|
| `channel` | string | body | yes | Channel ID to post to |
| `text` | string | body | yes | Message text |

Headers:
```json
{
  "Authorization": "Bearer xoxb-your-slack-token"
}
```

### Create Jira Issue

```
Name:        create_jira_issue
Method:      POST
URL:         https://yourcompany.atlassian.net/rest/api/3/issue
Description: Create a new Jira issue
```

Parameters:

| Name | Type | In | Required | Description |
|------|------|----|----------|-------------|
| `project_key` | string | body | yes | Project key (e.g. "ENG") |
| `summary` | string | body | yes | Issue title |
| `description` | string | body | no | Issue description |
| `issue_type` | string | body | yes | Type: Bug, Task, Story, etc. |

Headers:
```json
{
  "Authorization": "Basic base64-encoded-email:api-token",
  "Accept": "application/json"
}
```

> **Note:** The Jira example requires wrapping the body params into Jira's nested JSON structure. For APIs with complex nested request bodies, a single `body` parameter containing pre-formatted JSON may be more practical than multiple flat body params.

### Geocode an Address

```
Name:        geocode_address
Method:      GET
URL:         https://api.mapbox.com/geocoding/v5/mapbox.places/{address}.json
Description: Convert an address to geographic coordinates (latitude/longitude)
```

Parameters:

| Name | Type | In | Required | Description |
|------|------|----|----------|-------------|
| `address` | string | path | yes | Address or place name to geocode |
| `limit` | integer | query | no | Max results (1-10) |

Headers: `{}`

Query string auth (append to URL): `?access_token=pk.your-mapbox-token`

> **Tip:** For APIs that use query-string authentication, you can either bake the token directly into the URL or define it as a required query parameter. Baking it into the URL keeps it hidden from the LLM.

import { Request } from 'express';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { SessionService } from '../services/session.service.js';
import { registerDreamFactoryTools } from '../services/tools.service.js';
import packageJson from '../../package.json' with { type: 'json' };

export function getSessionId(req: Request): string | undefined {
  const header = req.headers['mcp-session-id'];
  if (!header) {
    return undefined;
  }
  return Array.isArray(header) ? header[0] : header;
}

export function updateSessionConfigFromHeaders(
  req: Request,
  sessionManager: SessionService,
  sessionId?: string
) {
  if (!sessionId) {
    return;
  }

  const baseUrl = req.header('X-Mcp-Base-Url');
  const sessionToken = req.header('X-DreamFactory-Session-Token');
  const apiKey = req.header('X-DreamFactory-API-Key');
  if (!baseUrl || !sessionToken) {
    return;
  }

  sessionManager.setConfig(sessionId, { url: baseUrl, sessionToken, apiKey });
}

export function parseConfigFromHeaders(req: Request) {
  const configHeader = req.header('X-Mcp-Config');
  const baseUrl = req.header('X-Mcp-Base-Url');

  if (!configHeader) {
    throw new Error('X-Mcp-Config header required for initialization requests');
  }
  if (!baseUrl) {
    throw new Error('X-Mcp-Base-Url header required for initialization requests');
  }

  const configObject = resolveConfig(JSON.parse(configHeader));
  const apiName = extractApiName(configObject);

  if (!apiName) {
    throw new Error('api_name is required for MCP service');
  }

  return {
    apiName,
    baseUrl
  };
}

function resolveConfig(payload: unknown): Record<string, unknown> {
  if (!isRecord(payload)) {
    throw new Error('X-Mcp-Config header must be a JSON object');
  }

  const nested = payload.config;
  if (isRecord(nested)) {
    return nested;
  }

  return payload;
}

function extractApiName(config: Record<string, unknown>): string | undefined {
  return extractString(config?.api_name, config?.apiName);
}

function extractString(...values: Array<unknown>): string | undefined {
  for (const value of values) {
    if (typeof value === 'string') {
      const trimmed = value.trim();
      if (trimmed.length > 0) {
        return trimmed;
      }
    }
  }
  return undefined;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

export function createServer(
  serviceName: string,
  baseUrl: string,
  sessionManager: SessionService
): McpServer {
  const instructions = [
    `You are connected to the DreamFactory database service "${serviceName}".`,
    `Base URL: ${baseUrl}`,
    '',
    '## Getting Started',
    'IMPORTANT: Call `get_data_model` FIRST before making any data queries.',
    'The data model provides in a single ~10-20KB response:',
    '- Every table with all columns (name, type, primary key, foreign keys)',
    '- Foreign key references showing how tables connect',
    '- Structural patterns you MUST handle correctly:',
    '  * HIERARCHIES: Self-referencing FKs (e.g. dept.parent_dept_id → dept.dept_id) mean',
    '    records form a TREE. You must use recursive traversal to find ALL descendants,',
    '    not just direct children. Aggregate metrics must roll up through ALL levels.',
    '  * JUNCTION TABLES: Many-to-many relationships via intermediate tables.',
    '- Row counts per table',
    '',
    '## Tool Usage Guide',
    '1. `get_data_model` - START HERE. Condensed schema with columns, FKs, and patterns.',
    '2. `get_api_spec` - OpenAPI spec with query syntax hints. Use compact=true (default).',
    '3. `get_table_data` - Query data with filter, order, limit, offset, fields, related',
    '4. `get_table_schema` - Full schema for a single table (if you need more detail)',
    '5. `create_records` / `update_records` / `delete_records` - CRUD operations',
    '6. `get_stored_procedures` / `call_stored_procedure` - Stored procedure access',
    '7. `get_stored_functions` / `call_stored_function` - Stored function access',
    '',
    '## Query Syntax Quick Reference',
    '- Filter: `field=value`, `field>10`, `field LIKE %text%`, `field IN (1,2,3)`, `field BETWEEN 1 AND 10`, `field IS NULL`',
    '- Combine filters: `(field1=value1) AND (field2>value2)`, `(f1=v1) OR (f2=v2)`',
    '- Order: `field ASC`, `field DESC`, `field1 ASC, field2 DESC`',
    '- Fields: select specific columns to reduce response size',
    '- Related: include related records via foreign keys (e.g., `related=parent_table_by_fk_field`)',
    '- Pagination: use `limit` and `offset`, set `includeCount=true` for total count',
    '',
    '## Key Data Modeling Hints',
    '- When a table has a column referencing ITSELF (self-referencing FK), it forms a tree/hierarchy.',
    '  Example: dept.parent_dept_id → dept.dept_id means departments are nested.',
    '  You MUST recursively traverse to aggregate child data into parent totals.',
    '- When you see amount + paid_amount columns, compute outstanding = amount - paid_amount.',
    '  An invoice with status "paid" but paid_amount < amount still has an outstanding balance.',
    '',
    'All tools operate against the DreamFactory REST API using the authenticated user session.'
  ].join('\n');

  const server = new McpServer(
    {
      name: `DreamFactory MCP (${serviceName})`,
      version: (packageJson as { version?: string })?.version ?? 'dev'
    },
    {
      instructions
    }
  );

  registerDreamFactoryTools(server, sessionManager);
  return server;
}

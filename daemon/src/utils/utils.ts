import { Request } from 'express';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { SessionService } from '../services/session.service.js';
import { registerDreamFactoryTools } from '../services/tools.service.js';
import { registerCustomTools } from '../services/custom-tools.service.js';
import { registerGlobalTools } from '../services/global-tools.service.js';
import { createLazyState, installLazyFacade, LAZY_INSTRUCTIONS, type LazyMode } from '../services/lazy.service.js';
import { installArgNormalizer } from '../services/args.js';
import { DreamFactoryService, type DFAuthConfig } from '../services/dreamfactory.service.js';
import packageJson from '../../package.json' with { type: 'json' };
import type { ToolStyle, ApiConfig, CustomToolDefinition } from '../types.js';

export type { ApiConfig };

export function getSessionId(req: Request): string | undefined {
  const header = req.headers['mcp-session-id'];
  if (!header) {
    return undefined;
  }
  return Array.isArray(header) ? header[0] : header;
}

/**
 * Extract and normalize a header value.
 * Returns undefined for missing, empty, or whitespace-only values.
 */
function normalizeHeader(value: string | undefined): string | undefined {
  if (!value) return undefined;
  const trimmed = value.trim();
  return trimmed.length > 0 ? trimmed : undefined;
}

/**
 * Update session configuration from request headers.
 *
 * Requires the base URL and at least one auth credential (session token or
 * API key). The stored credentials are REPLACED with exactly what this
 * request carried: the PHP proxy authenticates every request and forwards the
 * complete credential set for that request's auth mode, so keeping an old
 * session token alive after a request that no longer presents one would let a
 * stale identity outlive its authentication. The apiConfigs discovered at
 * session init are preserved.
 *
 * @returns true if config was updated, false if validation failed
 */
export function updateSessionConfigFromHeaders(
  req: Request,
  sessionManager: SessionService,
  sessionId?: string
): boolean {
  if (!sessionId) {
    return false;
  }

  const baseUrl = normalizeHeader(req.header('X-Mcp-Base-Url'));
  const sessionToken = normalizeHeader(req.header('X-DreamFactory-Session-Token'));
  const apiKey = normalizeHeader(req.header('X-DreamFactory-API-Key'));

  if (!baseUrl) {
    return false;
  }

  // At least one auth method required (API-key-only is valid)
  if (!sessionToken && !apiKey) {
    return false;
  }

  const existingConfig = sessionManager.getConfig(sessionId);
  sessionManager.setConfig(sessionId, {
    url: baseUrl,
    sessionToken,
    apiKey,
    apiConfigs: existingConfig?.apiConfigs
  });
  return true;
}

export function parseConfigFromHeaders(req: Request) {
  const baseUrl = req.header('X-Mcp-Base-Url');

  if (!baseUrl) {
    throw new Error('X-Mcp-Base-Url header required for initialization requests');
  }

  return {
    baseUrl
  };
}

/**
 * Parse pre-resolved services from the X-Mcp-Available-Services header.
 * The PHP layer resolves these via ServiceManager (bypasses RBAC), so the
 * daemon never needs GET system/service permission.
 */
/**
 * Parse a pre-resolved services array (from the request body envelope).
 */
function parseAvailableServicesList(services: unknown[], rootUrl: string): ApiConfig[] | null {
  try {
    const typed = services as Array<{
      name: string;
      label?: string;
      type: string;
      category?: string;
    }>;

    // Empty is a real catalog (scoped connection with no backends), not "missing".
    return typed.map(s => ({
      name: s.name,
      baseUrl: `${rootUrl}/${s.name}`,
      category: (s.category ?? 'database') as 'database' | 'file',
      type: s.type,
      label: s.label,
    }));
  } catch (e) {
    console.warn('[parseAvailableServicesList] Failed to parse services:', e);
    return null;
  }
}

/**
 * Parse pre-resolved services from the X-Mcp-Available-Services header (legacy).
 */
function parseAvailableServicesHeader(req: Request, rootUrl: string): ApiConfig[] | null {
  const header = req.header('X-Mcp-Available-Services');
  if (!header) {
    return null;
  }

  try {
    const services = JSON.parse(header) as Array<{
      name: string;
      label?: string;
      type: string;
      category?: string;
    }>;

    if (!Array.isArray(services)) {
      return null;
    }

    // Empty array is authoritative — PHP scoped the catalog to nothing.
    return services.map(s => ({
      name: s.name,
      baseUrl: `${rootUrl}/${s.name}`,
      category: (s.category ?? 'database') as 'database' | 'file',
      type: s.type,
      label: s.label,
    }));
  } catch (e) {
    console.warn('[parseAvailableServicesHeader] Failed to parse header:', e);
    return null;
  }
}

/**
 * Discover all supported services from DreamFactory and build API configs.
 *
 * Prefers pre-resolved services from the PHP layer (body envelope or
 * X-Mcp-Available-Services header) to avoid requiring system/service GET
 * permission. Falls back to the DreamFactory API only if neither is present.
 */
export async function discoverServices(
  rootUrl: string,
  auth: DFAuthConfig,
  req?: Request,
  availableServicesFromBody?: unknown[]
): Promise<ApiConfig[]> {
  // Prefer pre-resolved services from body envelope (new path).
  // An empty array is authoritative: PHP scoped the catalog to no backends.
  // Falling through would rediscover every DB/file service and undo scoping.
  if (Array.isArray(availableServicesFromBody)) {
    const parsed = parseAvailableServicesList(availableServicesFromBody, rootUrl);
    if (parsed !== null) {
      console.log('[discoverServices] Using pre-resolved services from body:', parsed.map(s => s.name));
      return parsed;
    }
  }

  // Fallback: pre-resolved services from header (legacy path), including []
  if (req) {
    const preResolved = parseAvailableServicesHeader(req, rootUrl);
    if (preResolved !== null) {
      console.log('[discoverServices] Using pre-resolved services from header:', preResolved.map(s => s.name));
      return preResolved;
    }
  }

  // Fallback: call the API directly (requires system/service GET permission)
  console.log('[discoverServices] No pre-resolved services, falling back to API discovery...');
  console.log('[discoverServices] rootUrl:', rootUrl);

  try {
    // Discover database services
    const dbServices = await DreamFactoryService.getDatabaseServices(rootUrl, auth);
    const dbConfigs: ApiConfig[] = dbServices.map(service => ({
      name: service.name,
      baseUrl: `${rootUrl}/${service.name}`,
      category: 'database',
      type: service.type
    }));
    console.log('[discoverServices] Database services:', dbConfigs.map(c => c.name));

    // Discover file services
    const fileServices = await DreamFactoryService.getFileServices(rootUrl, auth);
    const fileConfigs: ApiConfig[] = fileServices.map(service => ({
      name: service.name,
      baseUrl: `${rootUrl}/${service.name}`,
      category: 'file',
      type: service.type
    }));
    console.log('[discoverServices] File services:', fileConfigs.map(c => c.name));

    const allConfigs = [...dbConfigs, ...fileConfigs];
    console.log('[discoverServices] Total services discovered:', allConfigs.length);

    return allConfigs;
  } catch (error) {
    console.error('[discoverServices] Error during discovery:', error);
    throw error;
  }
}

export function createServer(
  serviceName: string,
  apiConfigs: ApiConfig[],
  sessionManager: SessionService,
  disabledTools?: Set<string>,
  customTools?: CustomToolDefinition[],
  lazyMode: LazyMode = 'auto',
  toolStyle: ToolStyle = 'prefixed'
): McpServer {
  const dbApis = apiConfigs.filter(c => c.category === 'database').map(c => c.name);
  const fileApis = apiConfigs.filter(c => c.category === 'file').map(c => c.name);

  const dbPrefixes = dbApis.map(name => name.replace(/[^a-zA-Z0-9]/g, '_'));
  const examplePrefix = dbPrefixes[0] ?? 'db';
  // Merged mode drops the per-service prefix, so the tool-usage guide below
  // must describe bare verbs instead or the model hunts for tools that do not exist.
  const merged = toolStyle === 'merged';
  const multiDb = dbApis.length > 1;
  const svcArg = merged && multiDb ? "service='<name>'" : '';

  const instructions = [
    `You are connected to the DreamFactory service "${serviceName}".`,
    dbApis.length > 0 ? `Available database APIs: ${dbApis.join(', ')}` : '',
    fileApis.length > 0 ? `Available file storage APIs: ${fileApis.join(', ')}` : '',
    '',
    '## Getting Started',
    `IMPORTANT: Call \`${examplePrefix}_get_data_model\` FIRST before making any data queries.`,
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
    'Argument names are snake_case (table_name, count_only, include_count); camelCase is accepted too. Unknown arguments are rejected, never ignored.',
    merged
      ? (multiDb
          ? `Database tools are shared across every API. Pass ${svcArg} to choose which database a call targets (one of: ${dbApis.join(', ')}).`
          : `Database tools act on the "${dbApis[0] ?? 'database'}" API directly — no service argument is needed.`)
      : 'All database tools are prefixed with the API name (e.g., db_get_tables, mysql_get_table_data).',
    merged
      ? 'Every verb takes the same parameters regardless of which database you target.'
      : 'The same verb on every database shares the same parameters — only the prefix changes.',
    dbApis.length > 1 ? 'Use the list_apis tool to see all available APIs.' : '',
    '',
    `1. \`${merged ? '' : examplePrefix + '_'}get_data_model\` - START HERE. Condensed schema with columns, FKs, and patterns.`,
    `2. \`${merged ? '' : examplePrefix + '_'}get_api_spec\` - OpenAPI spec with query syntax hints. Use compact=true (default).`,
    `3. \`${merged ? '' : examplePrefix + '_'}get_table_data\` - Query data with filter, order, limit, offset, fields, related`,
    `4. \`${merged ? '' : examplePrefix + '_'}aggregate_data\` - Compute SUM/COUNT/AVG/MIN/MAX in ONE call (no manual pagination needed)`,
    `5. \`${merged ? '' : examplePrefix + '_'}get_table_schema\` - Full schema for a single table (if you need more detail)`,
    `6. \`${merged ? '' : examplePrefix + '_'}create_records\` / \`update_records\` / \`delete_records\` - CRUD operations`,
    `7. \`${merged ? '' : examplePrefix + '_'}get_stored_procedures\` / \`call_stored_procedure\` - Stored procedure access`,
    `8. \`${merged ? '' : examplePrefix + '_'}get_stored_functions\` / \`call_stored_function\` - Stored function access`,
    '',
    fileApis.length > 0 ? 'File tools: list_files, get_file_content, create_folder, delete_file (also prefixed per file API).\n' : '',
    '## Query Syntax Quick Reference',
    '- Filter: `field=value`, `field>10`, `field LIKE %text%`, `field IN (1,2,3)`, `field BETWEEN 1 AND 10`, `field IS NULL`',
    '- IMPORTANT: Use field names exactly as they appear in the schema — do NOT add quotes, brackets, backticks, or URL-encoding around field names. Spaces in field names are valid as-is. Example: `Production Day=2026-03-16` (NOT `[Production Day]`, NOT `"Production Day"`, NOT `Production%20Day`)',
    '- Combine filters: `(field1=value1) AND (field2>value2)`, `(f1=v1) OR (f2=v2)`',
    '- Order: `field ASC`, `field DESC`, `field1 ASC, field2 DESC`',
    '- Fields: select specific columns to reduce response size',
    '- Related: include related records via foreign keys (e.g., `related=parent_table_by_fk_field`)',
    '- Pagination: use `limit` and `offset`, set `include_count=true` for total count',
    '- Counting: use `count_only=true` to get just the count without data',
    `- Aggregation: use \`${merged ? '' : examplePrefix + '_'}aggregate_data\` for SUM/COUNT/AVG/MIN/MAX — it pushes computation to the database server`,
    '- Max page size: 1000 records. Always paginate for tables with more rows.',
    '',
    '## Key Data Modeling Hints',
    '- When a table has a column referencing ITSELF (self-referencing FK), it forms a tree/hierarchy.',
    '  Example: dept.parent_dept_id → dept.dept_id means departments are nested.',
    '  You MUST recursively traverse to aggregate child data into parent totals.',
    '- When you see amount + paid_amount columns, compute outstanding = amount - paid_amount.',
    '  An invoice with status "paid" but paid_amount < amount still has an outstanding balance.',
    '',
    'All tools operate against the DreamFactory REST API using the authenticated user session.',
    ...(customTools && customTools.length > 0
      ? (() => {
          const hasApi = customTools.some(t => t.tool_type !== 'function');
          const hasFunction = customTools.some(t => t.tool_type === 'function');
          const typeDesc = hasApi && hasFunction
            ? 'These tools make HTTP requests to external APIs or execute server-side functions.'
            : hasFunction
              ? 'These tools execute server-side functions.'
              : 'These tools make HTTP requests to external APIs.';
          return [
            '',
            '## Custom Tools',
            `The following custom tools are available: ${customTools.map(t => t.name).join(', ')}.`,
            `${typeDesc} Use them as described in their tool descriptions.`
          ];
        })()
      : []),
    ...(lazyMode !== 'off' ? [LAZY_INSTRUCTIONS.replaceAll('{prefix}', examplePrefix)] : [])
  ].filter(Boolean).join('\n');

  const server = new McpServer(
    {
      name: `DreamFactory MCP (${serviceName})`,
      version: (packageJson as { version?: string })?.version ?? 'dev'
    },
    {
      instructions
    }
  );

  // Must exist before any registerTool call so the registrar can catalog tools.
  const lazy = lazyMode !== 'off' ? createLazyState(server, serviceName, lazyMode) : undefined;

  // Agent identity/access tools — always available, not service-prefixed.
  registerGlobalTools(server, sessionManager, disabledTools);

  registerDreamFactoryTools(server, sessionManager, apiConfigs, disabledTools, toolStyle);

  if (customTools && customTools.length > 0) {
    registerCustomTools(server, customTools, sessionManager, disabledTools);
  }

  if (lazy) {
    installLazyFacade(server, lazy);
  }

  // After every tool is registered: snake_case/camelCase aliases and unknown-key errors (issue #66).
  installArgNormalizer(server);

  return server;
}

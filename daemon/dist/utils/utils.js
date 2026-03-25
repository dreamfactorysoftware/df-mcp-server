import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { registerDreamFactoryTools } from '../services/tools.service.js';
import { registerCustomTools } from '../services/custom-tools.service.js';
import { DreamFactoryService } from '../services/dreamfactory.service.js';
import packageJson from '../../package.json' with { type: 'json' };
export function getSessionId(req) {
    const header = req.headers['mcp-session-id'];
    if (!header) {
        return undefined;
    }
    return Array.isArray(header) ? header[0] : header;
}
export function updateSessionConfigFromHeaders(req, sessionManager, sessionId) {
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
export function parseConfigFromHeaders(req) {
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
function parseAvailableServicesList(services, rootUrl) {
    try {
        const typed = services;
        if (typed.length === 0) {
            return null;
        }
        return typed.map(s => ({
            name: s.name,
            baseUrl: `${rootUrl}/${s.name}`,
            category: (s.category ?? 'database'),
            type: s.type,
        }));
    }
    catch (e) {
        console.warn('[parseAvailableServicesList] Failed to parse services:', e);
        return null;
    }
}
/**
 * Parse pre-resolved services from the X-Mcp-Available-Services header (legacy).
 */
function parseAvailableServicesHeader(req, rootUrl) {
    const header = req.header('X-Mcp-Available-Services');
    if (!header) {
        return null;
    }
    try {
        const services = JSON.parse(header);
        if (!Array.isArray(services) || services.length === 0) {
            return null;
        }
        return services.map(s => ({
            name: s.name,
            baseUrl: `${rootUrl}/${s.name}`,
            category: (s.category ?? 'database'),
            type: s.type,
        }));
    }
    catch (e) {
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
export async function discoverServices(rootUrl, auth, req, availableServicesFromBody) {
    // Prefer pre-resolved services from body envelope (new path)
    if (availableServicesFromBody && availableServicesFromBody.length > 0) {
        const parsed = parseAvailableServicesList(availableServicesFromBody, rootUrl);
        if (parsed && parsed.length > 0) {
            console.log('[discoverServices] Using pre-resolved services from body:', parsed.map(s => s.name));
            return parsed;
        }
    }
    // Fallback: pre-resolved services from header (legacy path)
    if (req) {
        const preResolved = parseAvailableServicesHeader(req, rootUrl);
        if (preResolved && preResolved.length > 0) {
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
        const dbConfigs = dbServices.map(service => ({
            name: service.name,
            baseUrl: `${rootUrl}/${service.name}`,
            category: 'database',
            type: service.type
        }));
        console.log('[discoverServices] Database services:', dbConfigs.map(c => c.name));
        // Discover file services
        const fileServices = await DreamFactoryService.getFileServices(rootUrl, auth);
        const fileConfigs = fileServices.map(service => ({
            name: service.name,
            baseUrl: `${rootUrl}/${service.name}`,
            category: 'file',
            type: service.type
        }));
        console.log('[discoverServices] File services:', fileConfigs.map(c => c.name));
        const allConfigs = [...dbConfigs, ...fileConfigs];
        console.log('[discoverServices] Total services discovered:', allConfigs.length);
        return allConfigs;
    }
    catch (error) {
        console.error('[discoverServices] Error during discovery:', error);
        throw error;
    }
}
export function createServer(serviceName, apiConfigs, sessionManager, disabledTools, customTools) {
    const dbApis = apiConfigs.filter(c => c.category === 'database').map(c => c.name);
    const fileApis = apiConfigs.filter(c => c.category === 'file').map(c => c.name);
    const dbPrefixes = dbApis.map(name => name.replace(/[^a-zA-Z0-9]/g, '_'));
    const examplePrefix = dbPrefixes[0] ?? 'db';
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
        'All database tools are prefixed with the API name (e.g., db_get_tables, mysql_get_table_data).',
        'Use the list_apis tool to see all available APIs.',
        '',
        `1. \`{prefix}_get_data_model\` - START HERE. Condensed schema with columns, FKs, and patterns.`,
        `2. \`{prefix}_get_api_spec\` - OpenAPI spec with query syntax hints. Use compact=true (default).`,
        `3. \`{prefix}_get_table_data\` - Query data with filter, order, limit, offset, fields, related`,
        `4. \`{prefix}_aggregate_data\` - Compute SUM/COUNT/AVG/MIN/MAX in ONE call (no manual pagination needed)`,
        `5. \`{prefix}_get_table_schema\` - Full schema for a single table (if you need more detail)`,
        `6. \`{prefix}_create_records\` / \`update_records\` / \`delete_records\` - CRUD operations`,
        `7. \`{prefix}_get_stored_procedures\` / \`call_stored_procedure\` - Stored procedure access`,
        `8. \`{prefix}_get_stored_functions\` / \`call_stored_function\` - Stored function access`,
        '',
        fileApis.length > 0 ? 'File tools: list_files, get_file_content, create_folder, delete_file (also prefixed per file API).\n' : '',
        '## Query Syntax Quick Reference',
        '- Filter: `field=value`, `field>10`, `field LIKE %text%`, `field IN (1,2,3)`, `field BETWEEN 1 AND 10`, `field IS NULL`',
        '- Combine filters: `(field1=value1) AND (field2>value2)`, `(f1=v1) OR (f2=v2)`',
        '- Order: `field ASC`, `field DESC`, `field1 ASC, field2 DESC`',
        '- Fields: select specific columns to reduce response size',
        '- Related: include related records via foreign keys (e.g., `related=parent_table_by_fk_field`)',
        '- Pagination: use `limit` and `offset`, set `includeCount=true` for total count',
        '- Counting: use `countOnly=true` to get just the count without data',
        '- Aggregation: use `{prefix}_aggregate_data` for SUM/COUNT/AVG/MIN/MAX — it pushes computation to the database server',
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
            : [])
    ].filter(Boolean).join('\n');
    const server = new McpServer({
        name: `DreamFactory MCP (${serviceName})`,
        version: packageJson?.version ?? 'dev'
    }, {
        instructions
    });
    registerDreamFactoryTools(server, sessionManager, apiConfigs, disabledTools);
    if (customTools && customTools.length > 0) {
        registerCustomTools(server, customTools, sessionManager, disabledTools);
    }
    return server;
}

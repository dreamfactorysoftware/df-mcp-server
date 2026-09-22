import * as z from 'zod/v4';
import { DreamFactoryService } from './dreamfactory.service.js';
import { registerApiConnectorTools } from './api-connector.tools.js';
import { registerFileApiTools } from './file-api.tools.js';
import { respond, sanitizeApiName, getAuth, createToolRegistrar, WRITE_VERBS } from './tool-utils.js';
import { serviceNameMap } from './args.js';
/**
 * Base tool definitions that will be registered for each API.
 * The handler receives the API config and auth, so it knows which API to target.
 *
 * Descriptions stay short on purpose: each verb is emitted once per database,
 * so a long essay here is multiplied by service count in tools/list. Full
 * query syntax and "call get_data_model first" guidance live in the server
 * instructions (once per session). The Zod `schema` object is shared by
 * reference across prefixes — only the JSON serialization is per-tool.
 */
const BASE_TOOLS = [
    {
        name: 'get_api_spec',
        title: 'Get API Spec',
        description: 'OpenAPI 3.0 spec for this database (endpoints, query syntax, tables). Prefer get_data_model for a condensed schema. compact=true (default).',
        schema: z.object({
            compact: z.boolean().optional().describe('Return compact token-efficient format (default: true)'),
            resource_name: z.string().optional().describe('Get spec for a specific table/resource only'),
            tables: z.boolean().optional().describe('Include full table and field details'),
            refresh: z.boolean().optional().describe('Force refresh cached spec data')
        }),
        handler: async (args, _context, apiConfig, auth) => {
            const options = { compact: true, ...args };
            const data = await DreamFactoryService.getApiSpec(apiConfig.baseUrl, auth, options);
            return respond(data);
        }
    },
    {
        name: 'get_data_model',
        title: 'Get Data Model',
        description: 'BEST FIRST CALL. Condensed model of every table, columns (name/type/FK), row counts, hierarchies, and junction tables. Use this to plan queries before get_table_data.',
        schema: z.object({
            refresh: z.boolean().optional().describe('Force refresh cached data')
        }),
        handler: async (args, _context, apiConfig, auth) => {
            const data = await DreamFactoryService.getApiSpec(apiConfig.baseUrl, auth, {
                model: true,
                refresh: args?.refresh
            });
            return respond(data);
        }
    },
    {
        name: 'get_tables',
        title: 'List Tables',
        description: 'List tables in this database. Prefer get_data_model for columns and relationships.',
        schema: z.object({}),
        handler: async (_args, _context, apiConfig, auth) => {
            const data = await DreamFactoryService.getTables(apiConfig.baseUrl, auth);
            return respond(data);
        }
    },
    {
        name: 'get_table_schema',
        title: 'Get Table Schema',
        description: 'Full schema for one table.',
        schema: z.object({ table_name: z.string() }),
        handler: async ({ table_name }, _context, apiConfig, auth) => {
            const data = await DreamFactoryService.getTableSchema(table_name, apiConfig.baseUrl, auth);
            return respond(data);
        }
    },
    {
        name: 'get_table_data',
        title: 'Get Table Data',
        description: 'Read rows with filter/order/limit/offset. Max 1000 rows per call; paginate for more. count_only=true to count. For SUM/COUNT/AVG/MIN/MAX/GROUP BY use aggregate_data — this tool cannot aggregate.',
        schema: z.object({
            table_name: z.string(),
            fields: z.array(z.string()).optional(),
            filter: z.string().optional(),
            offset: z.number().optional(),
            limit: z.number().optional().describe('Max records per request (server max: 1000). Use with offset to paginate.'),
            order: z.string().optional(),
            continue: z.boolean().optional(),
            related: z.string().optional().describe('Include related records via FK (e.g. "parent_table_by_fk_field"). Check relationships in the data model.'),
            count_only: z.boolean().optional().describe('Return only the record count, no data. Use this instead of COUNT() in fields.'),
            include_count: z.boolean().optional().describe('Include total record count in response metadata alongside data.'),
            include_schema: z.boolean().optional(),
            ids: z.array(z.string()).optional()
        }),
        handler: async (args, _context, apiConfig, auth) => {
            const data = await DreamFactoryService.getTableData(apiConfig.baseUrl, auth, args);
            return respond(data);
        }
    },
    {
        name: 'create_records',
        title: 'Create Records',
        description: 'Insert one or more rows.',
        schema: z.object({
            table_name: z.string(),
            records: z.array(z.record(z.string(), z.unknown())),
            fields: z.array(z.string()).optional(),
            related: z.string().optional(),
            continue: z.boolean().optional(),
            rollback: z.boolean().optional()
        }),
        handler: async ({ table_name, records, ...options }, _context, apiConfig, auth) => {
            const data = await DreamFactoryService.createRecords(table_name, apiConfig.baseUrl, auth, records, options);
            return respond(data);
        }
    },
    {
        name: 'update_records',
        title: 'Update Records',
        description: 'Patch rows by ids or filter.',
        schema: z.object({
            table_name: z.string(),
            records: z.array(z.record(z.string(), z.unknown())),
            fields: z.array(z.string()).optional(),
            related: z.string().optional(),
            ids: z.array(z.string()).optional(),
            filter: z.string().optional(),
            continue: z.boolean().optional(),
            rollback: z.boolean().optional()
        }),
        handler: async ({ table_name, records, ...options }, _context, apiConfig, auth) => {
            const data = await DreamFactoryService.updateRecords(table_name, apiConfig.baseUrl, auth, records, options);
            return respond(data);
        }
    },
    {
        name: 'delete_records',
        title: 'Delete Records',
        description: 'Delete rows by ids or filter.',
        schema: z.object({
            table_name: z.string(),
            ids: z.array(z.string()).optional(),
            filter: z.string().optional(),
            force: z.boolean().optional(),
            fields: z.array(z.string()).optional(),
            related: z.string().optional(),
            continue: z.boolean().optional(),
            rollback: z.boolean().optional()
        }),
        handler: async ({ table_name, ...options }, _context, apiConfig, auth) => {
            const data = await DreamFactoryService.deleteRecords(table_name, apiConfig.baseUrl, auth, options);
            return respond(data);
        }
    },
    {
        name: 'get_table_fields',
        title: 'Get Table Fields',
        description: 'Field definitions for one table.',
        schema: z.object({
            table_name: z.string(),
            refresh: z.boolean().optional()
        }),
        handler: async ({ table_name, refresh }, _context, apiConfig, auth) => {
            const data = await DreamFactoryService.getTableFields(table_name, apiConfig.baseUrl, auth, refresh);
            return respond(data);
        }
    },
    {
        name: 'get_table_relationships',
        title: 'Get Table Relationships',
        description: 'Foreign keys, self-referencing hierarchies, and junction tables for one table.',
        schema: z.object({
            table_name: z.string(),
            refresh: z.boolean().optional()
        }),
        handler: async ({ table_name, refresh }, _context, apiConfig, auth) => {
            const data = await DreamFactoryService.getTableRelationships(table_name, apiConfig.baseUrl, auth, refresh);
            return respond(data);
        }
    },
    {
        name: 'get_stored_procedures',
        title: 'List Stored Procedures',
        description: 'List stored procedures.',
        schema: z.object({}),
        handler: async (_args, _context, apiConfig, auth) => {
            const data = await DreamFactoryService.getStoredProcedures(apiConfig.baseUrl, auth);
            return respond(data);
        }
    },
    {
        name: 'call_stored_procedure',
        title: 'Call Stored Procedure',
        description: 'Execute a stored procedure.',
        schema: z.object({
            procedure_name: z.string(),
            parameters: z.record(z.string(), z.unknown()).optional(),
            wrapper: z.string().optional(),
            returns: z.string().optional()
        }),
        handler: async ({ procedure_name, parameters, wrapper, returns }, _context, apiConfig, auth) => {
            const data = await DreamFactoryService.callStoredProcedure(procedure_name, apiConfig.baseUrl, auth, parameters, wrapper, returns);
            return respond(data);
        }
    },
    {
        name: 'get_stored_functions',
        title: 'List Stored Functions',
        description: 'List stored functions.',
        schema: z.object({}),
        handler: async (_args, _context, apiConfig, auth) => {
            const data = await DreamFactoryService.getStoredFunctions(apiConfig.baseUrl, auth);
            return respond(data);
        }
    },
    {
        name: 'call_stored_function',
        title: 'Call Stored Function',
        description: 'Execute a stored function.',
        schema: z.object({
            function_name: z.string(),
            parameters: z.record(z.string(), z.unknown()).optional(),
            returns: z.string().optional()
        }),
        handler: async ({ function_name, parameters, returns }, _context, apiConfig, auth) => {
            const data = await DreamFactoryService.callStoredFunction(function_name, apiConfig.baseUrl, auth, parameters, returns);
            return respond(data);
        }
    },
    {
        name: 'get_database_resources',
        title: 'List Database Resources',
        description: 'List resources this database service exposes.',
        schema: z.object({
            as_list: z.boolean().optional(),
            as_access_list: z.boolean().optional(),
            include_access: z.boolean().optional(),
            fields: z.array(z.string()).optional(),
            refresh: z.boolean().optional()
        }),
        handler: async (args, _context, apiConfig, auth) => {
            const data = await DreamFactoryService.getDatabaseResources(apiConfig.baseUrl, auth, args);
            return respond(data);
        }
    },
    {
        name: 'aggregate_data',
        title: 'Aggregate Data',
        description: 'THE ONLY WAY to SUM/COUNT/AVG/MIN/MAX/GROUP BY. Always pass group_by when possible. Do not use get_table_data for aggregation.',
        schema: z.object({
            table_name: z.string().describe('Table to aggregate'),
            aggregates: z.array(z.object({
                function: z.enum(['SUM', 'COUNT', 'AVG', 'MIN', 'MAX']).describe('Aggregate function'),
                field: z.string().describe('Column to aggregate (use "*" for COUNT)'),
                alias: z.string().optional().describe('Name for the result column')
            })).describe('List of aggregations to compute'),
            filter: z.string().optional().describe('Filter rows before aggregating (same syntax as get_table_data)'),
            group_by: z.array(z.string()).optional().describe('Group results by these columns')
        }),
        handler: async (args, _context, apiConfig, auth) => {
            const data = await DreamFactoryService.aggregateData(apiConfig.baseUrl, auth, args);
            return respond(data);
        }
    }
];
/** Base tool names for database services. */
export const DB_TOOL_NAMES = BASE_TOOLS.map(t => t.name);
/**
 * Merged tool registration (tool_style = 'merged').
 *
 * The prefixed scheme emits every verb once per database, so five databases cost
 * 5 x 16 = 80 tools and ~14k catalog tokens even though the schemas are identical
 * apart from the prefix. Merged mode registers each verb once and selects the
 * backend with a `service` argument.
 *
 * Per-service disables are preserved, not discarded. disabled_tools entries are
 * prefixed (dvdstore_delete_records), and a merged tool spans every service, so:
 *
 *   - a verb disabled for EVERY exposed service (or disabled by its bare name)
 *     is not registered at all;
 *   - a verb disabled for SOME services is registered, but only the services it
 *     is still allowed on appear in the `service` enum, and the handler rejects
 *     any other service as a second line of defence. Without this, flipping
 *     tool_style to merged would silently re-enable a destructive tool an admin
 *     had turned off for one database.
 *
 * When exactly one service is allowed for a verb there is nothing to
 * disambiguate, so the `service` argument is omitted entirely and the call stays
 * a one-argument call.
 */
function registerMergedDatabaseTools(server, sessionManager, dbConfigs, disabledTools, tools = BASE_TOOLS) {
    if (dbConfigs.length === 0) {
        return;
    }
    const registerTool = createToolRegistrar(server, disabledTools);
    const notes = [];
    for (const tool of tools) {
        // Services this verb is still permitted on, after per-service disables.
        const allowed = dbConfigs.filter(c => !disabledTools?.has(`${sanitizeApiName(c.name)}_${tool.name}`));
        // Bare-name disable, or disabled everywhere: drop the tool entirely.
        if (disabledTools?.has(tool.name) || allowed.length === 0) {
            continue;
        }
        const blocked = dbConfigs.filter(c => !allowed.includes(c));
        if (blocked.length > 0) {
            notes.push(`${tool.name}: ${allowed.map(c => c.name).join(', ')} (blocked: ${blocked.map(c => c.name).join(', ')})`);
        }
        const allowedNames = allowed.map(c => c.name);
        const byName = new Map(allowed.map(c => [c.name, c]));
        const only = allowed.length === 1 ? allowed[0] : undefined;
        const schema = only
            ? tool.schema
            : tool.schema.extend({
                service: z
                    .enum(allowedNames)
                    .describe(`Which database to target. One of: ${allowedNames.join(', ')}`)
            });
        const description = only
            ? `[${only.name}] ${tool.description}`
            : `${tool.description} Pass service= to choose the database (${allowedNames.join(', ')}).`;
        registerTool(tool.name, tool.title, description, schema, async (params, context) => {
            const auth = getAuth(sessionManager, context.sessionId);
            if (only) {
                return tool.handler(params, context, only, auth);
            }
            const { service, ...rest } = (params ?? {});
            const apiConfig = service ? byName.get(service) : undefined;
            if (!apiConfig) {
                // Either no service was given, or one that is disabled for this verb
                // / not exposed at all. Say which, without leaking disabled names.
                return respond({
                    error: service
                        ? `"${tool.name}" is not available for service "${service}".`
                        : 'Missing required argument "service".',
                    available_services: allowedNames
                });
            }
            return tool.handler(rest, context, apiConfig, auth);
        }, { serviceNames: only ? undefined : serviceNameMap(allowed) });
    }
    if (notes.length > 0) {
        console.log('[merged-tools] per-service disables enforced at call time; allowed services per verb: ' +
            notes.join('; '));
    }
}
export function registerDreamFactoryTools(server, sessionManager, apiConfigs, disabledTools, toolStyle = 'prefixed', allowWrites = true) {
    const registerTool = createToolRegistrar(server, disabledTools);
    // Register API connector tools (list_apis, all_get_tables, etc.)
    registerApiConnectorTools(server, sessionManager, apiConfigs, disabledTools);
    // Register file API tools
    registerFileApiTools(server, sessionManager, apiConfigs, disabledTools, allowWrites);
    // Filter to database services only for database tools
    const dbConfigs = apiConfigs.filter(c => c.category === 'database');
    // allow_writes=false: write verbs are not registered at all, so they are
    // absent from tools/list and from the lazy catalog (call_tool cannot reach them).
    const tools = allowWrites ? BASE_TOOLS : BASE_TOOLS.filter(t => !WRITE_VERBS.has(t.name));
    if (toolStyle === 'merged') {
        registerMergedDatabaseTools(server, sessionManager, dbConfigs, disabledTools, tools);
    }
    else {
        // Register prefixed tools for each database API
        for (const apiConfig of dbConfigs) {
            const prefix = sanitizeApiName(apiConfig.name);
            for (const tool of tools) {
                const prefixedName = `${prefix}_${tool.name}`;
                const prefixedTitle = `${apiConfig.name}: ${tool.title}`;
                const prefixedDescription = `[${apiConfig.name}] ${tool.description}`;
                registerTool(prefixedName, prefixedTitle, prefixedDescription, tool.schema, async (params, context) => {
                    const auth = getAuth(sessionManager, context.sessionId);
                    return tool.handler(params, context, apiConfig, auth);
                });
            }
        }
    }
    // Register stub tools for connectors that require them
    registerTool('search', 'Search (stub)', 'Stub search implementation for connectors that require it', z.object({ query: z.string() }), async () => ({
        content: [{ type: 'text', text: JSON.stringify({ results: [] }) }]
    }));
    registerTool('fetch', 'Fetch (stub)', 'Stub fetch implementation for connectors that require it', z.object({ id: z.string() }), async ({ id }) => ({
        content: [
            {
                type: 'text',
                text: JSON.stringify({
                    id,
                    title: '',
                    text: '',
                    url: '',
                    metadata: null
                })
            }
        ]
    }));
}

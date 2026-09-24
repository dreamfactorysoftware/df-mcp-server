import * as z from 'zod/v4';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { DreamFactoryService, type DFAuthConfig } from './dreamfactory.service.js';
import { SessionService } from './session.service.js';
import { registerApiConnectorTools } from './api-connector.tools.js';
import { registerFileApiTools } from './file-api.tools.js';
import type { ApiConfig, ToolStyle } from '../types.js';
import { type CallStyle, createToolRegistrar, getAuth, registerMergedTools, respond, sanitizeApiName, type ToolResponse, WRITE_VERBS } from './tool-utils.js';
import { serviceNameMap } from './args.js';

type ToolDefinition = {
  name: string;
  title: string;
  description: string;
  schema: z.ZodTypeAny;
  handler: (
    params: any,
    context: { sessionId?: string },
    apiConfig: ApiConfig,
    auth: DFAuthConfig,
    call?: CallStyle
  ) => Promise<ToolResponse>;
};

/**
 * df-core's data model ships `query_templates` naming bare verbs (`get_table_data`)
 * with no `service`. Rewrite each one into the exact call this server accepts:
 * `{prefix}_{verb}` in prefixed mode, `service` added in merged mode.
 */
export function adaptQueryTemplates(model: any, call: CallStyle = {}): any {
  const templates = model?.query_templates;
  if (!templates || typeof templates !== 'object') return model;
  for (const t of Object.values(templates) as any[]) {
    if (!t || typeof t.tool !== 'string') continue;
    if (call.prefix) t.tool = `${call.prefix}_${t.tool}`;
    if (call.service && t.params && typeof t.params === 'object') t.params = { ...t.params, service: call.service };
  }
  return model;
}

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
const BASE_TOOLS: ToolDefinition[] = [
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
    handler: async (args, _context, apiConfig, auth, call) => {
      const data = await DreamFactoryService.getApiSpec(apiConfig.baseUrl, auth, {
        model: true,
        refresh: args?.refresh
      });
      return respond(adaptQueryTemplates(data, call));
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
      ids: z.array(z.union([z.string(), z.number()])).optional()
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
      ids: z.array(z.union([z.string(), z.number()])).optional(),
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
      ids: z.array(z.union([z.string(), z.number()])).optional(),
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
      const data = await DreamFactoryService.callStoredProcedure(
        procedure_name,
        apiConfig.baseUrl,
        auth,
        parameters,
        wrapper,
        returns
      );
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
 * Merged database registration (tool_style = 'merged'): every verb once, with a
 * `service` argument choosing the database. The per-service disable semantics
 * and the single-service collapse live in registerMergedTools, which file
 * services share.
 */
function registerMergedDatabaseTools(
  server: McpServer,
  sessionManager: SessionService,
  dbConfigs: ApiConfig[],
  disabledTools?: Set<string>,
  tools: ToolDefinition[] = BASE_TOOLS
) {
  registerMergedTools(server, sessionManager, dbConfigs, tools, {
    targetNoun: 'database',
    logLabel: 'merged-tools',
    disabledTools
  });
}

export function registerDreamFactoryTools(
  server: McpServer,
  sessionManager: SessionService,
  apiConfigs: ApiConfig[],
  disabledTools?: Set<string>,
  toolStyle: ToolStyle = 'prefixed',
  allowWrites = true
) {
  const registerTool = createToolRegistrar(server, disabledTools);

  // Register API connector tools (list_apis, all_get_tables, etc.)
  registerApiConnectorTools(server, sessionManager, apiConfigs, disabledTools);

  // Register file API tools
  registerFileApiTools(server, sessionManager, apiConfigs, disabledTools, allowWrites, toolStyle);

  // Filter to database services only for database tools
  const dbConfigs = apiConfigs.filter(c => c.category === 'database');

  // allow_writes=false: write verbs are not registered at all, so they are
  // absent from tools/list and from the lazy catalog (call_tool cannot reach them).
  const tools = allowWrites ? BASE_TOOLS : BASE_TOOLS.filter(t => !WRITE_VERBS.has(t.name));

  if (toolStyle === 'merged') {
    registerMergedDatabaseTools(server, sessionManager, dbConfigs, disabledTools, tools);
  } else {
    // Register prefixed tools for each database API
    for (const apiConfig of dbConfigs) {
      const prefix = sanitizeApiName(apiConfig.name);

      for (const tool of tools) {
        const prefixedName = `${prefix}_${tool.name}`;
        const prefixedTitle = `${apiConfig.name}: ${tool.title}`;
        const prefixedDescription = `[${apiConfig.name}] ${tool.description}`;

        registerTool(
          prefixedName,
          prefixedTitle,
          prefixedDescription,
          tool.schema,
          async (params, context) => {
            const auth = getAuth(sessionManager, context.sessionId);
            return tool.handler(params, context, apiConfig, auth, { prefix });
          }
        );
      }
    }
  }

  // Register stub tools for connectors that require them
  registerTool(
    'search',
    'Search (stub)',
    'Stub search implementation for connectors that require it',
    z.object({ query: z.string() }),
    async () => ({
      content: [{ type: 'text', text: JSON.stringify({ results: [] }) }]
    })
  );

  registerTool(
    'fetch',
    'Fetch (stub)',
    'Stub fetch implementation for connectors that require it',
    z.object({ id: z.string() }),
    async ({ id }) => ({
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
    })
  );
}

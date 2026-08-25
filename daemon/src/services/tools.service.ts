import * as z from 'zod/v4';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { DreamFactoryService, type DFAuthConfig } from './dreamfactory.service.js';
import { SessionService } from './session.service.js';
import { registerApiConnectorTools } from './api-connector.tools.js';
import { registerFileApiTools } from './file-api.tools.js';
import type { ApiConfig } from '../types.js';
import { type ToolResponse, type ToolCallContext, type ProgressReporter, respond, sanitizeApiName, getAuth, createToolRegistrar, makeProgressReporter } from './tool-utils.js';

type ToolDefinition = {
  name: string;
  title: string;
  description: string;
  schema: z.ZodTypeAny;
  handler: (
    params: any,
    context: ToolCallContext,
    apiConfig: ApiConfig,
    auth: DFAuthConfig,
    onProgress?: ProgressReporter
  ) => Promise<ToolResponse>;
};

/**
 * Base tool definitions that will be registered for each API.
 * The handler receives the API config and auth, so it knows which API to target.
 */
const BASE_TOOLS: ToolDefinition[] = [
  {
    name: 'get_api_spec',
    title: 'Get API Spec',
    description: 'Get the OpenAPI 3.0 specification for this database service. Returns endpoint descriptions, ' +
      'query parameter syntax (filter operators, order format, field selection), table names with row counts, ' +
      'relationships between tables (including structural patterns like hierarchies), and LLM usage hints. ' +
      'TIP: Use get_data_model instead for a more condensed schema-focused view. ' +
      'Use compact=true (default) for a token-efficient summary. Use tables=true to include full table/field details. ' +
      'Use resourceName to get spec for a specific table only.',
    schema: z.object({
      compact: z.boolean().optional().describe('Return compact token-efficient format (default: true)'),
      resourceName: z.string().optional().describe('Get spec for a specific table/resource only'),
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
    description: 'Get a condensed data model showing ALL tables, their columns (name + type + foreign keys), ' +
      'row counts, and structural patterns (hierarchies, junction tables). Returns ~10-20KB — small enough ' +
      'to read in full. IMPORTANT: This is the best tool to call FIRST. It tells you:\n' +
      '- Every table and column with types\n' +
      '- Which columns are foreign keys and what they reference\n' +
      '- Self-referencing hierarchies (e.g. dept.parent_dept_id → dept = tree structure needing recursive traversal)\n' +
      '- Junction tables for many-to-many relationships\n' +
      'Use this to plan your queries before calling get_table_data.',
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
    description: 'Get tables available in the database. TIP: Use get_data_model first for richer metadata including columns, relationships, and structural patterns.',
    schema: z.object({}),
    handler: async (_args, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.getTables(apiConfig.baseUrl, auth);
      return respond(data);
    }
  },
  {
    name: 'get_table_schema',
    title: 'Get Table Schema',
    description: 'Retrieve the schema of a specific table',
    schema: z.object({ tableName: z.string() }),
    handler: async ({ tableName }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.getTableSchema(tableName, apiConfig.baseUrl, auth);
      return respond(data);
    }
  },
  {
    name: 'get_table_data',
    title: 'Get Table Data',
    description: 'Retrieve table data with filtering, pagination, and sorting.\n' +
      'IMPORTANT: The API returns max 1000 records per request. For large tables, paginate with limit+offset and set includeCount=true to know the total.\n' +
      'COUNTING: Use countOnly=true to get just the record count without fetching data.\n' +
      'STOP — AGGREGATION: If you need SUM, COUNT, AVG, MIN, MAX, totals, or GROUP BY — do NOT use this tool. Use aggregate_data instead. This tool CANNOT do aggregation and will error if you try.\n' +
      'Filter syntax: field=value, field>value, field LIKE %value%. Order syntax: field ASC, field DESC.',
    schema: z.object({
      tableName: z.string(),
      fields: z.array(z.string()).optional(),
      filter: z.string().optional(),
      offset: z.number().optional(),
      limit: z.number().optional().describe('Max records per request (server max: 1000). Use with offset to paginate.'),
      order: z.string().optional(),
      continue: z.boolean().optional(),
      related: z.string().optional().describe('Include related records via FK (e.g. "parent_table_by_fk_field"). Check relationships in the data model.'),
      countOnly: z.boolean().optional().describe('Return only the record count, no data. Use this instead of COUNT() in fields.'),
      includeCount: z.boolean().optional().describe('Include total record count in response metadata alongside data.'),
      includeSchema: z.boolean().optional(),
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
    description: 'Create one or more records in a table',
    schema: z.object({
      tableName: z.string(),
      records: z.array(z.record(z.string(), z.unknown())),
      fields: z.array(z.string()).optional(),
      related: z.string().optional(),
      continue: z.boolean().optional(),
      rollback: z.boolean().optional()
    }),
    handler: async ({ tableName, records, ...options }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.createRecords(tableName, apiConfig.baseUrl, auth, records, options);
      return respond(data);
    }
  },
  {
    name: 'update_records',
    title: 'Update Records',
    description: 'Update (patch) records in a table',
    schema: z.object({
      tableName: z.string(),
      records: z.array(z.record(z.string(), z.unknown())),
      fields: z.array(z.string()).optional(),
      related: z.string().optional(),
      ids: z.array(z.string()).optional(),
      filter: z.string().optional(),
      continue: z.boolean().optional(),
      rollback: z.boolean().optional()
    }),
    handler: async ({ tableName, records, ...options }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.updateRecords(tableName, apiConfig.baseUrl, auth, records, options);
      return respond(data);
    }
  },
  {
    name: 'delete_records',
    title: 'Delete Records',
    description: 'Delete records from a table',
    schema: z.object({
      tableName: z.string(),
      ids: z.array(z.string()).optional(),
      filter: z.string().optional(),
      force: z.boolean().optional(),
      fields: z.array(z.string()).optional(),
      related: z.string().optional(),
      continue: z.boolean().optional(),
      rollback: z.boolean().optional()
    }),
    handler: async ({ tableName, ...options }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.deleteRecords(tableName, apiConfig.baseUrl, auth, options);
      return respond(data);
    }
  },
  {
    name: 'get_table_fields',
    title: 'Get Table Fields',
    description: 'Retrieve field definitions for a table',
    schema: z.object({
      tableName: z.string(),
      refresh: z.boolean().optional()
    }),
    handler: async ({ tableName, refresh }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.getTableFields(tableName, apiConfig.baseUrl, auth, refresh);
      return respond(data);
    }
  },
  {
    name: 'get_table_relationships',
    title: 'Get Table Relationships',
    description: 'Get foreign key relationships for a table. Shows which columns reference other tables, self-referencing hierarchies (e.g. parent_dept_id → dept for tree structures requiring recursive traversal), and junction tables for many-to-many joins.',
    schema: z.object({
      tableName: z.string(),
      refresh: z.boolean().optional()
    }),
    handler: async ({ tableName, refresh }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.getTableRelationships(tableName, apiConfig.baseUrl, auth, refresh);
      return respond(data);
    }
  },
  {
    name: 'get_stored_procedures',
    title: 'List Stored Procedures',
    description: 'Get stored procedures available in the database',
    schema: z.object({}),
    handler: async (_args, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.getStoredProcedures(apiConfig.baseUrl, auth);
      return respond(data);
    }
  },
  {
    name: 'call_stored_procedure',
    title: 'Call Stored Procedure',
    description: 'Call a stored procedure',
    schema: z.object({
      procedureName: z.string(),
      parameters: z.record(z.string(), z.unknown()).optional(),
      wrapper: z.string().optional(),
      returns: z.string().optional()
    }),
    handler: async ({ procedureName, parameters, wrapper, returns }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.callStoredProcedure(
        procedureName,
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
    description: 'Get stored functions available in the database',
    schema: z.object({}),
    handler: async (_args, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.getStoredFunctions(apiConfig.baseUrl, auth);
      return respond(data);
    }
  },
  {
    name: 'call_stored_function',
    title: 'Call Stored Function',
    description: 'Call a stored function',
    schema: z.object({
      functionName: z.string(),
      parameters: z.record(z.string(), z.unknown()).optional(),
      returns: z.string().optional()
    }),
    handler: async ({ functionName, parameters, returns }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.callStoredFunction(functionName, apiConfig.baseUrl, auth, parameters, returns);
      return respond(data);
    }
  },
  {
    name: 'get_database_resources',
    title: 'List Database Resources',
    description: 'Get all resources available in the database service',
    schema: z.object({
      asList: z.boolean().optional(),
      asAccessList: z.boolean().optional(),
      includeAccess: z.boolean().optional(),
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
    description: 'THE ONLY WAY to compute totals, sums, counts, averages, min/max on this database. Do NOT use get_table_data for aggregation — it will fail.\n' +
      'Pushes SUM, COUNT, AVG, MIN, MAX to the database server. Returns results in a single call — no pagination needed.\n' +
      'IMPORTANT: Always provide groupBy for efficient server-side aggregation. Without groupBy, falls back to slow client-side pagination.\n' +
      'Examples:\n' +
      '  - Total revenue by currency: aggregates=[{function:"SUM", field:"totalamount"}], groupBy=["currency"]\n' +
      '  - Revenue by country: aggregates=[{function:"SUM", field:"totalamount"}], groupBy=["country"]\n' +
      '  - Average order value by status: aggregates=[{function:"AVG", field:"totalamount"}], groupBy=["status"]\n' +
      '  - Row count by category: aggregates=[{function:"COUNT", field:"*"}], groupBy=["category"]',
    schema: z.object({
      tableName: z.string().describe('Table to aggregate'),
      aggregates: z.array(z.object({
        function: z.enum(['SUM', 'COUNT', 'AVG', 'MIN', 'MAX']).describe('Aggregate function'),
        field: z.string().describe('Column to aggregate (use "*" for COUNT)'),
        alias: z.string().optional().describe('Name for the result column')
      })).describe('List of aggregations to compute'),
      filter: z.string().optional().describe('Filter rows before aggregating (same syntax as get_table_data)'),
      groupBy: z.array(z.string()).optional().describe('Group results by these columns')
    }),
    handler: async (args, _context, apiConfig, auth, onProgress) => {
      const data = await DreamFactoryService.aggregateData(apiConfig.baseUrl, auth, args, onProgress);
      return respond(data);
    }
  }
];

/** Base tool names for database services. */
export const DB_TOOL_NAMES = BASE_TOOLS.map(t => t.name);

export function registerDreamFactoryTools(
  server: McpServer,
  sessionManager: SessionService,
  apiConfigs: ApiConfig[],
  disabledTools?: Set<string>
) {
  const registerTool = createToolRegistrar(server, disabledTools);

  // Register API connector tools (list_apis, all_get_tables, etc.)
  registerApiConnectorTools(server, sessionManager, apiConfigs, disabledTools);

  // Register file API tools
  registerFileApiTools(server, sessionManager, apiConfigs, disabledTools);

  // Filter to database services only for database tools
  const dbConfigs = apiConfigs.filter(c => c.category === 'database');

  // Register prefixed tools for each database API
  for (const apiConfig of dbConfigs) {
    const prefix = sanitizeApiName(apiConfig.name);

    for (const tool of BASE_TOOLS) {
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
          const onProgress = makeProgressReporter(server, context._meta);
          return tool.handler(params, context, apiConfig, auth, onProgress);
        }
      );
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

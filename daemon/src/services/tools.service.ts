import * as z from 'zod/v4';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { DreamFactoryService, type DFAuthConfig } from './dreamfactory.service.js';
import { SessionService } from './session.service.js';
import { registerApiConnectorTools } from './api-connector.tools.js';
import { registerFileApiTools } from './file-api.tools.js';
import type { ApiConfig } from '../types.js';
import { type ToolResponse, respond, sanitizeApiName, getAuth, createToolRegistrar } from './tool-utils.js';

type ToolDefinition = {
  name: string;
  title: string;
  description: string;
  schema: z.ZodTypeAny;
  handler: (
    params: any,
    context: { sessionId?: string },
    apiConfig: ApiConfig,
    auth: DFAuthConfig
  ) => Promise<ToolResponse>;
};

/**
 * Base tool definitions that will be registered for each API.
 * The handler receives the API config and auth, so it knows which API to target.
 */
const BASE_TOOLS: ToolDefinition[] = [
  {
    name: 'get_tables',
    title: 'List Tables',
    description: 'Get tables available in the database',
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
    description: 'Retrieve table data with filtering, pagination, and sorting',
    schema: z.object({
      tableName: z.string(),
      fields: z.array(z.string()).optional(),
      filter: z.string().optional(),
      offset: z.number().optional(),
      limit: z.number().optional(),
      order: z.string().optional(),
      group: z.string().optional(),
      continue: z.boolean().optional(),
      related: z.string().optional(),
      countOnly: z.boolean().optional(),
      includeCount: z.boolean().optional(),
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
    description: 'Retrieve relationships definition for a table',
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
  }
];

export function registerDreamFactoryTools(
  server: McpServer,
  sessionManager: SessionService,
  apiConfigs: ApiConfig[]
) {
  const registerTool = createToolRegistrar(server);

  // Register API connector tools (list_apis, all_get_tables, etc.)
  registerApiConnectorTools(server, sessionManager, apiConfigs);

  // Register file API tools
  registerFileApiTools(server, sessionManager, apiConfigs);

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
          return tool.handler(params, context, apiConfig, auth);
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

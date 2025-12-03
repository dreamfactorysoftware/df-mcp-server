import * as z from 'zod/v4';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { DreamFactoryService } from './dreamfactory.service.js';
import { SessionService } from './session.service.js';

type ToolResponse = {
  content: Array<{ type: 'text'; text: string }>;
  isError?: boolean;
};

// The label is not used in this version due to conflicts with ChatGPT agent response requirements
const respond = (label: string, data: unknown): ToolResponse => ({
  content: [
    { type: 'text', text: JSON.stringify(data, null, 2) }
  ]
});

const respondError = (message: string): ToolResponse => ({
  content: [{ type: 'text', text: message }],
  isError: true
});

const handleError = (error: unknown, operation: string): string => {
  if (!(error instanceof Error)) {
    return `Unknown error during ${operation}: ${String(error)}`;
  }

  const message = error.message;
  if (message.includes('Authentication failed') || message.includes('401')) {
    return `Authentication Error: ${message}`;
  }
  if (message.includes('Network error') || message.includes('Unable to connect')) {
    return `Connection Error: ${message}`;
  }
  if (message.includes('Access forbidden') || message.includes('403')) {
    return `Permission Error: ${message}`;
  }
  if (message.includes('Resource not found') || message.includes('404')) {
    return `Resource Error: ${message}`;
  }
  if (message.includes('Validation error') || message.includes('422')) {
    return `Validation Error: ${message}`;
  }
  if (message.includes('Server error') || message.includes('500')) {
    return `Server Error: ${message}`;
  }
  return `Error during ${operation}: ${message}`;
};

export function registerDreamFactoryTools(server: McpServer, sessionManager: SessionService) {
  const getSessionConfig = (sessionId?: string) => {
    const sessionConfig = sessionId ? sessionManager.getConfig(sessionId) : undefined;
    const url = sessionConfig?.url ?? process.env.DREAMFACTORY_URL ?? '';
    const apiKey = sessionConfig?.apiKey ?? process.env.DREAMFACTORY_API_KEY ?? '';

    if (!url || !apiKey) {
      throw new Error(
        'DreamFactory configuration not found. Please ensure DREAMFACTORY_URL and DREAMFACTORY_API_KEY are configured either in the session or process environment.'
      );
    }

    return { url, apiKey };
  };

  const tool = (
    name: string,
    title: string,
    description: string,
    schema: z.ZodTypeAny,
    handler: (params: any, context: { sessionId?: string }) => Promise<ToolResponse>
  ) => {
    server.registerTool(
      name,
      {
        title,
        description,
        inputSchema: schema
      },
      async (params, context) => {
        try {
          return await handler(params ?? {}, context ?? {});
        } catch (error) {
          console.error(`Tool ${name} error:`, error);
          return respondError(handleError(error, name));
        }
      }
    );
  };

  tool('get_tables', 'List Tables', 'Get tables available in the database', z.object({}), async (_args, { sessionId }) => {
    const config = getSessionConfig(sessionId);
    const data = await DreamFactoryService.getTables(config.url, config.apiKey);
    return respond('Tables available in the database:', data);
  });

  tool(
    'get_table_schema',
    'Get Table Schema',
    'Retrieve the schema of a specific table',
    z.object({ tableName: z.string() }),
    async ({ tableName }, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      const data = await DreamFactoryService.getTableSchema(tableName, config.url, config.apiKey);
      return respond(`Schema for table ${tableName}:`, data);
    }
  );

  tool(
    'get_table_data',
    'Get Table Data',
    'Retrieve table data with filtering, pagination, and sorting',
    z.object({
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
    async (args, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      const data = await DreamFactoryService.getTableData(config.url, config.apiKey, args);
      return respond(`Data for table ${args.tableName}:`, data);
    }
  );

  tool(
    'create_records',
    'Create Records',
    'Create one or more records in a table',
    z.object({
      tableName: z.string(),
      records: z.array(z.record(z.string(), z.unknown())),
      fields: z.array(z.string()).optional(),
      related: z.string().optional(),
      continue: z.boolean().optional(),
      rollback: z.boolean().optional()
    }),
    async ({ tableName, records, ...options }, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      const data = await DreamFactoryService.createRecords(tableName, config.url, config.apiKey, records, options);
      return respond(`Records created in table ${tableName}:`, data);
    }
  );

  tool(
    'update_records',
    'Update Records',
    'Update (patch) records in a table',
    z.object({
      tableName: z.string(),
      records: z.array(z.record(z.string(), z.unknown())),
      fields: z.array(z.string()).optional(),
      related: z.string().optional(),
      ids: z.array(z.string()).optional(),
      filter: z.string().optional(),
      continue: z.boolean().optional(),
      rollback: z.boolean().optional()
    }),
    async ({ tableName, records, ...options }, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      const data = await DreamFactoryService.updateRecords(tableName, config.url, config.apiKey, records, options);
      return respond(`Records updated in table ${tableName}:`, data);
    }
  );

  tool(
    'delete_records',
    'Delete Records',
    'Delete records from a table',
    z.object({
      tableName: z.string(),
      ids: z.array(z.string()).optional(),
      filter: z.string().optional(),
      force: z.boolean().optional(),
      fields: z.array(z.string()).optional(),
      related: z.string().optional(),
      continue: z.boolean().optional(),
      rollback: z.boolean().optional()
    }),
    async ({ tableName, ...options }, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      const data = await DreamFactoryService.deleteRecords(tableName, config.url, config.apiKey, options);
      return respond(`Records deleted from table ${tableName}:`, data);
    }
  );

  tool(
    'get_table_fields',
    'Get Table Fields',
    'Retrieve field definitions for a table',
    z.object({
      tableName: z.string(),
      refresh: z.boolean().optional()
    }),
    async ({ tableName, refresh }, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      const data = await DreamFactoryService.getTableFields(tableName, config.url, config.apiKey, refresh);
      return respond(`Fields for table ${tableName}:`, data);
    }
  );

  tool(
    'get_table_relationships',
    'Get Table Relationships',
    'Retrieve relationships definition for a table',
    z.object({
      tableName: z.string(),
      refresh: z.boolean().optional()
    }),
    async ({ tableName, refresh }, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      const data = await DreamFactoryService.getTableRelationships(tableName, config.url, config.apiKey, refresh);
      return respond(`Relationships for table ${tableName}:`, data);
    }
  );

  tool(
    'get_stored_procedures',
    'List Stored Procedures',
    'Get stored procedures available in the database',
    z.object({}),
    async (_args, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      const data = await DreamFactoryService.getStoredProcedures(config.url, config.apiKey);
      return respond('Stored procedures available:', data);
    }
  );

  tool(
    'call_stored_procedure',
    'Call Stored Procedure',
    'Call a stored procedure',
    z.object({
      procedureName: z.string(),
      parameters: z.record(z.string(), z.unknown()).optional(),
      wrapper: z.string().optional(),
      returns: z.string().optional()
    }),
    async ({ procedureName, parameters, wrapper, returns }, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      const data = await DreamFactoryService.callStoredProcedure(
        procedureName,
        config.url,
        config.apiKey,
        parameters,
        wrapper,
        returns
      );
      return respond(`Stored procedure ${procedureName} called successfully:`, data);
    }
  );

  tool(
    'get_stored_functions',
    'List Stored Functions',
    'Get stored functions available in the database',
    z.object({}),
    async (_args, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      const data = await DreamFactoryService.getStoredFunctions(config.url, config.apiKey);
      return respond('Stored functions available:', data);
    }
  );

  tool(
    'call_stored_function',
    'Call Stored Function',
    'Call a stored function',
    z.object({
      functionName: z.string(),
      parameters: z.record(z.string(), z.unknown()).optional(),
      returns: z.string().optional()
    }),
    async ({ functionName, parameters, returns }, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      const data = await DreamFactoryService.callStoredFunction(functionName, config.url, config.apiKey, parameters, returns);
      return respond(`Stored function ${functionName} called successfully:`, data);
    }
  );

  tool(
    'get_database_resources',
    'List Database Resources',
    'Get all resources available in the database service',
    z.object({
      asList: z.boolean().optional(),
      asAccessList: z.boolean().optional(),
      includeAccess: z.boolean().optional(),
      fields: z.array(z.string()).optional(),
      refresh: z.boolean().optional()
    }),
    async (args, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      const data = await DreamFactoryService.getDatabaseResources(config.url, config.apiKey, args);
      return respond('Database resources:', data);
    }
  );

  tool(
    'search',
    'Search (stub)',
    'Stub search implementation for connectors that require it',
    z.object({ query: z.string() }),
    async () => ({
      content: [{ type: 'text', text: JSON.stringify({ results: [] }) }]
    })
  );

  tool(
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


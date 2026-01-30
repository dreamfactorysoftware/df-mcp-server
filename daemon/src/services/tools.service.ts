import * as z from 'zod/v4';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { DreamFactoryService, type DFAuthConfig } from './dreamfactory.service.js';
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
  const getSessionConfig = (sessionId?: string): { url: string; auth: DFAuthConfig } => {
    const sessionConfig = sessionId ? sessionManager.getConfig(sessionId) : undefined;
    const url = sessionConfig?.url ?? process.env.DREAMFACTORY_URL ?? '';
    const sessionToken = sessionConfig?.sessionToken ?? '';
    const apiKey = sessionConfig?.apiKey;

    if (!url || !sessionToken) {
      throw new Error(
        'DreamFactory session not found. Please authenticate via OAuth.'
      );
    }

    return {
      url,
      auth: { sessionToken, apiKey }
    };
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

  tool(
    'get_api_spec',
    'Get API Spec',
    'Get the OpenAPI 3.0 specification for this database service. Returns endpoint descriptions, ' +
    'query parameter syntax (filter operators, order format, field selection), table names with row counts, ' +
    'relationships between tables (including structural patterns like hierarchies), and LLM usage hints. ' +
    'TIP: Use get_data_model instead for a more condensed schema-focused view. ' +
    'Use compact=true (default) for a token-efficient summary. Use tables=true to include full table/field details. ' +
    'Use resourceName to get spec for a specific table only.',
    z.object({
      compact: z.boolean().optional().describe('Return compact token-efficient format (default: true)'),
      resourceName: z.string().optional().describe('Get spec for a specific table/resource only'),
      tables: z.boolean().optional().describe('Include full table and field details'),
      refresh: z.boolean().optional().describe('Force refresh cached spec data')
    }),
    async (args, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      // Default to compact mode for LLM efficiency
      const options = { compact: true, ...args };
      const data = await DreamFactoryService.getApiSpec(config.url, config.auth, options);
      return respond('API Specification:', data);
    }
  );

  tool(
    'get_data_model',
    'Get Data Model',
    'Get a condensed data model showing ALL tables, their columns (name + type + foreign keys), ' +
    'row counts, and structural patterns (hierarchies, junction tables). Returns ~10-20KB — small enough ' +
    'to read in full. IMPORTANT: This is the best tool to call FIRST. It tells you:\n' +
    '- Every table and column with types\n' +
    '- Which columns are foreign keys and what they reference\n' +
    '- Self-referencing hierarchies (e.g. dept.parent_dept_id → dept = tree structure needing recursive traversal)\n' +
    '- Junction tables for many-to-many relationships\n' +
    'Use this to plan your queries before calling get_table_data.',
    z.object({
      refresh: z.boolean().optional().describe('Force refresh cached data')
    }),
    async (args, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      const data = await DreamFactoryService.getApiSpec(config.url, config.auth, {
        model: true,
        refresh: args?.refresh
      });
      return respond('Data Model:', data);
    }
  );

  tool(
    'get_tables',
    'List Tables',
    'Get tables available in the database. TIP: Use get_data_model first for richer metadata including columns, relationships, and structural patterns.',
    z.object({}),
    async (_args, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      const data = await DreamFactoryService.getTables(config.url, config.auth);
      return respond('Tables available in the database:', data);
    }
  );

  tool(
    'get_table_schema',
    'Get Table Schema',
    'Retrieve the schema of a specific table',
    z.object({ tableName: z.string() }),
    async ({ tableName }, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      const data = await DreamFactoryService.getTableSchema(tableName, config.url, config.auth);
      return respond(`Schema for table ${tableName}:`, data);
    }
  );

  tool(
    'get_table_data',
    'Get Table Data',
    'Retrieve table data with filtering, pagination, and sorting. Filter syntax: field=value, field>value, field LIKE %value%. Order syntax: field ASC, field DESC. Use get_api_spec to learn all available filter operators and field names.',
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
      const data = await DreamFactoryService.getTableData(config.url, config.auth, args);
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
      const data = await DreamFactoryService.createRecords(tableName, config.url, config.auth, records, options);
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
      const data = await DreamFactoryService.updateRecords(tableName, config.url, config.auth, records, options);
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
      const data = await DreamFactoryService.deleteRecords(tableName, config.url, config.auth, options);
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
      const data = await DreamFactoryService.getTableFields(tableName, config.url, config.auth, refresh);
      return respond(`Fields for table ${tableName}:`, data);
    }
  );

  tool(
    'get_table_relationships',
    'Get Table Relationships',
    'Get foreign key relationships for a table. Shows which columns reference other tables, self-referencing hierarchies (e.g. parent_dept_id → dept for tree structures requiring recursive traversal), and junction tables for many-to-many joins.',
    z.object({
      tableName: z.string(),
      refresh: z.boolean().optional()
    }),
    async ({ tableName, refresh }, { sessionId }) => {
      const config = getSessionConfig(sessionId);
      const data = await DreamFactoryService.getTableRelationships(tableName, config.url, config.auth, refresh);
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
      const data = await DreamFactoryService.getStoredProcedures(config.url, config.auth);
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
        config.auth,
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
      const data = await DreamFactoryService.getStoredFunctions(config.url, config.auth);
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
      const data = await DreamFactoryService.callStoredFunction(functionName, config.url, config.auth, parameters, returns);
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
      const data = await DreamFactoryService.getDatabaseResources(config.url, config.auth, args);
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


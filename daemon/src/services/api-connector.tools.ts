import * as z from 'zod/v4';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { DreamFactoryService } from './dreamfactory.service.js';
import { SessionService } from './session.service.js';
import type { ApiConfig } from '../types.js';
import { respond, sanitizeApiName, getAuth, createToolRegistrar } from './tool-utils.js';
import { DB_TOOL_NAMES } from './tools.service.js';
import { FILE_TOOL_NAMES } from './file-api.tools.js';

/**
 * Register API connector tools that work across all databases.
 */
export function registerApiConnectorTools(
  server: McpServer,
  sessionManager: SessionService,
  apiConfigs: ApiConfig[],
  disabledTools?: Set<string>
) {
  const registerTool = createToolRegistrar(server, disabledTools);
  const dbConfigs = apiConfigs.filter(c => c.category === 'database');

  // List all available APIs (excludes services where all tools are disabled)
  registerTool(
    'list_apis',
    'List Available APIs',
    'List all available database APIs and their tool prefixes',
    z.object({}),
    async () => {
      const apis = apiConfigs
        .filter(api => {
          if (!disabledTools || disabledTools.size === 0) return true;
          const prefix = sanitizeApiName(api.name);
          const baseNames = api.category === 'file' ? FILE_TOOL_NAMES : DB_TOOL_NAMES;
          return baseNames.some(name => !disabledTools.has(`${prefix}_${name}`));
        })
        .map(api => ({
          name: api.name,
          prefix: sanitizeApiName(api.name),
          baseUrl: api.baseUrl,
          category: api.category
        }));
      return respond({ apis, count: apis.length });
    }
  );

  // Cross-service aggregators only pay off (and only cost tokens) when more
  // than one database is in this MCP connection's catalog.
  if (dbConfigs.length < 2) {
    return;
  }

  // Get tables from all databases
  registerTool(
    'all_get_tables',
    'Get Tables from All Databases',
    'Retrieve tables from all connected database services in one call',
    z.object({}),
    async (_args, { sessionId }) => {
      const auth = getAuth(sessionManager, sessionId);
      const results: Record<string, unknown> = {};

      await Promise.all(
        dbConfigs.map(async (api) => {
          try {
            const tables = await DreamFactoryService.getTables(api.baseUrl, auth);
            results[api.name] = { success: true, data: tables };
          } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            results[api.name] = { success: false, error: message };
          }
        })
      );

      return respond({ databases: results });
    }
  );

  // Get schema for a table across all databases (finds where it exists)
  registerTool(
    'all_find_table',
    'Find Table Across All Databases',
    'Search for a table by name across all connected databases and return its schema if found',
    z.object({
      tableName: z.string().describe('The table name to search for')
    }),
    async ({ tableName }, { sessionId }) => {
      const auth = getAuth(sessionManager, sessionId);
      const results: Record<string, unknown> = {};

      await Promise.all(
        dbConfigs.map(async (api) => {
          try {
            const schema = await DreamFactoryService.getTableSchema(tableName, api.baseUrl, auth);
            results[api.name] = { found: true, schema };
          } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            if (message.includes('404') || message.includes('not found')) {
              results[api.name] = { found: false };
            } else {
              results[api.name] = { found: false, error: message };
            }
          }
        })
      );

      const foundIn = Object.entries(results)
        .filter(([, v]) => (v as { found: boolean }).found)
        .map(([k]) => k);

      return respond({ tableName, foundIn, details: results });
    }
  );

  // Get stored procedures from all databases
  registerTool(
    'all_get_stored_procedures',
    'Get Stored Procedures from All Databases',
    'Retrieve stored procedures from all connected database services',
    z.object({}),
    async (_args, { sessionId }) => {
      const auth = getAuth(sessionManager, sessionId);
      const results: Record<string, unknown> = {};

      await Promise.all(
        dbConfigs.map(async (api) => {
          try {
            const procedures = await DreamFactoryService.getStoredProcedures(api.baseUrl, auth);
            results[api.name] = { success: true, data: procedures };
          } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            results[api.name] = { success: false, error: message };
          }
        })
      );

      return respond({ databases: results });
    }
  );

  // Get stored functions from all databases
  registerTool(
    'all_get_stored_functions',
    'Get Stored Functions from All Databases',
    'Retrieve stored functions from all connected database services',
    z.object({}),
    async (_args, { sessionId }) => {
      const auth = getAuth(sessionManager, sessionId);
      const results: Record<string, unknown> = {};

      await Promise.all(
        dbConfigs.map(async (api) => {
          try {
            const functions = await DreamFactoryService.getStoredFunctions(api.baseUrl, auth);
            results[api.name] = { success: true, data: functions };
          } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            results[api.name] = { success: false, error: message };
          }
        })
      );

      return respond({ databases: results });
    }
  );

  // Get database resources from all databases
  registerTool(
    'all_get_resources',
    'Get Resources from All Databases',
    'Retrieve all available resources from all connected database services',
    z.object({}),
    async (_args, { sessionId }) => {
      const auth = getAuth(sessionManager, sessionId);
      const results: Record<string, unknown> = {};

      await Promise.all(
        dbConfigs.map(async (api) => {
          try {
            const resources = await DreamFactoryService.getDatabaseResources(api.baseUrl, auth, {});
            results[api.name] = { success: true, data: resources };
          } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            results[api.name] = { success: false, error: message };
          }
        })
      );

      return respond({ databases: results });
    }
  );
}

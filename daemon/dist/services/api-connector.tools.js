import * as z from 'zod/v4';
import { DreamFactoryService } from './dreamfactory.service.js';
import { respond, sanitizeApiName, getAuth, createToolRegistrar } from './tool-utils.js';
import { DB_TOOL_NAMES } from './tools.service.js';
import { FILE_TOOL_NAMES } from './file-api.tools.js';
/**
 * Register API connector tools that work across all databases.
 */
export function registerApiConnectorTools(server, sessionManager, apiConfigs, disabledTools) {
    const registerTool = createToolRegistrar(server, disabledTools);
    const dbConfigs = apiConfigs.filter(c => c.category === 'database');
    // List all available APIs (excludes services where all tools are disabled)
    registerTool('list_apis', 'List Available APIs', 'List the database and file services in this connection with their category and tool-name prefix. Lighter than discover_services, which also reports the HTTP verbs your role permits on each service; call that when you need to know what you may write.', z.object({}), async () => {
        const apis = apiConfigs
            .filter(api => {
            if (!disabledTools || disabledTools.size === 0)
                return true;
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
    });
    // Cross-service aggregators only pay off (and only cost tokens) when more
    // than one database is in this MCP connection's catalog.
    if (dbConfigs.length < 2) {
        return;
    }
    // Get tables from all databases
    registerTool('all_get_tables', 'Get Tables from All Databases', 'Table names from every connected database in one call, keyed by service name. Use it when you do not know which database holds the data; for one database call get_tables, and for columns and relationships call get_data_model.', z.object({}), async (_args, { sessionId }) => {
        const auth = getAuth(sessionManager, sessionId);
        const results = {};
        await Promise.all(dbConfigs.map(async (api) => {
            try {
                const tables = await DreamFactoryService.getTables(api.baseUrl, auth);
                results[api.name] = { success: true, data: tables };
            }
            catch (error) {
                const message = error instanceof Error ? error.message : String(error);
                results[api.name] = { success: false, error: message };
            }
        }));
        return respond({ databases: results });
    });
    // Get schema for a table across all databases (finds where it exists)
    registerTool('all_find_table', 'Find Table Across All Databases', 'Look up one table name in every connected database and return its full schema from each database that has it. Use it when a table could live in several services. Returns foundIn (the services that have it) plus per-service schema or error.', z.object({
        table_name: z.string().describe('Exact table name to look for.')
    }), async ({ table_name }, { sessionId }) => {
        const auth = getAuth(sessionManager, sessionId);
        const results = {};
        await Promise.all(dbConfigs.map(async (api) => {
            try {
                const schema = await DreamFactoryService.getTableSchema(table_name, api.baseUrl, auth);
                results[api.name] = { found: true, schema };
            }
            catch (error) {
                const message = error instanceof Error ? error.message : String(error);
                if (message.includes('404') || message.includes('not found')) {
                    results[api.name] = { found: false };
                }
                else {
                    results[api.name] = { found: false, error: message };
                }
            }
        }));
        const foundIn = Object.entries(results)
            .filter(([, v]) => v.found)
            .map(([k]) => k);
        return respond({ table_name, foundIn, details: results });
    });
    // Get stored procedures from all databases
    registerTool('all_get_stored_procedures', 'Get Stored Procedures from All Databases', 'Stored procedure names and parameters from every connected database, keyed by service; empty for services without procedures. For a single database call get_stored_procedures.', z.object({}), async (_args, { sessionId }) => {
        const auth = getAuth(sessionManager, sessionId);
        const results = {};
        await Promise.all(dbConfigs.map(async (api) => {
            try {
                const procedures = await DreamFactoryService.getStoredProcedures(api.baseUrl, auth);
                results[api.name] = { success: true, data: procedures };
            }
            catch (error) {
                const message = error instanceof Error ? error.message : String(error);
                results[api.name] = { success: false, error: message };
            }
        }));
        return respond({ databases: results });
    });
    // Get stored functions from all databases
    registerTool('all_get_stored_functions', 'Get Stored Functions from All Databases', 'Stored function names and parameters from every connected database, keyed by service; empty for services without functions. For a single database call get_stored_functions.', z.object({}), async (_args, { sessionId }) => {
        const auth = getAuth(sessionManager, sessionId);
        const results = {};
        await Promise.all(dbConfigs.map(async (api) => {
            try {
                const functions = await DreamFactoryService.getStoredFunctions(api.baseUrl, auth);
                results[api.name] = { success: true, data: functions };
            }
            catch (error) {
                const message = error instanceof Error ? error.message : String(error);
                results[api.name] = { success: false, error: message };
            }
        }));
        return respond({ databases: results });
    });
    // Get database resources from all databases
    registerTool('all_get_resources', 'Get Resources from All Databases', 'Resource groups (_table, _schema, _proc, _func) exposed by every connected database, keyed by service. Use it only to compare what each service offers; all_get_tables is the lighter call when you just need tables.', z.object({}), async (_args, { sessionId }) => {
        const auth = getAuth(sessionManager, sessionId);
        const results = {};
        await Promise.all(dbConfigs.map(async (api) => {
            try {
                const resources = await DreamFactoryService.getDatabaseResources(api.baseUrl, auth, {});
                results[api.name] = { success: true, data: resources };
            }
            catch (error) {
                const message = error instanceof Error ? error.message : String(error);
                results[api.name] = { success: false, error: message };
            }
        }));
        return respond({ databases: results });
    });
}

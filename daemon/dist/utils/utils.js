import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { registerDreamFactoryTools } from '../services/tools.service.js';
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
    const configHeader = req.header('X-Mcp-Config');
    const baseUrl = req.header('X-Mcp-Base-Url');
    if (!configHeader) {
        throw new Error('X-Mcp-Config header required for initialization requests');
    }
    if (!baseUrl) {
        throw new Error('X-Mcp-Base-Url header required for initialization requests');
    }
    const configObject = resolveConfig(JSON.parse(configHeader));
    const apiName = extractApiName(configObject);
    if (!apiName) {
        throw new Error('api_name is required for MCP service');
    }
    return {
        apiName,
        baseUrl
    };
}
function resolveConfig(payload) {
    if (!isRecord(payload)) {
        throw new Error('X-Mcp-Config header must be a JSON object');
    }
    const nested = payload.config;
    if (isRecord(nested)) {
        return nested;
    }
    return payload;
}
function extractApiName(config) {
    return extractString(config?.api_name, config?.apiName);
}
function extractString(...values) {
    for (const value of values) {
        if (typeof value === 'string') {
            const trimmed = value.trim();
            if (trimmed.length > 0) {
                return trimmed;
            }
        }
    }
    return undefined;
}
function isRecord(value) {
    return typeof value === 'object' && value !== null;
}
/**
 * Extract the DreamFactory root API URL from a service-specific URL.
 * e.g., "http://localhost/api/v2/db" -> "http://localhost/api/v2"
 */
function getDreamFactoryRootUrl(serviceUrl) {
    console.log('[discoverDatabaseServices] Input serviceUrl:', serviceUrl);
    const url = new URL(serviceUrl);
    const pathParts = url.pathname.split('/').filter(Boolean);
    console.log('[discoverDatabaseServices] Path parts:', pathParts);
    // Remove the last segment (service name) to get the root API path
    if (pathParts.length > 0) {
        pathParts.pop();
    }
    url.pathname = '/' + pathParts.join('/');
    const rootUrl = url.toString().replace(/\/$/, '');
    console.log('[discoverDatabaseServices] Computed rootUrl:', rootUrl);
    return rootUrl;
}
/**
 * Discover all supported services from DreamFactory and build API configs
 */
export async function discoverServices(serviceBaseUrl, auth) {
    console.log('[discoverServices] Starting discovery...');
    console.log('[discoverServices] serviceBaseUrl:', serviceBaseUrl);
    console.log('[discoverServices] auth.sessionToken:', auth.sessionToken ? `${auth.sessionToken.substring(0, 10)}...` : 'MISSING');
    console.log('[discoverServices] auth.apiKey:', auth.apiKey ? `${auth.apiKey.substring(0, 10)}...` : 'not provided');
    const rootUrl = getDreamFactoryRootUrl(serviceBaseUrl);
    console.log('[discoverServices] Root URL:', rootUrl);
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
/**
 * @deprecated Use discoverServices instead
 */
export async function discoverDatabaseServices(serviceBaseUrl, auth) {
    const all = await discoverServices(serviceBaseUrl, auth);
    return all.filter(c => c.category === 'database');
}
export function createServer(serviceName, apiConfigs, sessionManager) {
    const dbApis = apiConfigs.filter(c => c.category === 'database').map(c => c.name);
    const fileApis = apiConfigs.filter(c => c.category === 'file').map(c => c.name);
    const instructions = [
        `You are connected to the DreamFactory service "${serviceName}".`,
        dbApis.length > 0 ? `Available database APIs: ${dbApis.join(', ')}` : '',
        fileApis.length > 0 ? `Available file storage APIs: ${fileApis.join(', ')}` : '',
        '',
        'Use the available tools to interact with databases and file storage.',
        'Database tools: inspect schemas, fetch data, call stored procedures/functions.',
        'File tools: list files, get content, create folders, delete files.',
        '',
        'All tools are prefixed with the API name (e.g., db_get_tables, files_list_files).',
        'Use the list_apis tool to see all available APIs.',
        'All tools operate against the DreamFactory REST API using the authenticated user session.'
    ].filter(Boolean).join('\n');
    const server = new McpServer({
        name: `DreamFactory MCP (${serviceName})`,
        version: packageJson?.version ?? 'dev'
    }, {
        instructions
    });
    registerDreamFactoryTools(server, sessionManager, apiConfigs);
    return server;
}

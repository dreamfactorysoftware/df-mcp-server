import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { registerDreamFactoryTools } from '../services/tools.service.js';
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
    const configHeader = req.header('X-Mcp-Config');
    const baseUrl = req.header('X-Mcp-Base-Url');
    const sessionToken = req.header('X-DreamFactory-Session-Token');
    if (!configHeader || !baseUrl) {
        return;
    }
    try {
        const parsed = JSON.parse(configHeader);
        const apiKey = extractApiKey(parsed);
        if (apiKey || sessionToken) {
            sessionManager.setConfig(sessionId, { url: baseUrl, apiKey: apiKey ?? '', sessionToken });
        }
    }
    catch (error) {
        console.warn('Failed to parse X-Mcp-Config header:', error);
    }
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
    const apiKey = extractApiKey(configObject);
    const apiName = extractApiName(configObject);
    if (!apiKey) {
        throw new Error('API key is required for MCP service');
    }
    if (!apiName) {
        throw new Error('api_name is required for MCP service');
    }
    return {
        apiKey,
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
function extractApiKey(config) {
    return extractString(config?.api_key ?? config?.apiKey);
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
export function createServer(serviceName, baseUrl, sessionManager) {
    const instructions = [
        `You are connected to the DreamFactory service "${serviceName}".`,
        `Base URL: ${baseUrl}`,
        '',
        'Use the available tools to inspect schemas, fetch data, and call stored procedures/functions.',
        'All tools operate against the DreamFactory REST API using the API key supplied when this session was initialized.'
    ].join('\n');
    const server = new McpServer({
        name: `DreamFactory MCP (${serviceName})`,
        version: packageJson?.version ?? 'dev'
    }, {
        instructions
    });
    registerDreamFactoryTools(server, sessionManager);
    return server;
}

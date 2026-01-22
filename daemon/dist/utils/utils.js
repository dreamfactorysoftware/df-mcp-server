import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { registerDreamFactoryTools } from '../services/tools.service.js';
import packageJson from '../../package.json' with { type: 'json' };
/**
 * Extract and normalize a header value.
 * Returns undefined for missing, empty, or whitespace-only values.
 */
function normalizeHeader(value) {
    if (!value)
        return undefined;
    const trimmed = value.trim();
    return trimmed.length > 0 ? trimmed : undefined;
}
export function getSessionId(req) {
    const header = req.headers['mcp-session-id'];
    if (!header) {
        console.debug('No MCP session ID in request headers');
        return undefined;
    }
    const sessionId = Array.isArray(header) ? header[0] : header;
    console.debug(`MCP session ID: ${sessionId}`);
    return sessionId;
}
/**
 * Update session configuration from request headers.
 *
 * This function merges new auth credentials with existing ones to prevent
 * accidentally losing credentials when only partial auth info is sent.
 *
 * @returns true if config was updated, false if validation failed
 */
export function updateSessionConfigFromHeaders(req, sessionManager, sessionId) {
    if (!sessionId) {
        console.debug('updateSessionConfig: No session ID provided, skipping');
        return false;
    }
    const baseUrl = normalizeHeader(req.header('X-Mcp-Base-Url'));
    const sessionToken = normalizeHeader(req.header('X-DreamFactory-Session-Token'));
    const apiKey = normalizeHeader(req.header('X-DreamFactory-API-Key'));
    // Validate: baseUrl is required
    if (!baseUrl) {
        console.warn(`Session ${sessionId}: Cannot update config - missing X-Mcp-Base-Url header`);
        return false;
    }
    // Validate: at least one auth method required
    if (!sessionToken && !apiKey) {
        console.warn(`Session ${sessionId}: Cannot update config - no auth credentials provided`);
        return false;
    }
    // Merge with existing config to prevent losing credentials
    const existingConfig = sessionManager.getConfig(sessionId);
    const mergedConfig = {
        url: baseUrl,
        sessionToken: sessionToken ?? existingConfig?.sessionToken,
        apiKey: apiKey ?? existingConfig?.apiKey
    };
    sessionManager.setConfig(sessionId, mergedConfig);
    console.debug(`Session ${sessionId}: Config updated (sessionToken: ${!!mergedConfig.sessionToken}, apiKey: ${!!mergedConfig.apiKey})`);
    return true;
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
export function createServer(serviceName, baseUrl, sessionManager) {
    const instructions = [
        `You are connected to the DreamFactory service "${serviceName}".`,
        `Base URL: ${baseUrl}`,
        '',
        'Use the available tools to inspect schemas, fetch data, and call stored procedures/functions.',
        'All tools operate against the DreamFactory REST API using the authenticated user session.'
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

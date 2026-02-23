import express from 'express';
import cors from 'cors';
import { randomUUID } from 'node:crypto';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { SessionService } from './services/session.service.js';
import { createServer, getSessionId, parseConfigFromHeaders, updateSessionConfigFromHeaders, discoverServices } from './utils/utils.js';
const app = express();
const PORT = Number(process.env.MCP_DAEMON_PORT ?? 8006);
const HOST = process.env.MCP_DAEMON_HOST ?? '127.0.0.1';
app.use(cors());
app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true }));
/**
 * Send 401 Unauthorized response
 */
function sendUnauthorized(res) {
    res.status(401).json({
        jsonrpc: '2.0',
        id: null,
        error: {
            code: -32001,
            message: 'Unauthorized: DreamFactory session token required',
        },
    });
}
const sessionManager = new SessionService();
const sessions = new Map();
// Health check endpoints
app.get('/health', (_req, res) => {
    res.json({
        status: 'ok',
        timestamp: Math.floor(Date.now() / 1000),
        sessions: Array.from(sessions.keys())
    });
});
app.get('/ping', (_req, res) => {
    res.json({
        status: 'ok',
        timestamp: Math.floor(Date.now() / 1000),
        sessions: Array.from(sessions.keys())
    });
});
// Cache management endpoint
app.post('/mcp/cache/clear', (req, res) => {
    const service = typeof req.body === 'object' ? req.body?.service : undefined;
    if (service) {
        for (const [sessionId, entry] of sessions.entries()) {
            if (entry.serviceName === service) {
                entry.transport.close().catch(() => undefined);
                sessionManager.clearConfig(sessionId);
                sessions.delete(sessionId);
            }
        }
        res.json({ message: `Cache cleared for service: ${service}` });
    }
    else {
        for (const [sessionId, entry] of sessions.entries()) {
            entry.transport.close().catch(() => undefined);
            sessionManager.clearConfig(sessionId);
        }
        sessions.clear();
        res.json({ message: 'All cache cleared' });
    }
});
// ============================================================================
// MCP Protocol Endpoint - Requires DreamFactory session token from PHP
// ============================================================================
app.all('/mcp/:serviceName', async (req, res) => {
    const { serviceName } = req.params;
    const sessionIdHeader = getSessionId(req);
    const existingSession = sessionIdHeader ? sessions.get(sessionIdHeader) : undefined;
    // Get DreamFactory session token from header (passed by PHP after OAuth validation)
    const dfSessionToken = req.headers['x-dreamfactory-session-token'];
    // Get API key (required for non-admin users)
    const dfApiKey = req.headers['x-dreamfactory-api-key'];
    if (!dfSessionToken) {
        return sendUnauthorized(res);
    }
    try {
        if (existingSession) {
            updateSessionConfigFromHeaders(req, sessionManager, sessionIdHeader);
            await existingSession.transport.handleRequest(req, res, req.body);
            return;
        }
        if (req.method !== 'POST') {
            res.status(400).json({
                jsonrpc: '2.0',
                error: {
                    code: -32000,
                    message: 'Bad Request: Session not found and no initialization payload provided'
                },
                id: null
            });
            return;
        }
        // Build config from headers
        const config = parseConfigFromHeaders(req);
        // Discover all services from DreamFactory (databases + files)
        const apiConfigs = await discoverServices(config.baseUrl, {
            sessionToken: dfSessionToken,
            apiKey: dfApiKey
        });
        if (apiConfigs.length === 0) {
            res.status(400).json({
                jsonrpc: '2.0',
                error: {
                    code: -32000,
                    message: 'No supported services found in DreamFactory'
                },
                id: null
            });
            return;
        }
        const dbCount = apiConfigs.filter(c => c.category === 'database').length;
        const fileCount = apiConfigs.filter(c => c.category === 'file').length;
        console.log(`Discovered ${apiConfigs.length} service(s): ${dbCount} database, ${fileCount} file`);
        console.log('Services:', apiConfigs.map(a => `${a.name} (${a.category})`).join(', '));
        // Parse disabled tools from service config
        let disabledTools;
        const mcpConfigHeader = req.headers['x-mcp-config'];
        if (mcpConfigHeader) {
            try {
                const mcpConfig = JSON.parse(mcpConfigHeader);
                if (Array.isArray(mcpConfig.disabled_tools) && mcpConfig.disabled_tools.length > 0) {
                    disabledTools = new Set(mcpConfig.disabled_tools);
                    console.log(`Disabled tools (${disabledTools.size}):`, [...disabledTools]);
                }
            }
            catch { /* ignore parse errors */ }
        }
        const server = createServer(serviceName, apiConfigs, sessionManager, disabledTools);
        const transport = new StreamableHTTPServerTransport({
            sessionIdGenerator: () => {
                const sessionId = randomUUID();
                // Store DF session token, API key, and discovered API configs
                sessionManager.setConfig(sessionId, {
                    url: config.baseUrl,
                    sessionToken: dfSessionToken,
                    apiKey: dfApiKey,
                    apiConfigs
                });
                return sessionId;
            },
            onsessioninitialized: sessionId => {
                sessions.set(sessionId, { server, transport, serviceName, apiConfigs });
            },
            onsessionclosed: sessionId => {
                if (sessionId) {
                    sessions.delete(sessionId);
                    sessionManager.clearConfig(sessionId);
                }
            },
            enableJsonResponse: false
        });
        transport.onclose = () => {
            const sid = transport.sessionId;
            if (sid) {
                sessions.delete(sid);
                sessionManager.clearConfig(sid);
            }
        };
        await server.connect(transport);
        await transport.handleRequest(req, res, req.body);
    }
    catch (error) {
        console.error(`MCP Daemon Error [${serviceName}]:`, error);
        res.status(500).json({
            jsonrpc: '2.0',
            id: null,
            error: {
                code: -32000,
                message: error instanceof Error ? error.message : 'Server error'
            }
        });
    }
});
app.listen(PORT, HOST, () => {
    console.log(`MCP Daemon listening on http://${HOST}:${PORT}`);
    console.log('');
    console.log('Endpoints:');
    console.log(`  GET  /health - Health check`);
    console.log(`  GET  /ping - Ping`);
    console.log(`  POST /mcp/cache/clear - Clear session cache`);
    console.log(`  ALL  /mcp/:serviceName - MCP protocol (requires X-DreamFactory-Session-Token header)`);
});
process.on('SIGINT', async () => {
    console.log('Shutting down MCP daemon...');
    for (const [sessionId, entry] of sessions.entries()) {
        try {
            await entry.transport.close();
            sessionManager.clearConfig(sessionId);
        }
        catch (error) {
            console.error(`Failed to close session ${sessionId}:`, error);
        }
    }
    process.exit(0);
});

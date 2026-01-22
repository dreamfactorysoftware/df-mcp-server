import express from 'express';
import cors from 'cors';
import { randomUUID } from 'node:crypto';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { SessionService } from './services/session.service.js';
import { createServer, getSessionId, parseConfigFromHeaders, updateSessionConfigFromHeaders } from './utils/utils.js';
import { extractAndValidateAuth, getAuthModeDescription } from './utils/auth.utils.js';
const app = express();
const PORT = Number(process.env.MCP_DAEMON_PORT ?? 8006);
const HOST = process.env.MCP_DAEMON_HOST ?? '127.0.0.1';
app.use(cors());
app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true }));
/**
 * Send 401 Unauthorized response with optional custom message
 */
function sendUnauthorized(res, message) {
    res.status(401).json({
        jsonrpc: '2.0',
        id: null,
        error: {
            code: -32001,
            message: message ?? 'Unauthorized: DreamFactory session token or API key required',
        },
    });
}
/**
 * Log authentication mode for a service
 */
function logAuthMode(serviceName, authResult) {
    if (authResult.valid && authResult.mode) {
        console.log(`[${serviceName}] Auth: ${getAuthModeDescription(authResult.mode)}`);
    }
}
const sessionManager = new SessionService();
const sessions = new Map();
// Health check endpoints
app.get('/health', (_req, res) => {
    const sessionStats = sessionManager.getStats();
    res.json({
        status: 'ok',
        timestamp: Math.floor(Date.now() / 1000),
        sessions: {
            active: Array.from(sessions.keys()),
            stats: {
                total: sessionStats.total,
                oldestAgeMs: sessionStats.oldest,
                avgAgeMs: sessionStats.avgAge
            }
        }
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
// MCP Protocol Endpoint
//
// Authentication modes (at least one required):
// 1. Session Token (OAuth): User authenticates via OAuth, session token passed by PHP
// 2. API Key Only: App-based auth when app has a role assigned in DreamFactory
// 3. Both: Session token for user identity + API key for app context
//
// When both are provided, DreamFactory uses session token for user identity
// and API key for app-level permissions. See logAuthMode() for details.
// ============================================================================
app.all('/mcp/:serviceName', async (req, res) => {
    const serviceName = req.params.serviceName;
    const sessionIdHeader = getSessionId(req);
    const existingSession = sessionIdHeader ? sessions.get(sessionIdHeader) : undefined;
    // Extract and validate auth using centralized validation
    const authResult = extractAndValidateAuth(req, false); // Set to true for strict format validation
    if (!authResult.valid) {
        console.warn(`[${serviceName}] Auth failed: ${authResult.error}`);
        return sendUnauthorized(res, `Unauthorized: ${authResult.error}`);
    }
    // Log the authentication mode for debugging/auditing
    logAuthMode(serviceName, authResult);
    const dfSessionToken = authResult.credentials?.sessionToken;
    const dfApiKey = authResult.credentials?.apiKey;
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
        const server = createServer(serviceName, config.baseUrl, sessionManager);
        const transport = new StreamableHTTPServerTransport({
            sessionIdGenerator: () => {
                const sessionId = randomUUID();
                // Store DF session token and API key for user authentication
                sessionManager.setConfig(sessionId, {
                    url: config.baseUrl,
                    sessionToken: dfSessionToken,
                    apiKey: dfApiKey
                });
                return sessionId;
            },
            onsessioninitialized: sessionId => {
                sessions.set(sessionId, { server, transport, serviceName });
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
    console.log(`  ALL  /mcp/:serviceName - MCP protocol`);
    console.log('');
    console.log('Authentication (at least one required):');
    console.log('  X-DreamFactory-Session-Token - OAuth session token');
    console.log('  X-DreamFactory-API-Key - API key (app must have role assigned)');
});
process.on('SIGINT', async () => {
    console.log('Shutting down MCP daemon...');
    // Stop the cleanup timer
    sessionManager.stopCleanupTimer();
    // Close all active transports
    for (const [sessionId, entry] of sessions.entries()) {
        try {
            await entry.transport.close();
        }
        catch (error) {
            console.error(`Failed to close session ${sessionId}:`, error);
        }
    }
    // Clear all session configs
    sessionManager.clearAll();
    sessions.clear();
    console.log('MCP daemon shutdown complete');
    process.exit(0);
});

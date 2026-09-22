import express from 'express';
import cors from 'cors';
import { randomUUID } from 'node:crypto';
import { createRequire } from 'node:module';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { SessionService } from './services/session.service.js';
import { runWithTrace } from './services/trace.service.js';
import { lazyStateFor } from './services/lazy.service.js';
import { runWithResponse } from './services/ledger.js';
import { previewCatalog } from './services/catalog-preview.service.js';
import { internalKeyGate } from './services/internal-key.js';
import { functionToolsEnabled } from './services/custom-tools.service.js';
import { createServer, getSessionId, parseConfigFromHeaders, parseMcpConfig, updateSessionConfigFromHeaders, discoverServices } from './utils/utils.js';
import { extractAndValidateAuth, getAuthModeDescription } from './utils/auth.utils.js';
const app = express();
const PORT = Number(process.env.MCP_DAEMON_PORT ?? 8006);
const HOST = process.env.MCP_DAEMON_HOST ?? '127.0.0.1';
// Stateless mode (default): issue no session IDs and keep no session state
// between requests. Every input a session would cache (DreamFactory token, API
// key, resolved apiConfigs) is sent by the PHP proxy on each request, so a
// server is built per request and discarded. This lets any node answer any
// request, which is required behind a load balancer — MCP clients do not
// return affinity cookies. Trade-off: no server-initiated SSE stream (GET
// returns 405). MCP_STATELESS=false opts back into warm, process-pinned
// sessions for single-node installs.
const STATELESS = !['false', '0', 'no', 'off'].includes((process.env.MCP_STATELESS ?? 'true').trim().toLowerCase());
// Reported by /health so DreamFactory's MCP health check can show it.
// ../package.json resolves from both src/ (tsx) and dist/ (tsc).
const VERSION = createRequire(import.meta.url)('../package.json').version;
// MCP clients (Claude Desktop, etc.) are external — CORS must be permissive.
// The daemon is already protected by requiring a DreamFactory session token.
app.use(cors());
app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true }));
// Carry the platform trace id (minted by DF PHP) through every async
// continuation of this request so DF REST sub-calls can re-attach it.
app.use((req, res, next) => {
    runWithResponse(res, () => runWithTrace(req.header('x-dreamfactory-trace-id'), next));
});
// Every /mcp route (the MCP endpoint, catalog preview, cache clear) requires the
// shared secret the PHP proxy sends after RBAC, so no other local process can
// call the daemon directly with a stolen or self-issued session token. Fails
// closed. /health and /ping stay open for the admin health check.
app.use('/mcp', internalKeyGate);
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
// Health check endpoints — do not expose session IDs
app.get('/health', (_req, res) => {
    res.json({
        status: 'ok',
        version: VERSION,
        timestamp: Math.floor(Date.now() / 1000),
        mode: STATELESS ? 'stateless' : 'stateful',
        active_sessions: sessions.size,
        function_tools: functionToolsEnabled(),
    });
});
app.get('/ping', (_req, res) => {
    res.json({
        status: 'ok',
        timestamp: Math.floor(Date.now() / 1000),
        mode: STATELESS ? 'stateless' : 'stateful',
        active_sessions: sessions.size,
    });
});
// Cache management endpoint (internal-key gated above)
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
// Catalog preview (issue #64): what tools/list would advertise for a given
// service config + PHP-scoped backend catalog + client name, computed in a
// throwaway server. Nothing is executed: no DreamFactory calls, no MCP
// session, no audit row. Internal-key gated like every /mcp route.
app.post('/mcp/catalog/preview', async (req, res) => {
    try {
        const result = await previewCatalog(req.body && typeof req.body === 'object' ? req.body : {});
        // tools/list attaches the savings ledger to the current response; this is not a proxied MCP call.
        res.removeHeader('X-Mcp-Ledger');
        res.json(result);
    }
    catch (error) {
        console.error('[catalog/preview] failed:', error);
        res.status(500).json({ error: error instanceof Error ? error.message : 'Server error' });
    }
});
// ============================================================================
// MCP Protocol Endpoint - Requires DreamFactory session token from PHP
// ============================================================================
app.all('/mcp/:serviceName', async (req, res) => {
    const serviceName = req.params.serviceName;
    const sessionIdHeader = getSessionId(req);
    const existingSession = !STATELESS && sessionIdHeader ? sessions.get(sessionIdHeader) : undefined;
    // Authentication modes (at least one credential required; the PHP proxy has
    // already authenticated the caller and forwards the matching credentials):
    // 1. Session token (OAuth): user authenticated via OAuth, JWT passed by PHP
    // 2. API key only: app-based auth — the key's app has a role assigned
    // 3. Both: session token for user identity + API key for app context
    // Format strictness stays off here: the PHP proxy already enforces the
    // 64-hex key format on client-supplied keys, and OAuth-config app keys are
    // trusted values looked up server-side.
    const authResult = extractAndValidateAuth(req, false);
    if (!authResult.valid) {
        console.warn(`[${serviceName}] Auth failed: ${authResult.error}`);
        return sendUnauthorized(res, `Unauthorized: ${authResult.error}`);
    }
    // Log the authentication mode for debugging/auditing
    logAuthMode(serviceName, authResult);
    const dfSessionToken = authResult.credentials?.sessionToken;
    const dfApiKey = authResult.credentials?.apiKey;
    // Extract config and MCP payload from body envelope (POST) or headers (GET/DELETE).
    // The PHP proxy wraps the original MCP JSON-RPC payload + DreamFactory config into
    // a single JSON body to avoid exceeding Node.js's 16 KB HTTP header limit.
    let mcpPayload = req.body;
    let mcpConfig;
    let availableServicesFromBody;
    if (req.method === 'POST' && req.body?._mcpPayload !== undefined) {
        // Unwrap the envelope created by McpDaemonClient
        mcpPayload = req.body._mcpPayload;
        mcpConfig = req.body._mcpConfig ?? undefined;
        availableServicesFromBody = Array.isArray(req.body._mcpAvailableServices)
            ? req.body._mcpAvailableServices
            : undefined;
        // Replace req.body with the original MCP payload so the transport sees it
        req.body = mcpPayload;
    }
    try {
        if (existingSession) {
            existingSession.lastAccess = Date.now();
            // Evict stale SSE stream on GET reconnect to prevent 409 "Only one SSE stream"
            // The previous stream may be orphaned if the client disconnected uncleanly
            if (req.method === 'GET') {
                existingSession.transport.closeStandaloneSSEStream?.();
            }
            updateSessionConfigFromHeaders(req, sessionManager, sessionIdHeader);
            await existingSession.transport.handleRequest(req, res, req.body);
            return;
        }
        if (req.method !== 'POST') {
            if (STATELESS) {
                // No sessions exist to resume or terminate, so the standalone SSE
                // stream (GET) and session teardown (DELETE) do not apply. The spec
                // allows servers to decline them; clients fall back to POST-only.
                res.status(405).set('Allow', 'POST').json({
                    jsonrpc: '2.0',
                    error: {
                        code: -32000,
                        message: 'Method Not Allowed: daemon is in stateless mode (POST only)'
                    },
                    id: null
                });
                return;
            }
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
        // Prefers pre-resolved services from PHP (body or header) to avoid system/service permission requirement
        const INIT_TIMEOUT_MS = 30_000;
        let apiConfigs;
        try {
            apiConfigs = await Promise.race([
                discoverServices(config.baseUrl, {
                    sessionToken: dfSessionToken,
                    apiKey: dfApiKey
                }, req, availableServicesFromBody),
                new Promise((_, reject) => setTimeout(() => reject(new Error('Service discovery timed out')), INIT_TIMEOUT_MS))
            ]);
        }
        catch (discoveryError) {
            console.error(`[${serviceName}] Service discovery failed:`, discoveryError instanceof Error ? discoveryError.message : discoveryError);
            res.status(504).json({
                jsonrpc: '2.0',
                error: {
                    code: -32000,
                    message: `Service discovery failed: ${discoveryError instanceof Error ? discoveryError.message : 'unknown error'}`
                },
                id: null
            });
            return;
        }
        // Parse disabled tools and custom tools from service config (body envelope or header fallback)
        // This must happen before the service check so custom-tools-only roles are not rejected.
        const mcpConfigData = mcpConfig ?? (() => {
            const header = req.headers['x-mcp-config'];
            if (!header)
                return undefined;
            try {
                return JSON.parse(header);
            }
            catch (e) {
                console.warn('[config] Failed to parse X-Mcp-Config header:', e instanceof Error ? e.message : e);
                return undefined;
            }
        })();
        const { disabledTools, customTools, lazyMode, toolStyle, allowWrites } = parseMcpConfig(mcpConfigData);
        const hasCustomTools = customTools !== undefined && customTools.length > 0;
        const catalogFromPhp = Array.isArray(availableServicesFromBody)
            || Boolean(req.header('X-Mcp-Available-Services'));
        if (apiConfigs.length === 0 && !hasCustomTools && !catalogFromPhp) {
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
        console.log(`Discovered ${apiConfigs.length} service(s): ${dbCount} database, ${fileCount} file${hasCustomTools ? `, ${customTools.length} custom tool(s)` : ''}`);
        if (apiConfigs.length > 0) {
            console.log('Services:', apiConfigs.map(a => `${a.name} (${a.category})`).join(', '));
        }
        if (STATELESS) {
            // Scope the config to this request only. sessionIdGenerator: undefined
            // makes the SDK skip session validation entirely, so a fresh transport
            // may serve any request without having seen the initialize handshake.
            const requestSessions = new SessionService();
            requestSessions.setDefaultConfig({
                url: config.baseUrl,
                sessionToken: dfSessionToken,
                apiKey: dfApiKey,
                apiConfigs
            });
            const statelessServer = createServer(serviceName, apiConfigs, requestSessions, disabledTools, customTools, lazyMode, toolStyle, allowWrites);
            // No session remembers that the client already listed tools or who the
            // client is, so decide lazy behaviour per request from the catalog and
            // the client name PHP forwards (X-Mcp-Client-Name).
            const lazy = lazyStateFor(statelessServer);
            if (lazy) {
                lazy.clientHint = req.header('x-mcp-client-name');
                await lazy.prime();
            }
            const statelessTransport = new StreamableHTTPServerTransport({
                sessionIdGenerator: undefined,
                enableJsonResponse: true
            });
            res.on('close', () => {
                void Promise.resolve(statelessTransport.close()).catch(() => undefined);
                void Promise.resolve(statelessServer.close()).catch(() => undefined);
            });
            await statelessServer.connect(statelessTransport);
            await statelessTransport.handleRequest(req, res, req.body);
            return;
        }
        const server = createServer(serviceName, apiConfigs, sessionManager, disabledTools, customTools, lazyMode, toolStyle, allowWrites);
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
                sessions.set(sessionId, { server, transport, serviceName, apiConfigs, lastAccess: Date.now() });
            },
            onsessionclosed: sessionId => {
                if (sessionId) {
                    sessions.delete(sessionId);
                    sessionManager.clearConfig(sessionId);
                }
            },
            enableJsonResponse: true
        });
        // NOTE: do NOT tear the session down here. With enableJsonResponse the
        // transport's per-request stream closes right after each JSON POST — if we
        // deleted the session on that close, a first-party caller that does
        // initialize and tools/list as separate POSTs would lose its session
        // between them ("Server not initialized"). Sessions are instead reaped by
        // idle TTL (see reaper below) and by explicit DELETE (onsessionclosed).
        transport.onclose = () => {
            // intentionally no-op — idle reaper owns cleanup
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
// Reap MCP sessions that have been idle past the TTL. Sessions are now kept
// alive across separate JSON POSTs (so first-party stateless callers can do
// initialize then tools/list), so this reaper — plus explicit DELETE — is what
// bounds session lifetime.
const SESSION_IDLE_TTL_MS = 10 * 60 * 1000;
setInterval(() => {
    const now = Date.now();
    for (const [sid, entry] of sessions.entries()) {
        if (now - entry.lastAccess > SESSION_IDLE_TTL_MS) {
            try {
                entry.transport.close?.();
            }
            catch { /* already closed */ }
            sessions.delete(sid);
            sessionManager.clearConfig(sid);
        }
    }
}, 2 * 60 * 1000).unref?.();
const listener = app.listen(PORT, HOST, () => {
    const addr = listener.address();
    const port = addr && typeof addr === 'object' ? addr.port : PORT;
    console.log(`MCP Daemon listening on http://${HOST}:${port}`);
    console.log(`Session mode: ${STATELESS ? 'stateless (no session IDs; load-balancer safe)' : 'stateful (sessions pinned to this process)'}`);
    console.log('');
    console.log('Endpoints:');
    console.log(`  GET  /health - Health check`);
    console.log(`  GET  /ping - Ping`);
    console.log(`  POST /mcp/cache/clear - Clear session cache`);
    console.log(`  POST /mcp/catalog/preview - tools/list preview for a config + scoped catalog (no session)`);
    console.log(`  ALL  /mcp/:serviceName - MCP protocol`);
    console.log('');
    console.log('Authentication (at least one required):');
    console.log('  X-DreamFactory-Session-Token - OAuth session token');
    console.log('  X-DreamFactory-API-Key - API key (app must have role assigned)');
});
async function gracefulShutdown(signal) {
    console.log(`${signal} received, shutting down MCP daemon...`);
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
}
process.on('SIGINT', () => gracefulShutdown('SIGINT'));
process.on('SIGTERM', () => gracefulShutdown('SIGTERM'));
process.on('uncaughtException', (err) => {
    console.error('Uncaught exception (keeping process alive):', err);
});
process.on('unhandledRejection', (reason) => {
    console.error('Unhandled rejection (keeping process alive):', reason);
});

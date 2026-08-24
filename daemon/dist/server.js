import express from 'express';
import cors from 'cors';
import { randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { SessionService } from './services/session.service.js';
import { runWithTrace } from './services/trace.service.js';
import { createServer, getSessionId, parseConfigFromHeaders, updateSessionConfigFromHeaders, discoverServices } from './utils/utils.js';
const app = express();
const PORT = Number(process.env.MCP_DAEMON_PORT ?? 8006);
const HOST = process.env.MCP_DAEMON_HOST ?? '127.0.0.1';
// Stateless mode: issue no session IDs and keep no session state between
// requests. Every input a session would cache (DreamFactory token, API key,
// resolved apiConfigs) is sent by the PHP proxy on each request, so a server is
// built per request and discarded. This lets any node answer any request, which
// is required behind a load balancer — MCP clients do not return affinity
// cookies. Trade-off: no server-initiated SSE stream (GET returns 405).
const STATELESS = (process.env.MCP_STATELESS ?? '').toLowerCase() === 'true';
// MCP clients (Claude Desktop, etc.) are external — CORS must be permissive.
// The daemon is already protected by requiring a DreamFactory session token.
app.use(cors());
app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true }));
// Carry the platform trace id (minted by DF PHP) through every async
// continuation of this request so DF REST sub-calls can re-attach it.
app.use((req, _res, next) => {
    runWithTrace(req.header('x-dreamfactory-trace-id'), next);
});
// Shared secret for the PHP proxy -> daemon hop. The PHP side generates this
// key and persists it to storage/app/mcp_internal_key on shared storage; the
// daemon reads the same file. This binds the two sides without depending on
// APP_KEY reaching the daemon process environment. An explicit MCP_INTERNAL_KEY
// (or MCP_INTERNAL_KEY_FILE for a non-standard storage path) overrides the
// default file. The key is resolved lazily and cached on first read, so the
// daemon picks it up on the first request even when it boots before the PHP
// side has written the file.
function resolveInternalKeyFile() {
    if (process.env.MCP_INTERNAL_KEY_FILE) {
        return process.env.MCP_INTERNAL_KEY_FILE;
    }
    // dist/server.js lives at vendor/dreamfactory/df-mcp-server/daemon/dist,
    // five levels below the DreamFactory app root that owns storage/.
    const here = dirname(fileURLToPath(import.meta.url));
    return resolve(here, '../../../../../storage/app/mcp_internal_key');
}
let cachedInternalKey = '';
function getInternalKey() {
    const explicit = process.env.MCP_INTERNAL_KEY;
    if (explicit) {
        return explicit;
    }
    if (cachedInternalKey) {
        return cachedInternalKey;
    }
    try {
        const key = readFileSync(resolveInternalKeyFile(), 'utf8').trim();
        if (key) {
            cachedInternalKey = key;
            return key;
        }
    }
    catch {
        // Key file not present yet — the PHP side writes it on its first request.
    }
    return '';
}
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
// Health check endpoints — do not expose session IDs
app.get('/health', (_req, res) => {
    res.json({
        status: 'ok',
        timestamp: Math.floor(Date.now() / 1000),
        mode: STATELESS ? 'stateless' : 'stateful',
        active_sessions: sessions.size,
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
// Cache management endpoint — requires internal API key from PHP proxy
app.post('/mcp/cache/clear', (req, res) => {
    const internalKey = getInternalKey();
    if (!internalKey || req.headers['x-mcp-internal-key'] !== internalKey) {
        return res.status(403).json({ error: 'Forbidden: invalid internal key' });
    }
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
    // Shared-secret check: the PHP proxy injects this header after RBAC. The
    // daemon reads the same shared secret the PHP side generated and fails
    // closed — a request with no matching key is rejected. This stops any other
    // local process on 127.0.0.1 from calling the daemon directly with a valid
    // session token and bypassing PHP-side RBAC. The key is resolved lazily, so
    // the legitimate proxy is never rejected once the shared key exists.
    const internalKey = getInternalKey();
    if (!internalKey || req.headers['x-mcp-internal-key'] !== internalKey) {
        return res.status(403).json({
            jsonrpc: '2.0',
            id: null,
            error: {
                code: -32001,
                message: 'Forbidden: invalid internal key',
            },
        });
    }
    const serviceName = req.params.serviceName;
    const sessionIdHeader = getSessionId(req);
    const existingSession = !STATELESS && sessionIdHeader ? sessions.get(sessionIdHeader) : undefined;
    // Get DreamFactory session token from header (passed by PHP after OAuth validation)
    const dfSessionToken = req.headers['x-dreamfactory-session-token'];
    // Get API key (required for non-admin users)
    const dfApiKey = req.headers['x-dreamfactory-api-key'];
    if (!dfSessionToken) {
        return sendUnauthorized(res);
    }
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
        let disabledTools;
        let customTools;
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
        if (mcpConfigData) {
            if (Array.isArray(mcpConfigData.disabled_tools) && mcpConfigData.disabled_tools.length > 0) {
                disabledTools = new Set(mcpConfigData.disabled_tools);
                console.log(`Disabled tools (${disabledTools.size}):`, [...disabledTools]);
            }
            if (Array.isArray(mcpConfigData.custom_tools) && mcpConfigData.custom_tools.length > 0) {
                customTools = mcpConfigData.custom_tools
                    .filter((t) => t.enabled !== false && t.enabled !== 0)
                    .map((t) => ({
                    name: t.name,
                    description: t.description ?? '',
                    tool_type: t.tool_type ?? 'api',
                    http_method: t.http_method ?? undefined,
                    url: t.url ?? undefined,
                    parameters: Array.isArray(t.parameters) ? t.parameters : [],
                    headers: t.headers && typeof t.headers === 'object' && !Array.isArray(t.headers) ? t.headers : {},
                    function: t.function ?? undefined,
                    secrets: t.secrets && typeof t.secrets === 'object' && !Array.isArray(t.secrets) ? t.secrets : undefined,
                }));
                if (customTools.length > 0) {
                    console.log(`Custom tools (${customTools.length}):`, customTools.map(t => t.name));
                }
            }
        }
        const hasCustomTools = customTools !== undefined && customTools.length > 0;
        if (apiConfigs.length === 0 && !hasCustomTools) {
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
            const statelessServer = createServer(serviceName, apiConfigs, requestSessions, disabledTools, customTools);
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
        const server = createServer(serviceName, apiConfigs, sessionManager, disabledTools, customTools);
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
app.listen(PORT, HOST, () => {
    console.log(`MCP Daemon listening on http://${HOST}:${PORT}`);
    console.log(`Session mode: ${STATELESS ? 'stateless (no session IDs; load-balancer safe)' : 'stateful (sessions pinned to this process)'}`);
    console.log('');
    console.log('Endpoints:');
    console.log(`  GET  /health - Health check`);
    console.log(`  GET  /ping - Ping`);
    console.log(`  POST /mcp/cache/clear - Clear session cache`);
    console.log(`  ALL  /mcp/:serviceName - MCP protocol (requires X-DreamFactory-Session-Token header)`);
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

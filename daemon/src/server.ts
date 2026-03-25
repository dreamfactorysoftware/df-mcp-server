import express, { Request, Response } from 'express';
import cors from 'cors';
import { randomUUID } from 'node:crypto';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { SessionService } from './services/session.service.js';
import {
  createServer,
  getSessionId,
  parseConfigFromHeaders,
  updateSessionConfigFromHeaders,
  discoverServices,
  type ApiConfig
} from './utils/utils.js';
import type { CustomToolDefinition } from './types.js';

type SessionEntry = {
  server: McpServer;
  transport: StreamableHTTPServerTransport;
  serviceName: string;
  apiConfigs: ApiConfig[];
};

const app = express();
const PORT = Number(process.env.MCP_DAEMON_PORT ?? 8006);
const HOST = process.env.MCP_DAEMON_HOST ?? '127.0.0.1';

app.use(cors());
app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true }));

/**
 * Send 401 Unauthorized response
 */
function sendUnauthorized(res: Response): void {
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
const sessions = new Map<string, SessionEntry>();

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
  } else {
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

app.all('/mcp/:serviceName', async (req: Request, res: Response) => {
  const serviceName = req.params.serviceName as string;
  const sessionIdHeader = getSessionId(req);
  const existingSession = sessionIdHeader ? sessions.get(sessionIdHeader) : undefined;

  // Get DreamFactory session token from header (passed by PHP after OAuth validation)
  const dfSessionToken = req.headers['x-dreamfactory-session-token'] as string | undefined;
  // Get API key (required for non-admin users)
  const dfApiKey = req.headers['x-dreamfactory-api-key'] as string | undefined;

  if (!dfSessionToken) {
    return sendUnauthorized(res);
  }

  // Extract config and MCP payload from body envelope (POST) or headers (GET/DELETE).
  // The PHP proxy wraps the original MCP JSON-RPC payload + DreamFactory config into
  // a single JSON body to avoid exceeding Node.js's 16 KB HTTP header limit.
  let mcpPayload = req.body;
  let mcpConfig: Record<string, unknown> | undefined;
  let availableServicesFromBody: unknown[] | undefined;

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
    // Prefers pre-resolved services from PHP (body or header) to avoid system/service permission requirement
    const apiConfigs = await discoverServices(config.baseUrl, {
      sessionToken: dfSessionToken,
      apiKey: dfApiKey
    }, req, availableServicesFromBody);

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

    // Parse disabled tools and custom tools from service config (body envelope or header fallback)
    let disabledTools: Set<string> | undefined;
    let customTools: CustomToolDefinition[] | undefined;
    const mcpConfigData = mcpConfig ?? (() => {
      const header = req.headers['x-mcp-config'] as string | undefined;
      if (!header) return undefined;
      try {
        return JSON.parse(header);
      } catch (e) {
        console.warn('[config] Failed to parse X-Mcp-Config header:', e instanceof Error ? e.message : e);
        return undefined;
      }
    })();

    if (mcpConfigData) {
      if (Array.isArray(mcpConfigData.disabled_tools) && mcpConfigData.disabled_tools.length > 0) {
        disabledTools = new Set(mcpConfigData.disabled_tools as string[]);
        console.log(`Disabled tools (${disabledTools.size}):`, [...disabledTools]);
      }
      if (Array.isArray(mcpConfigData.custom_tools) && mcpConfigData.custom_tools.length > 0) {
        customTools = (mcpConfigData.custom_tools as any[])
          .filter((t: any) => t.enabled !== false && t.enabled !== 0)
          .map((t: any): CustomToolDefinition => ({
            name: t.name,
            description: t.description ?? '',
            tool_type: t.tool_type ?? 'api',
            http_method: t.http_method ?? undefined,
            url: t.url ?? undefined,
            parameters: Array.isArray(t.parameters) ? t.parameters : [],
            headers: t.headers && typeof t.headers === 'object' && !Array.isArray(t.headers) ? t.headers : {},
            function: t.function ?? undefined,
          }));
        if (customTools.length > 0) {
          console.log(`Custom tools (${customTools.length}):`, customTools.map(t => t.name));
        }
      }
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
  } catch (error) {
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

async function gracefulShutdown(signal: string) {
  console.log(`${signal} received, shutting down MCP daemon...`);
  for (const [sessionId, entry] of sessions.entries()) {
    try {
      await entry.transport.close();
      sessionManager.clearConfig(sessionId);
    } catch (error) {
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

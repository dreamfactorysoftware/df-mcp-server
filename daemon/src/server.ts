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
  updateSessionConfigFromHeaders
} from './utils/utils.js';

type SessionEntry = {
  server: McpServer;
  transport: StreamableHTTPServerTransport;
  serviceName: string;
};

const app = express();
const PORT = Number(process.env.MCP_DAEMON_PORT ?? 8006);
const HOST = process.env.MCP_DAEMON_HOST ?? '127.0.0.1';

app.use(cors());
app.use(express.json({ limit: '10mb' }));

const sessionManager = new SessionService();
const sessions = new Map<string, SessionEntry>();

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

app.all('/mcp/:serviceName', async (req: Request, res: Response) => {
  const { serviceName } = req.params;
  const sessionIdHeader = getSessionId(req);
  const existingSession = sessionIdHeader ? sessions.get(sessionIdHeader) : undefined;

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

    const config = parseConfigFromHeaders(req);
    const server = createServer(serviceName, config.baseUrl, sessionManager);

    const transport = new StreamableHTTPServerTransport({
      sessionIdGenerator: () => {
        const sessionId = randomUUID();
        sessionManager.setConfig(sessionId, { url: config.baseUrl, apiKey: config.apiKey });
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
  console.log(`MCP Streamable HTTP daemon listening on http://${HOST}:${PORT}`);
});

process.on('SIGINT', async () => {
  console.log('Shutting down MCP daemon...');
  for (const [sessionId, entry] of sessions.entries()) {
    try {
      await entry.transport.close();
      sessionManager.clearConfig(sessionId);
    } catch (error) {
      console.error(`Failed to close session ${sessionId}:`, error);
    }
  }
  process.exit(0);
});


import express, { Request, Response } from 'express';
import cors from 'cors';
import { randomUUID } from 'node:crypto';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { SessionService } from './services/session.service.js';
import { DreamFactoryOAuthProvider } from './auth/df-oauth-provider.js';
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
const BASE_URL = 'https://e4598d73a972.ngrok-free.app'; //process.env.MCP_DAEMON_BASE_URL || `http://${HOST}:${PORT}`;
const DF_URL = 'https://e4598d73a972.ngrok-free.app';
const DF_API_KEY = process.env.DF_API_KEY || '';

app.use(cors());
app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true }));

// Initialize OAuth provider
const oauthProvider = new DreamFactoryOAuthProvider({
  dreamFactoryUrl: DF_URL,
  apiKey: DF_API_KEY,
  baseUrl: BASE_URL,
});

/**
 * Send 401 Unauthorized with WWW-Authenticate header pointing to protected resource metadata
 */
function sendUnauthorized(res: Response, serviceName: string): void {
  const resourceMetadataUrl = `${BASE_URL}/mcp/${serviceName}/.well-known/oauth-protected-resource`;
  res.setHeader('WWW-Authenticate', `Bearer realm="mcp", resource_metadata="${resourceMetadataUrl}"`);
  res.status(401).json({
    jsonrpc: '2.0',
    id: null,
    error: {
      code: -32001,
      message: 'Unauthorized: Bearer token required',
    },
  });
}

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

// ============================================================================
// OAuth Discovery Endpoints (RFC 9728 / RFC 8414) - No auth required
// Scoped per-service under /mcp/:serviceName/
// ============================================================================

/**
 * OAuth Protected Resource Metadata (RFC 9728)
 * Client fetches this to discover the authorization server
 */
app.get('/mcp/:serviceName/.well-known/oauth-protected-resource', (req, res) => {
  const { serviceName } = req.params;
  const serviceUrl = `${BASE_URL}/mcp/${serviceName}`;
  res.json({
    resource: serviceUrl,
    authorization_servers: [serviceUrl],
    scopes_supported: ['mcp:read', 'mcp:write'],
  });
});

/**
 * OAuth Authorization Server Metadata (RFC 8414)
 * Client fetches this to discover OAuth endpoints
 */
app.get('/mcp/:serviceName/.well-known/oauth-authorization-server', (req, res) => {
  const { serviceName } = req.params;
  const serviceUrl = `${BASE_URL}/mcp/${serviceName}`;
  res.json({
    issuer: serviceUrl,
    authorization_endpoint: `${serviceUrl}/authorize`,
    token_endpoint: `${serviceUrl}/token`,
    registration_endpoint: `${serviceUrl}/register`,
    response_types_supported: ['code'],
    grant_types_supported: ['authorization_code', 'refresh_token'],
    code_challenge_methods_supported: ['S256'],
    token_endpoint_auth_methods_supported: ['none'],
    scopes_supported: ['mcp:read', 'mcp:write'],
  });
});

// ============================================================================
// OAuth Flow Endpoints - No auth required
// Scoped per-service under /mcp/:serviceName/
// ============================================================================

/**
 * Dynamic Client Registration (RFC 7591)
 */
app.post('/mcp/:serviceName/register', (req, res) => {
  try {
    const client = oauthProvider.clientsStore.registerClient(req.body);
    res.status(201).json(client);
  } catch (error) {
    console.error('Client registration error:', error);
    res.status(400).json({
      error: 'invalid_client_metadata',
      error_description: error instanceof Error ? error.message : 'Registration failed',
    });
  }
});

/**
 * Authorization Endpoint
 * Shows login page for user authentication
 */
app.get('/mcp/:serviceName/authorize', (req, res) => {
  const { serviceName } = req.params;
  const { client_id, redirect_uri, state, scope, code_challenge, code_challenge_method } = req.query;

  try {
    const html = oauthProvider.authorize(serviceName, {
      clientId: client_id as string,
      redirectUri: redirect_uri as string,
      state: state as string,
      scopes: scope ? (scope as string).split(' ') : [],
      codeChallenge: code_challenge as string,
      codeChallengeMethod: code_challenge_method as string,
    });
    res.setHeader('Content-Type', 'text/html');
    res.send(html);
  } catch (error) {
    console.error('Authorize error:', error);
    res.status(400).json({
      error: 'invalid_request',
      error_description: error instanceof Error ? error.message : 'Authorization failed',
    });
  }
});

/**
 * Login Endpoint
 * Handles form submission from authorization page
 */
app.post('/mcp/:serviceName/login', async (req, res) => {
  try {
    const { email, password, client_id, redirect_uri, state, code_challenge, code_challenge_method } = req.body;

    const redirectUrl = await oauthProvider.handleLogin(email, password, {
      clientId: client_id,
      redirectUri: redirect_uri,
      state,
      codeChallenge: code_challenge,
      codeChallengeMethod: code_challenge_method,
    });

    res.redirect(redirectUrl);
  } catch (error) {
    console.error('Login error:', error);
    res.status(401).send(`
      <!DOCTYPE html>
      <html>
      <head>
        <title>Login Failed</title>
        <style>
          body { font-family: system-ui; max-width: 400px; margin: 100px auto; padding: 20px; }
          .error { color: red; margin-bottom: 20px; }
          a { color: #007bff; }
        </style>
      </head>
      <body>
        <h2>Login Failed</h2>
        <p class="error">${error instanceof Error ? error.message : 'Invalid credentials'}</p>
        <p><a href="javascript:history.back()">Try again</a></p>
      </body>
      </html>
    `);
  }
});

/**
 * DF Callback Endpoint
 * Handles session token from DreamFactory SSO
 */
app.post('/mcp/:serviceName/df-callback', async (req, res) => {
  try {
    const { session_token, state, client_id, redirect_uri, code_challenge, original_state } = req.body;

    if (!session_token) {
      res.status(400).json({ error: 'Missing session_token' });
      return;
    }

    const redirectUrl = await oauthProvider.handleDFCallback(
      session_token,
      state,
      client_id,
      redirect_uri,
      code_challenge,
      original_state
    );

    res.json({ redirect: redirectUrl });
  } catch (error) {
    console.error('DF callback error:', error);
    res.status(400).json({
      error: error instanceof Error ? error.message : 'Failed to process session token',
    });
  }
});

/**
 * Token Endpoint
 * Exchange authorization code for access token
 */
app.post('/mcp/:serviceName/token', async (req, res) => {
  try {
    const { grant_type, code, redirect_uri, client_id, client_secret, code_verifier, refresh_token } = req.body;

    let result;
    if (grant_type === 'authorization_code') {
      result = await oauthProvider.exchangeAuthorizationCode({
        clientId: client_id,
        clientSecret: client_secret,
        code,
        redirectUri: redirect_uri,
        codeVerifier: code_verifier,
      });
    } else if (grant_type === 'refresh_token') {
      result = await oauthProvider.exchangeRefreshToken({
        clientId: client_id,
        clientSecret: client_secret,
        refreshToken: refresh_token,
      });
    } else {
      res.status(400).json({
        error: 'unsupported_grant_type',
        error_description: `Grant type '${grant_type}' is not supported`,
      });
      return;
    }

    res.json(result);
  } catch (error) {
    console.error('Token exchange error:', error);
    res.status(400).json({
      error: 'invalid_grant',
      error_description: error instanceof Error ? error.message : 'Token exchange failed',
    });
  }
});

/**
 * CORS preflight for token endpoint
 */
app.options('/mcp/:serviceName/token', (_req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
  res.status(204).send();
});

// ============================================================================
// MCP Protocol Endpoint - Requires Bearer token
// ============================================================================

app.all('/mcp/:serviceName', async (req: Request, res: Response) => {
  const { serviceName } = req.params;
  const sessionIdHeader = getSessionId(req);
  const existingSession = sessionIdHeader ? sessions.get(sessionIdHeader) : undefined;

  // Get DreamFactory session token from header (passed by PHP after OAuth validation)
  const dfSessionToken = req.headers['x-dreamfactory-session-token'] as string | undefined;

  // DEBUG
  console.log('[MCP] Request received:', {
    serviceName,
    sessionIdHeader,
    hasExistingSession: !!existingSession,
    hasDfSessionToken: !!dfSessionToken,
    dfSessionTokenPreview: dfSessionToken ? dfSessionToken.substring(0, 30) + '...' : 'none',
  });

  if (!dfSessionToken) {
    return sendUnauthorized(res, serviceName);
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
    const server = createServer(serviceName, config.baseUrl, sessionManager);

    const transport = new StreamableHTTPServerTransport({
      sessionIdGenerator: () => {
        const sessionId = randomUUID();
        // Store DF session token for user role-based access
        sessionManager.setConfig(sessionId, {
          url: config.baseUrl,
          apiKey: config.apiKey,
          sessionToken: dfSessionToken
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
  console.log(`Base URL: ${BASE_URL}`);
  console.log(`DreamFactory URL: ${DF_URL}`);
  console.log('');
  console.log('Per-service OAuth Discovery Endpoints (no auth):');
  console.log(`  GET ${BASE_URL}/mcp/:serviceName/.well-known/oauth-protected-resource`);
  console.log(`  GET ${BASE_URL}/mcp/:serviceName/.well-known/oauth-authorization-server`);
  console.log('');
  console.log('Per-service OAuth Flow Endpoints (no auth):');
  console.log(`  POST ${BASE_URL}/mcp/:serviceName/register`);
  console.log(`  GET  ${BASE_URL}/mcp/:serviceName/authorize`);
  console.log(`  POST ${BASE_URL}/mcp/:serviceName/login`);
  console.log(`  POST ${BASE_URL}/mcp/:serviceName/df-callback`);
  console.log(`  POST ${BASE_URL}/mcp/:serviceName/token`);
  console.log('');
  console.log('MCP Protocol Endpoint (requires Bearer token):');
  console.log(`  ALL  ${BASE_URL}/mcp/:serviceName`);
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


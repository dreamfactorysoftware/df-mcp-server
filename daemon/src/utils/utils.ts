import { Request } from 'express';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { SessionService } from '../services/session.service.js';
import { registerDreamFactoryTools } from '../services/tools.service.js';
import packageJson from '../../package.json' with { type: 'json' };

export function getSessionId(req: Request): string | undefined {
  const header = req.headers['mcp-session-id'];
  if (!header) {
    return undefined;
  }
  return Array.isArray(header) ? header[0] : header;
}

export function updateSessionConfigFromHeaders(
  req: Request,
  sessionManager: SessionService,
  sessionId?: string
) {
  if (!sessionId) {
    return;
  }

  const configHeader = req.header('X-Mcp-Config');
  const baseUrl = req.header('X-Mcp-Base-Url');
  if (!configHeader || !baseUrl) {
    return;
  }

  try {
    const parsed = JSON.parse(configHeader);
    const apiKey = parsed?.api_key;
    if (typeof apiKey === 'string' && apiKey.length > 0) {
      sessionManager.setConfig(sessionId, { url: baseUrl, apiKey });
    }
  } catch (error) {
    console.warn('Failed to parse X-Mcp-Config header:', error);
  }
}

export function parseConfigFromHeaders(req: Request, serviceName: string) {
  const configHeader = req.header('X-Mcp-Config');
  const baseUrl = req.header('X-Mcp-Base-Url');

  if (!configHeader) {
    throw new Error('X-Mcp-Config header required for initialization requests');
  }
  if (!baseUrl) {
    throw new Error('X-Mcp-Base-Url header required for initialization requests');
  }

  const parsed = JSON.parse(configHeader);
  const apiKey = parsed?.api_key;
  const apiName = parsed?.api_name ?? serviceName;

  if (!apiKey) {
    throw new Error('API key is required for MCP service');
  }

  return {
    apiKey,
    apiName,
    baseUrl
  };
}

export function createServer(
  serviceName: string,
  baseUrl: string,
  sessionManager: SessionService
): McpServer {
  const instructions = [
    `You are connected to the DreamFactory service "${serviceName}".`,
    `Base URL: ${baseUrl}`,
    '',
    'Use the available tools to inspect schemas, fetch data, and call stored procedures/functions.',
    'All tools operate against the DreamFactory REST API using the API key supplied when this session was initialized.'
  ].join('\n');

  const server = new McpServer(
    {
      name: `DreamFactory MCP (${serviceName})`,
      version: (packageJson as { version?: string })?.version ?? 'dev'
    },
    {
      instructions
    }
  );

  registerDreamFactoryTools(server, sessionManager);
  return server;
}



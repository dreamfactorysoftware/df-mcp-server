import * as z from 'zod/v4';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { DFAuthConfig } from './dreamfactory.service.js';
import type { SessionService } from './session.service.js';

export type ToolResponse = {
  content: Array<{ type: 'text'; text: string }>;
  isError?: boolean;
};

export const respond = (data: unknown): ToolResponse => ({
  content: [{ type: 'text', text: JSON.stringify(data, null, 2) }]
});

export const respondError = (message: string): ToolResponse => ({
  content: [{ type: 'text', text: message }],
  isError: true
});

export const handleError = (error: unknown, operation: string): string => {
  if (!(error instanceof Error)) {
    return `Unknown error during ${operation}: ${String(error)}`;
  }

  const message = error.message;
  if (message.includes('Authentication failed') || message.includes('401')) {
    return `Authentication Error: ${message}`;
  }
  if (message.includes('Network error') || message.includes('Unable to connect')) {
    return `Connection Error: ${message}`;
  }
  if (message.includes('Access forbidden') || message.includes('403')) {
    return `Permission Error: ${message}`;
  }
  if (message.includes('Resource not found') || message.includes('404')) {
    return `Resource Error: ${message}`;
  }
  if (message.includes('Validation error') || message.includes('422')) {
    return `Validation Error: ${message}`;
  }
  if (message.includes('Server error') || message.includes('500')) {
    return `Server Error: ${message}`;
  }
  return `Error during ${operation}: ${message}`;
};

/**
 * Sanitize API name for use as a tool prefix.
 * Converts to lowercase, replaces non-alphanumeric chars with underscores.
 */
export function sanitizeApiName(name: string): string {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9]/g, '_')
    .replace(/_+/g, '_')
    .replace(/^_|_$/g, '');
}

export function getAuth(sessionManager: SessionService, sessionId?: string): DFAuthConfig {
  const sessionConfig = sessionId ? sessionManager.getConfig(sessionId) : undefined;
  const sessionToken = sessionConfig?.sessionToken ?? '';
  const apiKey = sessionConfig?.apiKey;

  if (!sessionToken) {
    throw new Error('DreamFactory session not found. Please authenticate via OAuth.');
  }

  return { sessionToken, apiKey };
}

export function createToolRegistrar(server: McpServer) {
  return (
    name: string,
    title: string,
    description: string,
    schema: z.ZodTypeAny,
    handler: (params: any, context: { sessionId?: string }) => Promise<ToolResponse>
  ) => {
    server.registerTool(
      name,
      { title, description, inputSchema: schema },
      async (params, context) => {
        try {
          return await handler(params ?? {}, context ?? {});
        } catch (error) {
          console.error(`Tool ${name} error:`, error);
          return respondError(handleError(error, name));
        }
      }
    );
  };
}

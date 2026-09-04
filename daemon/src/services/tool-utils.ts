import * as z from 'zod/v4';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { DFAuthConfig } from './dreamfactory.service.js';
import type { SessionService } from './session.service.js';
import { lazyStateFor } from './lazy.service.js';

type TextBlock = { type: 'text'; text: string };
type ImageBlock = { type: 'image'; data: string; mimeType: string };
type AudioBlock = { type: 'audio'; data: string; mimeType: string };

export type ToolResponse = {
  content: Array<TextBlock | ImageBlock | AudioBlock>;
  isError?: boolean;
};

export const respond = (data: unknown): ToolResponse => {
  let text: string;
  try {
    text = JSON.stringify(data, null, 2) ?? 'null';
  } catch {
    text = String(data);
  }
  return { content: [{ type: 'text', text }] };
};

export const respondError = (message: string): ToolResponse => ({
  content: [{ type: 'text', text: message }],
  isError: true
});

export const handleError = (error: unknown, operation: string): string => {
  if (!(error instanceof Error)) {
    return `Unknown error during ${operation}: ${String(error)}`;
  }

  const message = error.message ?? '';
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
  // Always consult the manager: in stateless mode there is no session ID and the
  // per-request config is served from the default slot.
  const sessionConfig = sessionManager.getConfig(sessionId);
  const sessionToken = sessionConfig?.sessionToken ?? '';
  const apiKey = sessionConfig?.apiKey;

  if (!sessionToken) {
    throw new Error('DreamFactory session not found. Please authenticate via OAuth.');
  }

  return { sessionToken, apiKey };
}

export function createToolRegistrar(server: McpServer, disabledTools?: Set<string>) {
  return (
    name: string,
    title: string,
    description: string,
    schema: z.ZodTypeAny,
    handler: (params: any, context: { sessionId?: string }) => Promise<ToolResponse>
  ) => {
    if (disabledTools?.has(name)) {
      return;
    }
    // Lazy mode keeps a catalog of every tool so the facade can search,
    // describe and call them by name; results are shaped/paged when active.
    const lazy = lazyStateFor(server);
    lazy?.register({ name, title, description, schema, handler });
    server.registerTool(
      name,
      { title, description, inputSchema: schema },
      async (params, context) => {
        console.log(`[tool] ${name} called`);
        try {
          const result = await handler(params ?? {}, context ?? {});
          console.log(`[tool] ${name} completed, isError=${result?.isError ?? false}`);
          return lazy ? lazy.finish(name, result) : result;
        } catch (error) {
          console.error(`[tool] ${name} unhandled error:`, error);
          const failed = respondError(handleError(error, name));
          return lazy ? lazy.finish(name, failed) : failed;
        }
      }
    );
  };
}

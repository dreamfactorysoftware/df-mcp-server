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
  // Every DF sub-call carries the key/session the PHP proxy already validated,
  // so a 403 — or the 401 "User is not authenticated" df-core raises when a
  // key-only role has no access to the resource — is a role denial, not an
  // authentication failure. Say so, or the model wastes turns re-authenticating.
  if (message.includes('403') || message.includes('Access forbidden') || message.includes('User is not authenticated')) {
    return `Permission Error: the session's role may not ${operation}. Re-authenticating will not help; the role needs access granted in DreamFactory. DreamFactory said: ${message}`;
  }
  if (message.includes('Authentication failed') || message.includes('401')) {
    return `Authentication Error: ${message}`;
  }
  if (message.includes('Network error') || message.includes('Unable to connect')) {
    return `Connection Error: ${message}`;
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
 * Verbs that change data. With allow_writes=false these are never registered,
 * in either tool style, so neither tools/list nor the lazy facade can reach them.
 */
export const WRITE_VERBS = new Set([
  'create_records', 'update_records', 'delete_records',
  'call_stored_procedure', 'call_stored_function',
  'create_file', 'create_folder', 'delete_file'
]);

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
  const sessionToken = sessionConfig?.sessionToken || undefined;
  const apiKey = sessionConfig?.apiKey || undefined;

  // At least one credential is required. API-key-only auth is valid when the
  // key's app has a role assigned (validated by the PHP proxy).
  if (!sessionToken && !apiKey) {
    throw new Error('DreamFactory session not found. Please authenticate via OAuth or provide an API key.');
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

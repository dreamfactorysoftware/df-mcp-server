import * as z from 'zod/v4';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { DFAuthConfig } from './dreamfactory.service.js';
import type { SessionService } from './session.service.js';

type TextBlock = { type: 'text'; text: string };
type ImageBlock = { type: 'image'; data: string; mimeType: string };
type AudioBlock = { type: 'audio'; data: string; mimeType: string };

/**
 * The SDK's RequestHandlerExtra, narrowed to what tool handlers use. It was
 * already being passed through to handlers; only _meta is newly surfaced, so
 * progress-capable tools can see the client's progressToken.
 */
export type ToolCallContext = {
  sessionId?: string;
  _meta?: { progressToken?: string | number };
};

export type ToolResponse = {
  content: Array<TextBlock | ImageBlock | AudioBlock>;
  isError?: boolean;
};

/**
 * Reports incremental progress for a tool call, if the client asked for it.
 * Undefined when it did not — callers use `onProgress?.(...)` and stay silent.
 */
export type ProgressReporter = (progress: number, total?: number, message?: string) => Promise<void>;

/** Minimum gap between emitted progress events. A 100-page scan should not emit 100 notifications. */
const PROGRESS_THROTTLE_MS = 400;

/**
 * Build a progress reporter for one tool call.
 *
 * Returns undefined unless the client supplied a progressToken in the request's
 * _meta, which per the MCP spec is how a client opts in to progress.
 *
 * Notifications are sent WITHOUT a relatedRequestId, which routes them to the
 * session's standalone SSE stream (the GET) rather than the POST's own response
 * stream. That is deliberate: the PHP proxy reads the POST response without
 * streaming, so anything written to the POST stream is buffered and arrives all
 * at once alongside the result — useless as progress. The standalone stream is
 * proxied straight to the client, so events arrive while the call is still
 * running. The progressToken is what correlates them back to this request.
 *
 * If the client never opened the standalone stream the SDK drops these silently,
 * so this degrades to exactly the previous behaviour.
 */
export function makeProgressReporter(
  server: McpServer,
  meta?: { progressToken?: string | number }
): ProgressReporter | undefined {
  const progressToken = meta?.progressToken;
  if (progressToken === undefined || progressToken === null) {
    return undefined;
  }

  let lastSent = 0;
  let lastProgress: number | undefined;
  return async (progress, total, message) => {
    // A caller that reports the same count twice (e.g. a pagination loop whose
    // final, empty page adds no rows) must not emit a duplicate event.
    if (progress === lastProgress) {
      return;
    }
    // Always let the final tick through so the bar can reach completion.
    const isFinal = total !== undefined && progress >= total;
    if (!isFinal && Date.now() - lastSent < PROGRESS_THROTTLE_MS) {
      return;
    }
    lastSent = Date.now();
    lastProgress = progress;
    try {
      await server.server.notification({
        method: 'notifications/progress',
        params: { progressToken, progress, ...(total !== undefined ? { total } : {}), ...(message ? { message } : {}) }
      });
    } catch {
      // Progress is telemetry. Never fail a tool call because it could not be sent.
    }
  };
}

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
    handler: (params: any, context: ToolCallContext) => Promise<ToolResponse>
  ) => {
    if (disabledTools?.has(name)) {
      return;
    }
    server.registerTool(
      name,
      { title, description, inputSchema: schema },
      async (params, context) => {
        console.log(`[tool] ${name} called`);
        try {
          const result = await handler(params ?? {}, context ?? {});
          console.log(`[tool] ${name} completed, isError=${result?.isError ?? false}`);
          return result;
        } catch (error) {
          console.error(`[tool] ${name} unhandled error:`, error);
          return respondError(handleError(error, name));
        }
      }
    );
  };
}

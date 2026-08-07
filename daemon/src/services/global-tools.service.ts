import * as z from 'zod/v4';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { SessionService } from './session.service.js';
import type { DFAuthConfig } from './dreamfactory.service.js';
import { createToolRegistrar, respond, respondError, getAuth } from './tool-utils.js';

/**
 * Agent Access Negotiation (AAN) global tools. Unlike the per-database tools,
 * these are registered ONCE per session and are not service-prefixed — they are
 * about the calling agent's identity and access, not any one data source.
 *
 * Both call self-scoped DreamFactory endpoints (registered by df-agents under
 * /api/v2/agent/*) using the session's own credentials. RBAC and the role-filter
 * happen server-side; the daemon just relays.
 */

const REQUEST_TIMEOUT_MS = 30_000;

async function dfFetch(
  method: string,
  url: string,
  auth: DFAuthConfig,
  body?: Record<string, unknown>
): Promise<unknown> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-DreamFactory-Session-Token': auth.sessionToken,
  };
  if (auth.apiKey) {
    headers['X-DreamFactory-API-Key'] = auth.apiKey;
  }
  if (body) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(url, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
    signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
  });

  const text = await response.text();
  if (!response.ok) {
    throw new Error(text || response.statusText);
  }
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}

export function registerGlobalTools(
  server: McpServer,
  sessionManager: SessionService,
  disabledTools?: Set<string>
): void {
  const registerTool = createToolRegistrar(server, disabledTools);

  registerTool(
    'discover_services',
    'Discover Services',
    'Discover which DreamFactory services and operations YOU (the calling agent) are '
      + 'permitted to access, based on your assigned role. Returns the role-filtered '
      + 'service catalog with the operations (GET/POST/PUT/PATCH/DELETE) allowed on each. '
      + 'Call this first to learn your boundaries before querying any data.',
    z.object({}),
    async (_params, context) => {
      try {
        const cfg = context.sessionId ? sessionManager.getConfig(context.sessionId) : undefined;
        if (!cfg) {
          return respondError('DreamFactory session not found. Please authenticate.');
        }
        const auth = getAuth(sessionManager, context.sessionId);
        return respond(await dfFetch('GET', `${cfg.url}/agent/catalog`, auth));
      } catch (error) {
        return respondError(
          `discover_services failed: ${error instanceof Error ? error.message : String(error)}`
        );
      }
    }
  );

  registerTool(
    'request_access',
    'Request Access',
    'Request access you do not currently have. Creates a pending request for human '
      + 'approval and notifies administrators via alerts. Provide the service names and '
      + 'operations you need plus an optional reason. You will NOT get access immediately '
      + '— an administrator must approve it, after which a fresh scoped key is issued.',
    z.object({
      services: z.array(z.string()).describe('Service names to request, e.g. ["mysql","postgres"].'),
      operations: z
        .array(z.string())
        .describe('Operations needed: any of GET, POST, PUT, PATCH, DELETE.'),
      note: z.string().optional().describe('Why you need this access (shown to the approving admin).'),
    }),
    async (params, context) => {
      try {
        const cfg = context.sessionId ? sessionManager.getConfig(context.sessionId) : undefined;
        if (!cfg) {
          return respondError('DreamFactory session not found. Please authenticate.');
        }
        const auth = getAuth(sessionManager, context.sessionId);
        return respond(
          await dfFetch('POST', `${cfg.url}/agent/request_access`, auth, {
            services: params.services ?? [],
            operations: params.operations ?? [],
            note: params.note,
          })
        );
      } catch (error) {
        return respondError(
          `request_access failed: ${error instanceof Error ? error.message : String(error)}`
        );
      }
    }
  );
}

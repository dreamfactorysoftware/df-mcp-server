import * as z from 'zod/v4';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { CustomToolParameter, CustomToolDefinition } from '../types.js';
import type { DFAuthConfig } from './dreamfactory.service.js';
import type { SessionService } from './session.service.js';
import { respond, respondError, createToolRegistrar, getAuth } from './tool-utils.js';

const MAX_RESPONSE_SIZE = 1_048_576; // 1 MB
const REQUEST_TIMEOUT_MS = 30_000; // 30 seconds
const FUNCTION_TIMEOUT_MS = 30_000; // 30 seconds

// Function-type custom tools run an admin-authored JS body through new Function(),
// which is arbitrary code execution reachable from a request-driven path. It is
// disabled by default and must be explicitly enabled by an operator who accepts
// the risk. Set MCP_ALLOW_FUNCTION_TOOLS=true to allow admin-configured function
// tools to run.
const FUNCTION_TOOLS_ENABLED =
  (process.env.MCP_ALLOW_FUNCTION_TOOLS ?? 'false').toLowerCase() === 'true';

function safeStringify(value: unknown): string {
  try {
    return JSON.stringify(value);
  } catch {
    return String(value);
  }
}

/**
 * Build a Zod object schema from custom tool parameter definitions.
 */
export function buildZodSchema(parameters: CustomToolParameter[]): z.ZodObject<Record<string, z.ZodTypeAny>> {
  const shape: Record<string, z.ZodTypeAny> = {};

  if (!Array.isArray(parameters)) {
    return z.object(shape);
  }

  for (const param of parameters) {
    let field: z.ZodTypeAny;

    switch (param.type) {
      case 'number':
        field = z.number();
        break;
      case 'integer':
        field = z.number().int();
        break;
      case 'boolean':
        field = z.boolean();
        break;
      case 'string':
      default:
        field = z.string();
        break;
    }

    if (param.description) {
      field = field.describe(param.description);
    }

    if (!param.required) {
      field = field.optional();
    }

    shape[param.name] = field;
  }

  return z.object(shape);
}

/**
 * Execute a function tool by running the function body with parameter values.
 */
export async function executeFunctionToolRequest(
  toolDef: CustomToolDefinition,
  params: Record<string, unknown>
) {
  const functionBody = toolDef.function;
  console.log(`[custom-tool] Executing function "${toolDef.name}", params:`, safeStringify(params));

  if (!FUNCTION_TOOLS_ENABLED) {
    console.warn(`[custom-tool] Function "${toolDef.name}" blocked: function tools are disabled. Set MCP_ALLOW_FUNCTION_TOOLS=true to enable.`);
    return respondError('Function tools are disabled on this server.');
  }

  if (!functionBody) {
    console.error(`[custom-tool] Function "${toolDef.name}" has no function body`);
    return respondError('Function tool has no function defined.');
  }

  const parameters = Array.isArray(toolDef.parameters) ? toolDef.parameters : [];
  const paramNames = parameters.map(p => p.name);
  const paramValues = paramNames.map((name, i) => {
    const value = params[name];
    if (value !== undefined && value !== null) return value;
    // Provide type-appropriate defaults so functions can safely call methods like .includes()
    switch (parameters[i].type) {
      case 'string': return '';
      case 'number':
      case 'integer': return 0;
      case 'boolean': return false;
      default: return '';
    }
  });

  try {
    // Wrap the body in an async IIFE so that `await` is valid inside user-written formulas.
    // The outer (sync) function returns the Promise produced by the IIFE; Promise.race then
    // resolves or rejects it against the timeout.
    const fn = new Function('secrets', ...paramNames, `return (async () => { ${functionBody} })()`);
    const result = await Promise.race([
      fn(toolDef.secrets ?? {}, ...paramValues) as Promise<unknown>,
      new Promise((_, reject) =>
        setTimeout(() => reject(new Error('Function execution timed out after 30s')), FUNCTION_TIMEOUT_MS)
      ),
    ]);
    console.log(`[custom-tool] Function "${toolDef.name}" result:`, safeStringify(result));
    return respond(result);
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : String(error);
    console.error(`[custom-tool] Function "${toolDef.name}" error:`, message);
    return respondError(`Function execution error: ${message}`);
  }
}

/**
 * Execute an HTTP request for a custom tool definition with the given parameters.
 */
export async function executeCustomToolRequest(
  toolDef: CustomToolDefinition,
  params: Record<string, unknown>,
  auth?: DFAuthConfig
) {
  console.log(`[custom-tool] Executing API tool "${toolDef.name}", method=${toolDef.http_method}, url=${toolDef.url}, params:`, safeStringify(params));

  if (!toolDef.url) {
    console.error(`[custom-tool] API tool "${toolDef.name}" has no URL configured`);
    return respondError('API tool has no URL configured.');
  }

  let url = toolDef.url;
  const queryParts: string[] = [];
  let jsonBody: Record<string, unknown> | undefined;
  const dynamicHeaders: Record<string, string> = {};
  const parameters = Array.isArray(toolDef.parameters) ? toolDef.parameters : [];

  for (const paramDef of parameters) {
    const value = params[paramDef.name];
    if (value === undefined) continue;

    switch (paramDef.in) {
      case 'path':
        url = url.replace(`{${paramDef.name}}`, encodeURIComponent(String(value)));
        break;
      case 'query':
        queryParts.push(`${encodeURIComponent(paramDef.name)}=${encodeURIComponent(String(value))}`);
        break;
      case 'body':
        if (!jsonBody) jsonBody = {};
        jsonBody[paramDef.name] = value;
        break;
      case 'header':
        dynamicHeaders[paramDef.name] = String(value);
        break;
    }
  }

  if (queryParts.length > 0) {
    const separator = url.includes('?') ? '&' : '?';
    url += separator + queryParts.join('&');
  }

  const headers: Record<string, string> = {
    ...(toolDef.headers ?? {}),
    ...dynamicHeaders,
  };

  // Inject DreamFactory auth headers if available and not already set
  if (auth) {
    if (auth.sessionToken && !Object.keys(headers).some(k => k.toLowerCase() === 'x-dreamfactory-session-token')) {
      headers['X-DreamFactory-Session-Token'] = auth.sessionToken;
    }
    if (auth.apiKey && !Object.keys(headers).some(k => k.toLowerCase() === 'x-dreamfactory-api-key')) {
      headers['X-DreamFactory-API-Key'] = auth.apiKey;
    }
  }

  const method = toolDef.http_method!;

  const fetchOptions: RequestInit = {
    method,
    headers,
  };

  if (jsonBody && method !== 'GET') {
    fetchOptions.body = JSON.stringify(jsonBody);
    if (!Object.keys(headers).some(k => k.toLowerCase() === 'content-type')) {
      headers['Content-Type'] = 'application/json';
    }
  }

  fetchOptions.signal = AbortSignal.timeout(REQUEST_TIMEOUT_MS);

  console.log(`[custom-tool] "${toolDef.name}" fetching: ${method} ${url}`);
  const response = await fetch(url, fetchOptions);
  console.log(`[custom-tool] "${toolDef.name}" response: ${response.status} ${response.statusText}`);

  const contentLength = response.headers.get('content-length');
  if (contentLength && parseInt(contentLength, 10) > MAX_RESPONSE_SIZE) {
    return respondError(`Response too large (${contentLength} bytes). Maximum allowed: ${MAX_RESPONSE_SIZE} bytes.`);
  }

  const text = await response.text();

  if (text.length > MAX_RESPONSE_SIZE) {
    return respondError(`Response too large (${text.length} bytes). Maximum allowed: ${MAX_RESPONSE_SIZE} bytes.`);
  }

  if (!response.ok) {
    console.error(`[custom-tool] "${toolDef.name}" HTTP error: ${response.status} ${response.statusText}:`, text.slice(0, 500));
    return respondError(`HTTP ${response.status} ${response.statusText}: ${text}`);
  }

  // Try to parse as JSON for pretty-printing
  try {
    const json = JSON.parse(text);
    return respond(json);
  } catch {
    return { content: [{ type: 'text' as const, text }] };
  }
}

/**
 * Register custom tools on the MCP server.
 */
export function registerCustomTools(
  server: McpServer,
  customTools: CustomToolDefinition[],
  sessionManager: SessionService,
  disabledTools?: Set<string>
) {
  const registerTool = createToolRegistrar(server, disabledTools);

  for (const toolDef of customTools) {
    const schema = buildZodSchema(toolDef.parameters);

    const isFunction = toolDef.tool_type === 'function' || (!toolDef.url && !!toolDef.function);
    console.log(`[custom-tool] Registering "${toolDef.name}" — tool_type=${toolDef.tool_type}, isFunction=${isFunction}, url=${toolDef.url ?? '(none)'}`);

    registerTool(
      toolDef.name,
      toolDef.name,
      toolDef.description,
      schema,
      async (params, context) => {
        if (isFunction) {
          return executeFunctionToolRequest(toolDef, params);
        }
        let auth: DFAuthConfig | undefined;
        try {
          auth = getAuth(sessionManager, context.sessionId);
        } catch {
          // Auth not available — custom tool will run without DF headers
        }
        return executeCustomToolRequest(toolDef, params, auth);
      }
    );
  }
}

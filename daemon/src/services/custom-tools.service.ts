import * as z from 'zod/v4';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { CustomToolParameter, CustomToolDefinition } from '../types.js';
import { respond, respondError, createToolRegistrar } from './tool-utils.js';

const MAX_RESPONSE_SIZE = 1_048_576; // 1 MB
const REQUEST_TIMEOUT_MS = 30_000; // 30 seconds

/**
 * Build a Zod object schema from custom tool parameter definitions.
 */
export function buildZodSchema(parameters: CustomToolParameter[]): z.ZodObject<Record<string, z.ZodTypeAny>> {
  const shape: Record<string, z.ZodTypeAny> = {};

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
 * Execute an HTTP request for a custom tool definition with the given parameters.
 */
export async function executeCustomToolRequest(
  toolDef: CustomToolDefinition,
  params: Record<string, unknown>
) {
  let url = toolDef.url;
  const queryParts: string[] = [];
  let jsonBody: Record<string, unknown> | undefined;
  const dynamicHeaders: Record<string, string> = {};

  for (const paramDef of toolDef.parameters) {
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
    ...toolDef.headers,
    ...dynamicHeaders,
  };

  const fetchOptions: RequestInit = {
    method: toolDef.http_method,
    headers,
  };

  if (jsonBody && toolDef.http_method !== 'GET') {
    fetchOptions.body = JSON.stringify(jsonBody);
    if (!Object.keys(headers).some(k => k.toLowerCase() === 'content-type')) {
      headers['Content-Type'] = 'application/json';
    }
  }

  fetchOptions.signal = AbortSignal.timeout(REQUEST_TIMEOUT_MS);

  const response = await fetch(url, fetchOptions);

  const contentLength = response.headers.get('content-length');
  if (contentLength && parseInt(contentLength, 10) > MAX_RESPONSE_SIZE) {
    return respondError(`Response too large (${contentLength} bytes). Maximum allowed: ${MAX_RESPONSE_SIZE} bytes.`);
  }

  const text = await response.text();

  if (text.length > MAX_RESPONSE_SIZE) {
    return respondError(`Response too large (${text.length} bytes). Maximum allowed: ${MAX_RESPONSE_SIZE} bytes.`);
  }

  if (!response.ok) {
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
  disabledTools?: Set<string>
) {
  const registerTool = createToolRegistrar(server, disabledTools);

  for (const toolDef of customTools) {
    const schema = buildZodSchema(toolDef.parameters);

    registerTool(
      toolDef.name,
      toolDef.name,
      toolDef.description,
      schema,
      async (params) => {
        return executeCustomToolRequest(toolDef, params);
      }
    );
  }
}

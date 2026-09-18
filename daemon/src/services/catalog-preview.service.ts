import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createServer, parseAvailableServicesList, parseMcpConfig } from '../utils/utils.js';
import { SessionService } from './session.service.js';
import { isFacadeTool, isReadOnly, lazyStateFor, type LazyDecision, type LazyMode } from './lazy.service.js';
import { sanitizeApiName } from './tool-utils.js';
import { DB_TOOL_NAMES } from './tools.service.js';
import type { ApiConfig, CustomToolDefinition } from '../types.js';

/**
 * Catalog preview (issue #64): compute what tools/list would return for an MCP
 * service — for a role the admin picks — before anyone connects. The verb
 * lists, prefix rules, aggregator thresholds, facade names and the lazy
 * decision all live here, so PHP sends the same `_mcpConfig` and
 * `_mcpAvailableServices` it would put in a real envelope and we run the real
 * registration path (createServer) against a throwaway in-memory client.
 * No tool is ever invoked: no DreamFactory calls, no session state.
 */

export type PreviewInput = {
  serviceName?: unknown;
  _mcpConfig?: unknown;
  _mcpAvailableServices?: unknown;
  /** initialize.clientInfo.name to decide with (passthrough clients get the full list). */
  clientName?: unknown;
  /** Overrides _mcpConfig.lazy_mode so the UI can show "what if". */
  lazyMode?: unknown;
};

export type PreviewTool = {
  name: string;
  title?: string;
  description?: string;
  category: 'database' | 'file' | 'custom' | 'facade' | 'global';
  /** Heuristic: the tool mutates data, so it needs more than GET on its backend. */
  write: boolean;
  /** Backend service the tool targets (prefixed database/file tools only). */
  service?: string;
};

export type PreviewResult = {
  /** Every callable tool, facade excluded — the catalog behind tools/list. */
  tools: PreviewTool[];
  count: number;
  /** Serialized size of that catalog as tools/list emits it (the `auto` threshold input). */
  bytes: number;
  lazy: LazyDecision;
  /** What tools/list advertises INSTEAD of the catalog when lazy: facade + hot tools. Empty otherwise. */
  facade: string[];
};

const LAZY_MODES: LazyMode[] = ['auto', 'on', 'off'];
const DB_VERBS = new Set(DB_TOOL_NAMES);
// ponytail: the only global tool that creates something; extend if more land.
const GLOBAL_WRITE = new Set(['request_access']);

export async function previewCatalog(input: PreviewInput): Promise<PreviewResult> {
  const serviceName = typeof input.serviceName === 'string' && input.serviceName !== '' ? input.serviceName : 'preview';
  const config = parseMcpConfig(input._mcpConfig);
  if (LAZY_MODES.includes(input.lazyMode as LazyMode)) {
    config.lazyMode = input.lazyMode as LazyMode;
  }
  // Base URL is never called — no tool runs — but ApiConfig requires one.
  const apiConfigs = Array.isArray(input._mcpAvailableServices)
    ? parseAvailableServicesList(input._mcpAvailableServices, 'http://preview.invalid/api/v2') ?? []
    : [];

  const server = createServer(
    serviceName, apiConfigs, new SessionService(),
    config.disabledTools, config.customTools, config.lazyMode, config.toolStyle, config.allowWrites
  );
  const client = new Client({
    name: typeof input.clientName === 'string' && input.clientName !== '' ? input.clientName : 'df-admin-preview',
    version: '0'
  });
  const [serverSide, clientSide] = InMemoryTransport.createLinkedPair();
  try {
    await server.connect(serverSide);
    await client.connect(clientSide);
    // The real tools/list handler, with the real client name in play.
    const advertised = (await client.listTools()).tools;
    const lazy = lazyStateFor(server);
    const decision: LazyDecision = lazy ? lazy.decide() : 'direct';
    const catalog = lazy
      ? await lazy.catalogTools()
      : { tools: advertised, bytes: JSON.stringify({ tools: advertised }).length };
    const custom = new Map((config.customTools ?? []).map(t => [t.name, t]));
    return {
      tools: catalog.tools.map(t => classify(t, apiConfigs, custom)),
      count: catalog.tools.length,
      bytes: catalog.bytes,
      lazy: decision,
      facade: decision === 'lazy' ? advertised.map(t => t.name) : []
    };
  } finally {
    await client.close().catch(() => undefined);
    await server.close().catch(() => undefined);
  }
}

function classify(
  t: { name: string; title?: string; description?: string },
  apiConfigs: ApiConfig[],
  custom: Map<string, CustomToolDefinition>
): PreviewTool {
  const base = { name: t.name, title: t.title, description: t.description };
  if (isFacadeTool(t.name)) {
    return { ...base, category: 'facade', write: t.name === 'call_tool' };
  }
  const c = custom.get(t.name);
  if (c) {
    return { ...base, category: 'custom', write: c.http_method ? c.http_method !== 'GET' : !isReadOnly(t.name) };
  }
  // Longest prefix first so `sales_eu_get_tables` binds to sales_eu, not sales.
  const prefixed = apiConfigs
    .map(api => ({ api, prefix: `${sanitizeApiName(api.name)}_` }))
    .sort((a, b) => b.prefix.length - a.prefix.length)
    .find(({ prefix }) => t.name.startsWith(prefix));
  if (prefixed) {
    return {
      ...base,
      category: prefixed.api.category,
      write: !isReadOnly(t.name.slice(prefixed.prefix.length)),
      service: prefixed.api.name
    };
  }
  if (DB_VERBS.has(t.name)) {
    // Merged style: one bare verb, the `service` argument picks the database.
    return { ...base, category: 'database', write: !isReadOnly(t.name) };
  }
  if (t.name.startsWith('all_')) {
    return { ...base, category: t.name === 'all_list_files' ? 'file' : 'database', write: false };
  }
  return { ...base, category: 'global', write: GLOBAL_WRITE.has(t.name) };
}

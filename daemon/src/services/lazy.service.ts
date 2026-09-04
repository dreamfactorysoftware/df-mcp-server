import { AsyncLocalStorage } from 'node:async_hooks';
import { randomUUID } from 'node:crypto';
import type { Response } from 'express';
import * as z from 'zod/v4';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { ListToolsRequestSchema } from '@modelcontextprotocol/sdk/types.js';
import { toJsonSchemaCompat } from '@modelcontextprotocol/sdk/server/zod-json-schema-compat.js';
import { normalizeObjectSchema } from '@modelcontextprotocol/sdk/server/zod-compat.js';
import { type ToolResponse, respond, respondError, handleError } from './tool-utils.js';

/**
 * Lazy mode (issue #52): instead of advertising every prefixed tool of every
 * service, `tools/list` returns a four-tool facade (search_tools,
 * describe_tool, call_tool, fetch_more) plus the tools this service's recent
 * sessions actually used ("hot" tools). Every tool stays registered with the
 * SDK, so direct calls by name keep working; only the advertised list shrinks.
 *
 * Mode per service config: off (today's behaviour, this module is never
 * instantiated), on (always facade), auto (facade only when the full list
 * would exceed LAZY_THRESHOLD_BYTES). Clients that defer schemas themselves
 * (MCP_LAZY_PASSTHROUGH, default codex/grok/hermes) always get the full list.
 */

export type LazyMode = 'auto' | 'on' | 'off';
export type LazyDecision = 'lazy' | 'direct' | 'passthrough';

export type CatalogEntry = {
  name: string;
  title: string;
  description: string;
  schema: z.ZodTypeAny;
  handler: (params: any, context: { sessionId?: string }) => Promise<ToolResponse>;
};

const FACADE = new Set(['search_tools', 'describe_tool', 'call_tool', 'fetch_more']);
const HOT_MAX = 8;
const PAGE_KEEP = 64;
const FETCH_MAX = 12_000;
const SHORT_DESC = 160;
const STRUCTURED_DUP_CHARS = 1_000;
const NOISE_KEYS = new Set(['trace', 'stack', 'stacktrace', 'stackTrace', 'stack_trace']);
const SCHEMA_NOISE_KEYS = new Set(['$schema', 'examples', 'title', '$id', '$comment']);

const env = (k: string, d: number) => {
  const n = Number(process.env[k]);
  return Number.isFinite(n) && n > 0 ? n : d;
};
export const LAZY_THRESHOLD_BYTES = env('MCP_LAZY_THRESHOLD_BYTES', 32 * 1024); // ≈ 8k tokens
export const PAGE_CHARS = Math.max(500, env('MCP_LAZY_PAGE_CHARS', 6_000));
const PASSTHROUGH = (process.env.MCP_LAZY_PASSTHROUGH ?? 'codex,grok,hermes')
  .split(',').map(s => s.trim().toLowerCase()).filter(Boolean);

// ---------------------------------------------------------------------------
// Per-request plumbing: the express Response for the request being served, so
// tool handlers can attach the ledger header PHP persists to mcp_request_log.
// ---------------------------------------------------------------------------
const requestResponse = new AsyncLocalStorage<Response>();
export const runWithResponse = <T>(res: Response, fn: () => T): T => requestResponse.run(res, fn);

// ---------------------------------------------------------------------------
// Process-wide state. ponytail: hot tools and page handles live in daemon
// memory — they survive sessions, not restarts, and are per node. Move them to
// PHP (mcp_server_config) if multi-node deployments need shared hot sets.
// ---------------------------------------------------------------------------
const hotByService = new Map<string, string[]>();
const pages = new Map<string, string>(); // handle -> full text, insertion-ordered LRU

export function recordHot(service: string, name: string): void {
  const list = (hotByService.get(service) ?? []).filter(n => n !== name);
  list.push(name);
  hotByService.set(service, list.slice(-HOT_MAX));
}

export function storePage(text: string): string {
  const handle = randomUUID();
  pages.set(handle, text);
  while (pages.size > PAGE_KEEP) pages.delete(pages.keys().next().value as string);
  return handle;
}

export function fetchPage(handle: string, offset = 0, limit = PAGE_CHARS): Record<string, unknown> {
  const full = pages.get(handle);
  if (full === undefined) throw new Error(`unknown or expired handle ${handle}`);
  if (offset >= full.length) throw new Error(`offset ${offset} is past the end (${full.length} chars)`);
  const take = Math.min(Math.max(1, limit), FETCH_MAX);
  const text = full.slice(offset, offset + take);
  const end = offset + text.length;
  return { handle, offset, end, total: full.length, more: end < full.length, text };
}

// ---------------------------------------------------------------------------
// Pure helpers (ported from agent-cockpit gateway: search.rs, shape.rs)
// ---------------------------------------------------------------------------
export function tokenize(s: string): string[] {
  return s.toLowerCase().split(/[^a-z0-9]+/).filter(Boolean);
}

const K1 = 1.2, B = 0.75, SUBSTRING_MIN = 3, MAX_QUERY_TERMS = 32, EXACT_NAME = 8, NAME_CONTAINS = 3;

type Doc = { name: string; tf: Map<string, number>; len: number };

/** Okapi BM25 over name + description + parameter names; query terms ≥3 chars also prefix-match. */
export class SearchIndex {
  private readonly docs: Doc[];
  private readonly avgdl: number;

  constructor(entries: Array<{ name: string; description: string; params?: string[] }>) {
    this.docs = entries.map(e => {
      const nameToks = tokenize(e.name);
      const tokens = [...nameToks, ...nameToks, e.name.toLowerCase(), ...tokenize(e.description), ...(e.params ?? []).flatMap(tokenize)];
      const tf = new Map<string, number>();
      for (const t of tokens) tf.set(t, (tf.get(t) ?? 0) + 1);
      return { name: e.name.toLowerCase(), tf, len: tokens.length };
    });
    this.avgdl = this.docs.length ? this.docs.reduce((a, d) => a + d.len, 0) / this.docs.length : 1;
  }

  /** Indices of matching docs, best first; ties broken by catalog order. */
  rank(query: string, limit: number): number[] {
    const terms = tokenize(query).slice(0, MAX_QUERY_TERMS);
    const full = query.trim().toLowerCase();
    if (terms.length === 0 && !full) return [];
    const n = this.docs.length;
    const idfs = terms.map(t => {
      const df = this.docs.filter(d => termTf(d, t) > 0).length;
      return Math.log((n - df + 0.5) / (df + 0.5) + 1);
    });
    const scored: Array<[number, number]> = [];
    this.docs.forEach((doc, i) => {
      let score = 0;
      terms.forEach((t, j) => {
        const tf = termTf(doc, t);
        if (tf > 0) score += idfs[j] * (tf * (K1 + 1)) / (tf + K1 * (1 - B + B * doc.len / this.avgdl));
      });
      if (full) {
        if (doc.name === full) score += EXACT_NAME;
        else if (doc.name.includes(full)) score += NAME_CONTAINS;
      }
      if (score > 0) scored.push([score, i]);
    });
    scored.sort((a, b) => b[0] - a[0] || a[1] - b[1]);
    return scored.slice(0, Math.max(1, limit)).map(([, i]) => i);
  }
}

function termTf(doc: Doc, term: string): number {
  const exact = doc.tf.get(term);
  if (exact) return exact;
  if (term.length < SUBSTRING_MIN) return 0;
  let best = 0;
  for (const [t, tf] of doc.tf) if (t.startsWith(term) && tf > best) best = tf;
  return best;
}

export function stripKeys(v: unknown, keys: Set<string>): unknown {
  if (Array.isArray(v)) return v.map(x => stripKeys(x, keys));
  if (v && typeof v === 'object') {
    const out: Record<string, unknown> = {};
    for (const [k, val] of Object.entries(v)) if (!keys.has(k)) out[k] = stripKeys(val, keys);
    return out;
  }
  return v;
}

/** Minify JSON text (also JSON after a text prefix like "Error during x: {…}") and drop trace/stack keys. */
export function shapeText(text: string): string {
  const start = text.search(/[{[]/);
  if (start < 0) return text;
  try {
    const body = JSON.parse(text.slice(start).trimEnd());
    return text.slice(0, start) + JSON.stringify(stripKeys(body, NOISE_KEYS));
  } catch {
    return text;
  }
}

export function compactSchema(schema: unknown): unknown {
  const strip = (v: unknown): unknown => {
    if (Array.isArray(v)) return v.map(strip);
    if (v && typeof v === 'object') {
      const out: Record<string, unknown> = {};
      for (const [k, val] of Object.entries(v)) {
        if (SCHEMA_NOISE_KEYS.has(k) || (k === 'description' && val === '')) continue;
        out[k] = strip(val);
      }
      return out;
    }
    return v;
  };
  return strip(schema);
}

export function shortDesc(s: string, max = SHORT_DESC): string {
  const flat = s.split(/\s+/).join(' ');
  if (flat.length <= max) return flat;
  let cut = flat.slice(0, max);
  const sp = cut.lastIndexOf(' ');
  if (sp > max / 2) cut = cut.slice(0, sp);
  return cut + '…';
}

export function toJsonSchema(schema: z.ZodTypeAny): unknown {
  const obj = normalizeObjectSchema(schema as any);
  return obj ? toJsonSchemaCompat(obj, { strictUnions: true, pipeStrategy: 'input' }) : { type: 'object' };
}

// ponytail: read-only is a name heuristic; the base tools carry no annotations.
const READ_ONLY = /(^|_)(get|list|search|fetch|describe|aggregate|whoami)(_|$)/;
export const isReadOnly = (name: string) => READ_ONLY.test(name);

// ---------------------------------------------------------------------------
// Per-server state
// ---------------------------------------------------------------------------
export class LazyState {
  readonly catalog = new Map<string, CatalogEntry>();
  /** Hot tools are frozen at session start so the advertised list never changes mid-session. */
  readonly hot: string[];
  private decision?: LazyDecision;
  private index?: SearchIndex;
  private names: string[] = [];
  private fullList?: unknown[];
  private catalogBytes = 0;
  private facadeBytes = 0;
  /** The SDK's own tools/list handler, captured before we override it, so non-lazy lists stay byte-identical to `off`. */
  sdkList?: (req: unknown, extra: unknown) => Promise<{ tools: unknown[] }>;

  constructor(readonly service: string, readonly mode: Exclude<LazyMode, 'off'>, private readonly server: McpServer) {
    this.hot = hotByService.get(service) ?? [];
  }

  register(entry: CatalogEntry): void {
    this.catalog.set(entry.name, entry);
  }

  /** Full tools/list entries for every registered tool (facade included), as the SDK would emit them. */
  private async allTools(extra?: unknown): Promise<unknown[]> {
    if (!this.fullList) {
      this.fullList = this.sdkList
        ? (await this.sdkList({ method: 'tools/list' }, extra)).tools
        : [...this.catalog.values()].map(t => ({
          name: t.name, title: t.title, description: t.description, inputSchema: toJsonSchema(t.schema)
        }));
      const facade = this.fullList.filter((t: any) => FACADE.has(t.name) || this.hot.includes(t.name));
      this.catalogBytes = JSON.stringify({ tools: this.fullList.filter((t: any) => !FACADE.has(t.name)) }).length;
      this.facadeBytes = JSON.stringify({ tools: facade }).length;
    }
    return this.fullList;
  }

  decide(): LazyDecision {
    if (this.decision) return this.decision;
    if (!this.fullList) throw new Error('lazy: tools/list must run before the mode is decided');
    const client = (this.server.server.getClientVersion()?.name ?? '').toLowerCase();
    if (PASSTHROUGH.some(p => client.includes(p))) this.decision = 'passthrough';
    else if (this.mode === 'on' || this.catalogBytes > LAZY_THRESHOLD_BYTES) this.decision = 'lazy';
    else this.decision = 'direct';
    console.log(`[lazy] ${this.service}: mode=${this.mode} client=${client || '?'} catalog=${this.catalogBytes}B → ${this.decision}`);
    return this.decision;
  }

  /** False until the client has listed tools: a call before tools/list is served exactly as in `off`. */
  isLazy(): boolean {
    return this.fullList !== undefined && this.decide() === 'lazy';
  }

  /** What tools/list returns for this session. */
  async listTools(extra?: unknown): Promise<unknown[]> {
    const all = await this.allTools(extra);
    this.ledger({});
    if (!this.isLazy()) return all.filter((t: any) => !FACADE.has(t.name));
    return all.filter((t: any) => FACADE.has(t.name) || this.hot.includes(t.name));
  }

  /** Attach the savings ledger for this request; PHP copies it into mcp_request_log. */
  ledger(extra: { result_chars_withheld?: number; facade_calls?: number }): void {
    const res = requestResponse.getStore();
    if (!res || res.headersSent || !this.fullList) return;
    const tokens = (b: number) => Math.round(b / 4);
    res.setHeader('X-Mcp-Ledger', JSON.stringify({
      mode: this.decide(),
      catalog_tokens: tokens(this.catalogBytes),
      preamble_saved_per_turn: this.isLazy() ? tokens(this.catalogBytes - this.facadeBytes) : 0,
      result_chars_withheld: extra.result_chars_withheld ?? 0,
      facade_calls: extra.facade_calls ?? 0
    }));
  }

  /** Post-process a catalog tool result: hot-set bookkeeping, shaping, paging. No-op unless lazy. */
  finish(name: string, result: ToolResponse, facade = false): ToolResponse {
    if (!this.isLazy()) return result;
    recordHot(this.service, name);
    let withheld = 0;
    const r = result as ToolResponse & { structuredContent?: unknown };
    const content = r.content.map(block => {
      if (block.type !== 'text') return block;
      const shaped = shapeText(block.text);
      if (shaped.length <= PAGE_CHARS) return { ...block, text: shaped };
      const handle = storePage(shaped);
      withheld += shaped.length - PAGE_CHARS;
      const trailer = `\n[paged: ${shaped.length} chars total, ${shaped.length - PAGE_CHARS} withheld. ` +
        `Call fetch_more {"handle":"${handle}","offset":${PAGE_CHARS}} for the next slice; ` +
        `narrow the query (filter, limit, fields) to avoid paging.]`;
      return { ...block, text: shaped.slice(0, PAGE_CHARS) + trailer };
    });
    const out: typeof r = { ...r, content };
    if (r.structuredContent !== undefined && content.some(b => b.type === 'text') &&
        JSON.stringify(r.structuredContent).length > STRUCTURED_DUP_CHARS) {
      delete out.structuredContent;
    }
    this.ledger({ result_chars_withheld: withheld, facade_calls: facade ? 1 : 0 });
    return out;
  }

  search(query: string, limit: number) {
    if (!this.index) {
      this.names = [...this.catalog.keys()].filter(n => !FACADE.has(n));
      this.index = new SearchIndex(this.names.map(n => {
        const t = this.catalog.get(n)!;
        const shape = (t.schema as any)?.shape ?? {};
        return { name: n, description: t.description, params: Object.keys(shape) };
      }));
    }
    const hits = this.index.rank(query, limit).map(i => this.catalog.get(this.names[i])!);
    const out: Record<string, unknown> = {
      tools: hits.map(t => ({ name: t.name, description: shortDesc(t.description), read_only: isReadOnly(t.name) }))
    };
    // Unambiguous hit: include the schema so no describe_tool round trip is needed.
    if (hits.length === 1 || hits[0]?.name.toLowerCase() === query.trim().toLowerCase()) {
      out.schema = { name: hits[0].name, inputSchema: compactSchema(toJsonSchema(hits[0].schema)) };
    }
    return out;
  }

  describe(name: string): ToolResponse {
    const t = this.catalog.get(name);
    if (!t || FACADE.has(name)) return respondError(`unknown tool ${name}; use search_tools`);
    return respond({ name, description: t.description, read_only: isReadOnly(name), inputSchema: compactSchema(toJsonSchema(t.schema)) });
  }

  async call(name: string, args: unknown, context: { sessionId?: string }): Promise<ToolResponse> {
    const t = this.catalog.get(name);
    if (!t || FACADE.has(name)) return respondError(`unknown tool ${name}; use search_tools`);
    const parsed = t.schema.safeParse(args ?? {});
    if (!parsed.success) {
      // Error and schema in one result so the model can retry without describe_tool.
      return {
        isError: true,
        content: [{ type: 'text', text: JSON.stringify({
          error: `invalid arguments for ${name}`,
          issues: parsed.error.issues.map(i => `${i.path.join('.') || '(root)'}: ${i.message}`),
          schema: compactSchema(toJsonSchema(t.schema))
        }) }]
      };
    }
    try {
      return this.finish(name, await t.handler(parsed.data, context), true);
    } catch (error) {
      return this.finish(name, respondError(handleError(error, name)), true);
    }
  }
}

const states = new WeakMap<McpServer, LazyState>();
export const lazyStateFor = (server: McpServer) => states.get(server);

export function createLazyState(server: McpServer, service: string, mode: Exclude<LazyMode, 'off'>): LazyState {
  const state = new LazyState(service, mode, server);
  states.set(server, state);
  return state;
}

export const LAZY_INSTRUCTIONS = [
  '',
  '## Lazy tool loading',
  'If the tool list shows `search_tools`, `describe_tool`, `call_tool` and `fetch_more`, the full catalog is hidden to save context.',
  'Run any catalog tool with `call_tool(name, arguments)` — e.g. `call_tool("{prefix}_get_data_model", {})` — where `{prefix}` is the API name shown above.',
  'Use `search_tools(query)` to find a tool and `describe_tool(name)` for its argument schema; a wrong call returns the schema with the error.',
  'Long results are paged: the first page carries a handle, `fetch_more(handle, offset)` returns the rest. Tools listed next to the facade were used recently and can be called directly.'
].join('\n');

/** Register the facade and override tools/list. Call after every other tool is registered, before connect. */
export function installLazyFacade(server: McpServer, state: LazyState): void {
  const facade = (name: string, title: string, description: string, schema: z.ZodObject<any>, handler: (params: any, ctx: { sessionId?: string }) => Promise<ToolResponse>) => {
    state.register({ name, title, description, schema, handler });
    server.registerTool(name, { title, description, inputSchema: schema }, async (params: any, ctx: any) => {
      try {
        return await handler(params ?? {}, ctx ?? {});
      } catch (error) {
        return respondError(handleError(error, name));
      }
    });
  };

  facade('search_tools', 'Search Tools',
    'Find catalog tools by BM25 ranking over names, descriptions and parameter names. Returns name + one-line description (no schemas) — then use describe_tool or call_tool.',
    z.object({
      query: z.string().describe('Natural-language or keyword query'),
      limit: z.number().int().min(1).max(50).optional().describe('Max hits (default 8)')
    }),
    async ({ query, limit }) => {
      const r = respond(state.search(String(query).slice(0, 500), limit ?? 8));
      state.ledger({ facade_calls: 1 });
      return r;
    });

  facade('describe_tool', 'Describe Tool',
    "Return one catalog tool's full input schema.",
    z.object({ name: z.string().describe('Exact catalog tool name') }),
    async ({ name }) => {
      const r = state.describe(name);
      state.ledger({ facade_calls: 1 });
      return r;
    });

  facade('call_tool', 'Call Tool',
    'Invoke a catalog tool by name after validating its arguments. A validation error returns the schema in the same result.',
    z.object({
      name: z.string().describe('Exact catalog tool name'),
      arguments: z.record(z.string(), z.unknown()).optional().describe('Arguments for the catalog tool')
    }),
    async ({ name, arguments: args }, ctx) => state.call(name, args ?? {}, ctx));

  facade('fetch_more', 'Fetch More',
    'Return the next slice of a paged tool result (see the handle in the result). Prefer narrowing the original query.',
    z.object({
      handle: z.string().describe('Handle from a paged result'),
      offset: z.number().int().min(0).optional().describe('Character offset to start from'),
      limit: z.number().int().min(1).optional().describe(`Max characters to return (default ${PAGE_CHARS})`)
    }),
    async ({ handle, offset, limit }) => {
      state.ledger({ facade_calls: 1 });
      return respond(fetchPage(handle, offset, limit));
    });

  // Replace the SDK's tools/list with the mode-aware one. The SDK's own handler
  // is already installed (registerTool above); keep it so full lists stay
  // byte-identical to `off`, then overwrite it.
  // ponytail: _requestHandlers is SDK-private; if it moves we fall back to our own serializer.
  state.sdkList = (server.server as any)._requestHandlers?.get('tools/list');
  server.server.setRequestHandler(ListToolsRequestSchema, async (_req, extra) => ({ tools: (await state.listTools(extra)) as any }));
}

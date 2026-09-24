import type * as z from 'zod/v4';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { CallToolRequestSchema, type ToolAnnotations } from '@modelcontextprotocol/sdk/types.js';
import { ledgerBump } from './ledger.js';

/**
 * Argument normalisation (issue #66). Canonical argument names are snake_case;
 * camelCase (and any other casing) is accepted forever as an alias. A key that
 * maps to nothing is an error naming the closest valid key — never silently
 * dropped, which is what Zod's default object parsing does.
 *
 * The normaliser runs in front of the SDK's own validation (tools/call handler
 * override) and in front of the lazy facade's call_tool validation, so every
 * registered tool, custom tools included, shares one path.
 */

export type ArgSpec = {
  /** Canonical argument names, as advertised in the tool's inputSchema. */
  keys: string[];
  /** Merged mode: lowercased service name or label -> service name, for the `service` argument. */
  serviceNames?: Map<string, string>;
};

export type Normalized = {
  args: unknown;
  /** "givenKey→canonicalKey" for every key that was not already canonical. */
  aliases: string[];
  errors: string[];
};

/** tableName / TableName / table-name / TABLE_NAME -> table_name */
export const snake = (key: string): string =>
  key.replace(/([a-z0-9])([A-Z])/g, '$1_$2').replace(/[-\s]+/g, '_').toLowerCase();

function levenshtein(a: string, b: string): number {
  let prev = Array.from({ length: b.length + 1 }, (_, i) => i);
  for (let i = 1; i <= a.length; i++) {
    const cur = [i];
    for (let j = 1; j <= b.length; j++) {
      cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
    }
    prev = cur;
  }
  return prev[b.length];
}

function closest(key: string, keys: string[]): string | undefined {
  const k = snake(key);
  let best: string | undefined;
  let bestD = Infinity;
  for (const c of keys) {
    const d = levenshtein(k, snake(c));
    if (d < bestD) { bestD = d; best = c; }
  }
  // ponytail: edit distance ≤ 3 reads as a typo; further than that we list the valid keys instead.
  return bestD <= 3 ? best : undefined;
}

export function normalizeArgs(spec: ArgSpec, input: unknown): Normalized {
  if (!input || typeof input !== 'object' || Array.isArray(input)) {
    return { args: input, aliases: [], errors: [] };
  }
  const canon = new Map<string, string>();
  for (const k of spec.keys) if (!canon.has(snake(k))) canon.set(snake(k), k);

  const args: Record<string, unknown> = {};
  const aliases: string[] = [];
  const errors: string[] = [];
  const seen = new Map<string, string>(); // canonical -> key it came from

  for (const [key, value] of Object.entries(input as Record<string, unknown>)) {
    const target = spec.keys.includes(key) ? key : canon.get(snake(key));
    if (!target) {
      const near = closest(key, spec.keys);
      errors.push(
        near
          ? `unknown argument ${key}, did you mean ${near}`
          : `unknown argument ${key}` + (spec.keys.length ? ` (valid: ${spec.keys.join(', ')})` : ' (this tool takes no arguments)')
      );
      continue;
    }
    if (seen.has(target)) {
      errors.push(`argument ${target} given twice (as ${seen.get(target)} and ${key})`);
      continue;
    }
    seen.set(target, key);
    if (target !== key) aliases.push(`${key}→${target}`);
    args[target] = value;
  }

  // Merged mode: accept a service's label (or any casing of its name) for `service`.
  const svc = args.service;
  if (spec.serviceNames && typeof svc === 'string') {
    const name = spec.serviceNames.get(svc) ?? spec.serviceNames.get(svc.toLowerCase());
    if (name !== undefined && name !== svc) {
      args.service = name;
      aliases.push(`service=${svc}→${name}`);
    }
  }

  return { args, aliases, errors };
}

export function specFor(schema: z.ZodTypeAny, serviceNames?: Map<string, string>): ArgSpec {
  const shape = (schema as { shape?: Record<string, unknown> }).shape ?? {};
  return { keys: Object.keys(shape), serviceNames };
}

/** Lowercased name and label of every service -> canonical name, for ArgSpec.serviceNames. */
export function serviceNameMap(services: Array<{ name: string; label?: string }>): Map<string, string> {
  const m = new Map<string, string>();
  for (const s of services) {
    m.set(s.name, s.name);
    m.set(s.name.toLowerCase(), s.name);
    if (s.label) m.set(s.label.toLowerCase(), s.name);
  }
  return m;
}

// ---------------------------------------------------------------------------
// MCP tool annotations. Verb prefixes are the contract for generated tools; a
// caller can override per tool (custom tools pass their own).
// ---------------------------------------------------------------------------
const READ = /(^|_)(get|list|search|fetch|describe|aggregate|discover|find|whoami)(_|$)/;
const DESTRUCTIVE = /(^|_)(delete|update)(_|$)/;
const ADDITIVE = /(^|_)create(_|$)/;

export function annotationsFor(name: string): ToolAnnotations {
  if (name === 'call_tool') return { openWorldHint: false }; // runs any catalog tool: unknown
  if (READ.test(name)) return { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false };
  if (DESTRUCTIVE.test(name)) return { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false };
  if (ADDITIVE.test(name)) return { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false };
  return { readOnlyHint: false, openWorldHint: false }; // stored procedures, request_access: effect unknown
}

// ---------------------------------------------------------------------------
// tools/call hook: per-server arg specs, consulted before the SDK validates.
// ---------------------------------------------------------------------------
const specs = new WeakMap<McpServer, Map<string, ArgSpec>>();

export function registerArgSpec(server: McpServer, name: string, spec: ArgSpec): void {
  let m = specs.get(server);
  if (!m) specs.set(server, (m = new Map()));
  m.set(name, spec);
}

export const argError = (name: string, errors: string[]) => ({
  isError: true,
  content: [{ type: 'text' as const, text: `Invalid arguments for ${name}: ${errors.join('; ')}` }]
});

/** Wrap the SDK's tools/call handler. Call once, after every tool (facade included) is registered. */
export function installArgNormalizer(server: McpServer): void {
  // ponytail: _requestHandlers is SDK-private (same trick as the lazy tools/list override); if it moves, args go unnormalised.
  const sdk = (server.server as any)._requestHandlers?.get('tools/call');
  if (!sdk) return;
  server.server.setRequestHandler(CallToolRequestSchema, async (request, extra) => {
    const name = request.params.name;
    const spec = specs.get(server)?.get(name);
    if (!spec) return sdk(request, extra);
    const { args, aliases, errors } = normalizeArgs(spec, request.params.arguments);
    ledgerBump({ arg_aliases: aliases.length, arg_errors: errors.length });
    if (errors.length) {
      console.log(`[args] ${name} rejected: ${errors.join('; ')}`);
      return argError(name, errors);
    }
    if (aliases.length) console.log(`[args] ${name} aliases: ${aliases.join(', ')}`);
    return sdk({ ...request, params: { ...request.params, arguments: args } }, extra);
  });
}

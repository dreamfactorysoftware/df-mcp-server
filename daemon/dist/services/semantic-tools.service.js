import * as z from 'zod/v4';
import { createHash } from 'node:crypto';
import { DreamFactoryService } from './dreamfactory.service.js';
import { baseTools } from './tools.service.js';
import { createToolRegistrar, getAuth, respond, sanitizeApiName } from './tool-utils.js';
import { lazyStateFor } from './lazy.service.js';
const CACHE_TTL_MS = 30_000;
/** A failed or timed-out load is remembered this long, so a slow DreamFactory does not add the fetch timeout to every request. */
const NEGATIVE_TTL_MS = 10_000;
const FETCH_TIMEOUT_MS = 10_000;
/** One entry per credential and database; bounded so a stream of one-off keys cannot grow it without limit. */
const CACHE_MAX = 500;
const MAX_NAME = 64;
/** The only verbs a named tool may run: reads. A catalog entry naming anything else is ignored. */
export const SEMANTIC_BASE_TOOLS = new Set(['get_table_data', 'aggregate_data']);
const cache = new Map();
function cacheGet(key) {
    const hit = cache.get(key);
    return hit && Date.now() - hit.at < hit.ttl ? hit.value : undefined;
}
function cachePut(key, value, ttl) {
    cache.delete(key);
    if (cache.size >= CACHE_MAX) {
        const now = Date.now();
        for (const [k, e] of cache)
            if (now - e.at >= e.ttl)
                cache.delete(k);
    }
    // Map iterates in insertion order: the first key is the oldest entry.
    while (cache.size >= CACHE_MAX)
        cache.delete(cache.keys().next().value);
    cache.set(key, { at: Date.now(), ttl, value });
}
/** Metrics and verified queries in one service's semantics block, as runnable specs. */
export function runnablesFrom(service, semantics) {
    if (!semantics || typeof semantics !== 'object')
        return [];
    const out = [];
    const lists = [['metric', semantics.metrics], ['query', semantics.verified_queries]];
    for (const [kind, list] of lists) {
        if (!Array.isArray(list))
            continue;
        for (const e of list) {
            const run = e?.run_with;
            if (!e?.name || !run || typeof run.tool !== 'string' || !run.arguments || typeof run.arguments !== 'object')
                continue;
            const params = checkedParams(service, String(e.name), Array.isArray(e.params) ? e.params : undefined, run.arguments.filter);
            if (params === null)
                continue;
            out.push({
                kind,
                service,
                name: String(e.name),
                description: e.description,
                question: e.question,
                unit: e.unit,
                examples: Array.isArray(e.examples) ? e.examples.map(String) : undefined,
                params,
                tool: run.tool,
                arguments: run.arguments,
            });
        }
    }
    return out;
}
/** limit / offset are the query tools' own paging arguments. */
const RESERVED_PARAMS = new Set(['limit', 'offset']);
/**
 * Params as a named tool can use them. Placeholders are filled only in
 * `filter`, so a declared param the filter never uses is dropped (with a
 * warning) rather than advertised as an argument that does nothing. null
 * (skip the entry): a param named limit/offset (df-semantic rejects those
 * too) or a placeholder with no declared param, which could never be filled.
 */
function checkedParams(service, name, params, filter) {
    const used = new Set();
    if (typeof filter === 'string')
        for (const m of filter.matchAll(/\{([A-Za-z_][A-Za-z0-9_]*)\}/g))
            used.add(m[1]);
    const declared = (params ?? []).filter(p => p && typeof p.name === 'string');
    const reserved = declared.filter(p => RESERVED_PARAMS.has(p.name));
    if (reserved.length) {
        console.warn(`[semantic-tools] ${service}: "${name}" declares ${reserved.map(p => p.name).join(', ')}, which collide with the paging arguments; skipped`);
        return null;
    }
    const undeclared = [...used].filter(u => !declared.some(p => p.name === u));
    if (undeclared.length) {
        console.warn(`[semantic-tools] ${service}: "${name}" filter uses ${undeclared.map(u => `{${u}}`).join(', ')} with no declared param; skipped`);
        return null;
    }
    const unused = declared.filter(p => !used.has(p.name));
    if (unused.length) {
        console.warn(`[semantic-tools] ${service}: "${name}" declares ${unused.map(p => p.name).join(', ')} but its filter never uses them; ignored`);
    }
    const kept = declared.filter(p => used.has(p.name));
    return params === undefined && kept.length === 0 ? undefined : kept;
}
/** Lowercase snake_case slug of an entry name. */
export function slug(name) {
    return name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'entry';
}
/**
 * Tool name for a runnable: `metric_<slug>` / `query_<slug>`, prefixed with the
 * service when the endpoint uses prefixed tools or exposes several databases
 * (so two databases with a "revenue" metric never collide). Capped at 64.
 */
export function toolNameFor(r, withService) {
    const base = `${r.kind}_${slug(r.name)}`;
    const name = withService ? `${sanitizeApiName(r.service)}_${base}` : base;
    return name.slice(0, MAX_NAME).replace(/_+$/, '');
}
function paramField(p) {
    let f;
    switch (p.type) {
        case 'integer':
            f = z.number().int();
            break;
        case 'number':
            f = z.number();
            break;
        case 'boolean':
            f = z.boolean();
            break;
        case 'date':
            f = z.string().describe(`${p.description ? p.description + '. ' : ''}Date, YYYY-MM-DD.`);
            break;
        default: f = z.string();
    }
    if (p.description && p.type !== 'date')
        f = f.describe(p.description);
    return p.required === false ? f.optional() : f;
}
/** Input schema: one argument per declared parameter; queries also take limit/offset. */
export function schemaFor(r) {
    const shape = {};
    for (const p of r.params ?? [])
        shape[p.name] = paramField(p);
    if (r.kind === 'query') {
        if (!shape.limit)
            shape.limit = z.number().int().min(1).max(1000).optional().describe('Override the row limit (max 1000).');
        if (!shape.offset)
            shape.offset = z.number().int().min(0).optional().describe('Rows to skip, to page through results.');
    }
    return z.object(shape);
}
const DATE_RE = /^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/;
// DreamFactory's filter parser splits on ") OR (" / ") AND (" even inside
// quotes and has no escape for a quote, so these can never ride in a value.
const UNSAFE_CHARS = /[()'"\\\u0000-\u001f]/;
const PLACEHOLDER = /^\{([A-Za-z_][A-Za-z0-9_]*)\}/;
/**
 * The filter text for one placeholder value (shared rule with df-semantic's
 * DryRun). Numbers: finite, String(Number(v)). Booleans: true / false.
 * Dates: YYYY-MM-DD[ HH:MM[:SS]]. Strings (and untyped): no ( ) ' " \ or
 * control characters, and no comma inside an IN (...) list; quotes are never
 * doubled. Inside a quoted literal the value goes in raw; outside, strings
 * and dates are single-quoted.
 */
function placeholderValue(name, value, type, inLiteral, inList) {
    if (value === undefined || value === null || value === '') {
        throw new Error(`missing value for parameter "${name}"`);
    }
    const reject = (why) => new Error(`parameter "${name}": ${why}`);
    if (type === 'integer' || type === 'number') {
        const n = Number(value);
        if (!Number.isFinite(n))
            throw reject(`expected a finite number, got ${JSON.stringify(value)}`);
        return String(n);
    }
    if (type === 'boolean') {
        if (value === true || value === 'true')
            return 'true';
        if (value === false || value === 'false')
            return 'false';
        throw reject(`expected true or false, got ${JSON.stringify(value)}`);
    }
    const s = String(value);
    if (type === 'date' && !DATE_RE.test(s))
        throw reject('expected a date, YYYY-MM-DD (optionally with HH:MM[:SS])');
    if (UNSAFE_CHARS.test(s))
        throw reject('the value may not contain ( ) \' " \\ or control characters');
    if (inList && s.includes(','))
        throw reject('a value inside an IN (...) list may not contain a comma; pass one value');
    return inLiteral ? s : `'${s}'`;
}
/**
 * Fill every {param} in a stored filter. Quote state comes from a left-to-right
 * scan (a placeholder is inside a literal when an odd number of unescaped
 * quotes precede it), not from the characters next to it; parentheses opened
 * right after `in` mark an IN list.
 */
export function fillFilter(filter, values, types) {
    let out = '';
    let quote = null;
    const parens = [];
    for (let i = 0; i < filter.length;) {
        const ch = filter[i];
        if (ch === '\\') {
            out += filter.slice(i, i + 2);
            i += 2;
            continue;
        }
        if (ch === '{') {
            const m = PLACEHOLDER.exec(filter.slice(i));
            if (m) {
                out += placeholderValue(m[1], values[m[1]], types.get(m[1]), quote !== null, parens[parens.length - 1] === true);
                i += m[0].length;
                continue;
            }
        }
        if (quote !== null) {
            if (ch === quote)
                quote = null;
        }
        else if (ch === "'" || ch === '"') {
            quote = ch;
        }
        else if (ch === '(') {
            parens.push(/\bin\s*$/i.test(out));
        }
        else if (ch === ')') {
            parens.pop();
        }
        out += ch;
        i++;
    }
    return out;
}
/**
 * Replace each {param} placeholder in the filter with the caller's value
 * (see fillFilter). A placeholder with no value is an error, never left in
 * the filter; so is a value that could break out of its literal.
 */
export function fillArguments(r, values) {
    const args = JSON.parse(JSON.stringify(r.arguments));
    const types = new Map((r.params ?? []).map(p => [p.name, p.type]));
    if (typeof args.filter === 'string') {
        args.filter = fillFilter(args.filter, values, types);
    }
    if (r.kind === 'query') {
        if (values.limit !== undefined)
            args.limit = values.limit;
        if (values.offset !== undefined)
            args.offset = values.offset;
    }
    return args;
}
function describe(r, label) {
    const what = r.kind === 'metric' ? 'Approved metric' : 'Verified query';
    const parts = [`[${label}] ${what} "${r.name}".`];
    if (r.question)
        parts.push(`Answers: ${r.question}`);
    if (r.description)
        parts.push(r.description);
    if (r.unit)
        parts.push(`Unit: ${r.unit}.`);
    if (r.examples?.length)
        parts.push(`Example questions: ${r.examples.slice(0, 3).join(' | ')}`);
    parts.push('Runs the definition the data owners approved; prefer it over composing the query yourself.');
    return parts.join(' ');
}
function authKey(baseUrl, auth) {
    return createHash('sha256').update(`${baseUrl}\n${auth.sessionToken ?? ''}\n${auth.apiKey ?? ''}`).digest('hex');
}
/** Approved metrics and queries for every database the caller can see, cached 30 s per credential. */
export async function loadSemanticRunnables(apiConfigs, auth) {
    const dbs = apiConfigs.filter(c => c.category === 'database');
    const lists = await Promise.all(dbs.map(async (c) => {
        const key = authKey(c.baseUrl, auth);
        const hit = cacheGet(key);
        if (hit)
            return hit;
        let timer;
        try {
            const data = await Promise.race([
                DreamFactoryService.getApiSpec(c.baseUrl, auth, { model: 'semantics' }),
                new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('timed out')), FETCH_TIMEOUT_MS); }),
            ]);
            const value = runnablesFrom(c.name, data?.semantics);
            cachePut(key, value, CACHE_TTL_MS);
            // Logged once per load (every 30 s per credential at most), not per request.
            if (value.length)
                console.log(`[semantic-tools] ${c.name}: ${value.length} approved definition(s) loaded: ${value.map(r => `${r.kind}:${r.name}`).join(', ')}`);
            return value;
        }
        catch (e) {
            console.warn(`[semantic-tools] ${c.name}: semantics not loaded (${e instanceof Error ? e.message : e}); no named tools for it for ${NEGATIVE_TTL_MS / 1000} s`);
            cachePut(key, [], NEGATIVE_TTL_MS);
            return [];
        }
        finally {
            clearTimeout(timer);
        }
    }));
    return lists.flat();
}
/** Test hook. */
export function clearSemanticCache() {
    cache.clear();
}
/** Test hook. */
export function semanticCacheSize() {
    return cache.size;
}
/**
 * Register one tool per runnable. Pinned: under the lazy facade they stay in
 * tools/list (they are the point of opting in), and they never count against
 * the catalog size that switches the facade on.
 */
export function registerSemanticTools(server, sessionManager, apiConfigs, runnables, toolStyle, disabledTools, features = {}) {
    if (runnables.length === 0)
        return [];
    const registerTool = createToolRegistrar(server, disabledTools);
    const byName = new Map(apiConfigs.map(c => [c.name, c]));
    const dbCount = apiConfigs.filter(c => c.category === 'database').length;
    const withService = toolStyle !== 'merged' || dbCount > 1;
    // A metric's alias is part of its approved definition, so named tools always
    // apply aggregate aliases (semanticCatalog: true here touches only that; the
    // tools themselves exist only under the semantic_tools opt-in).
    const handlers = new Map(baseTools({ semanticCatalog: true, compactResults: features.compactResults })
        .filter(t => SEMANTIC_BASE_TOOLS.has(t.name))
        .map(t => [t.name, t]));
    const used = new Set();
    const names = [];
    // ponytail: _registeredTools is SDK-private (same trick as installArgNormalizer); the lazy catalog is the fallback.
    const registered = server._registeredTools;
    const taken = (name) => (registered !== undefined && name in registered) || lazyStateFor(server)?.catalog.has(name) === true;
    for (const r of runnables) {
        const apiConfig = byName.get(r.service);
        const base = handlers.get(r.tool);
        if (!apiConfig || !base) {
            if (apiConfig && !SEMANTIC_BASE_TOOLS.has(r.tool))
                console.warn(`[semantic-tools] ${r.service}: "${r.name}" runs ${r.tool}; only ${[...SEMANTIC_BASE_TOOLS].join(' / ')} can be named tools, skipped`);
            continue;
        }
        // Mirror disabled_tools for the verb the entry runs: prefixed per service
        // (hr_aggregate_data) or by bare name (aggregate_data), as registerMergedTools does.
        if (disabledTools?.has(r.tool) || disabledTools?.has(`${sanitizeApiName(r.service)}_${r.tool}`))
            continue;
        let name = toolNameFor(r, withService);
        for (let i = 2; used.has(name); i++)
            name = `${toolNameFor(r, withService).slice(0, MAX_NAME - 3)}_${i}`;
        used.add(name);
        // The SDK throws on a duplicate name, which would fail the whole request.
        // Anything registered before us (built-in, custom, function tool) wins.
        if (taken(name)) {
            console.warn(`[semantic-tools] ${r.service}: "${r.name}" would be ${name}, which is already registered; skipped`);
            continue;
        }
        names.push(name);
        registerTool(name, r.question ?? r.name, describe(r, apiConfig.name), schemaFor(r), async (params, context) => {
            let args;
            try {
                args = fillArguments(r, params ?? {});
            }
            catch (e) {
                return respond({ error: `${name}: ${e instanceof Error ? e.message : e}` });
            }
            const auth = getAuth(sessionManager, context.sessionId);
            return base.handler(args, context, apiConfig, auth, {});
        }, { annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false }, pinned: true });
    }
    return names;
}

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createServer, parseAvailableServicesList, parseMcpConfig } from '../utils/utils.js';
import { SessionService } from './session.service.js';
import { isFacadeTool, lazyStateFor } from './lazy.service.js';
import { annotationsFor } from './args.js';
import { sanitizeApiName } from './tool-utils.js';
import { DB_TOOL_NAMES } from './tools.service.js';
const LAZY_MODES = ['auto', 'on', 'off'];
const DB_VERBS = new Set(DB_TOOL_NAMES);
// ponytail: the only global tool that creates something; extend if more land.
const GLOBAL_WRITE = new Set(['request_access']);
export async function previewCatalog(input) {
    const serviceName = typeof input.serviceName === 'string' && input.serviceName !== '' ? input.serviceName : 'preview';
    const config = parseMcpConfig(input._mcpConfig);
    if (LAZY_MODES.includes(input.lazyMode)) {
        config.lazyMode = input.lazyMode;
    }
    // Base URL is never called — no tool runs — but ApiConfig requires one.
    const apiConfigs = Array.isArray(input._mcpAvailableServices)
        ? parseAvailableServicesList(input._mcpAvailableServices, 'http://preview.invalid/api/v2') ?? []
        : [];
    const server = createServer(serviceName, apiConfigs, new SessionService(), config.disabledTools, config.customTools, config.lazyMode, config.toolStyle, config.allowWrites);
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
        const decision = lazy ? lazy.decide() : 'direct';
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
    }
    finally {
        await client.close().catch(() => undefined);
        await server.close().catch(() => undefined);
    }
}
const readOnly = (t, verb) => t.annotations?.readOnlyHint ?? annotationsFor(verb ?? t.name).readOnlyHint === true;
function classify(t, apiConfigs, custom) {
    const base = { name: t.name, title: t.title, description: t.description };
    if (isFacadeTool(t.name)) {
        return { ...base, category: 'facade', write: t.name === 'call_tool' };
    }
    const c = custom.get(t.name);
    if (c) {
        return { ...base, category: 'custom', write: c.http_method ? c.http_method !== 'GET' : !readOnly(t) };
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
            write: !readOnly(t, t.name.slice(prefixed.prefix.length)),
            service: prefixed.api.name
        };
    }
    if (DB_VERBS.has(t.name)) {
        // Merged style: one bare verb, the `service` argument picks the database.
        return { ...base, category: 'database', write: !readOnly(t) };
    }
    if (t.name.startsWith('all_')) {
        return { ...base, category: t.name === 'all_list_files' ? 'file' : 'database', write: false };
    }
    return { ...base, category: 'global', write: GLOBAL_WRITE.has(t.name) };
}

import * as z from 'zod/v4';
import { lazyStateFor } from './lazy.service.js';
import { annotationsFor, registerArgSpec, serviceNameMap, specFor } from './args.js';
export const respond = (data) => {
    let text;
    try {
        text = JSON.stringify(data, null, 2) ?? 'null';
    }
    catch {
        text = String(data);
    }
    return { content: [{ type: 'text', text }] };
};
export const respondError = (message) => ({
    content: [{ type: 'text', text: message }],
    isError: true
});
export const handleError = (error, operation) => {
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
export function sanitizeApiName(name) {
    return name
        .toLowerCase()
        .replace(/[^a-z0-9]/g, '_')
        .replace(/_+/g, '_')
        .replace(/^_|_$/g, '');
}
export function getAuth(sessionManager, sessionId) {
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
export function createToolRegistrar(server, disabledTools) {
    return (name, title, description, schema, handler, opts = {}) => {
        if (disabledTools?.has(name)) {
            return;
        }
        const annotations = opts.annotations ?? annotationsFor(name);
        const spec = specFor(schema, opts.serviceNames);
        registerArgSpec(server, name, spec);
        // Lazy mode keeps a catalog of every tool so the facade can search,
        // describe and call them by name; results are shaped/paged when active.
        const lazy = lazyStateFor(server);
        lazy?.register({ name, title, description, schema, handler, annotations, spec });
        server.registerTool(name, { title, description, inputSchema: schema, annotations }, async (params, context) => {
            console.log(`[tool] ${name} called`);
            try {
                const result = await handler(params ?? {}, context ?? {});
                console.log(`[tool] ${name} completed, isError=${result?.isError ?? false}`);
                return lazy ? lazy.finish(name, result) : result;
            }
            catch (error) {
                console.error(`[tool] ${name} unhandled error:`, error);
                const failed = respondError(handleError(error, name));
                return lazy ? lazy.finish(name, failed) : failed;
            }
        });
    };
}
/**
 * Merged registration shared by database and file services (tool_style = 'merged').
 *
 * The prefixed scheme emits every verb once per service, so five services cost
 * 5 x N tools even though the schemas are identical apart from the prefix.
 * Merged mode registers each verb once and selects the backend with a `service`
 * argument.
 *
 * Per-service disables are preserved, not discarded. disabled_tools entries are
 * prefixed (dvdstore_delete_records, logs_delete_file), and a merged tool spans
 * every service, so:
 *
 *   - a verb disabled for EVERY exposed service (or disabled by its bare name)
 *     is not registered at all;
 *   - a verb disabled for SOME services is registered, but only the services it
 *     is still allowed on appear in the `service` enum, and the handler rejects
 *     any other service as a second line of defence. Without this, flipping
 *     tool_style to merged would silently re-enable a destructive tool an admin
 *     had turned off for one service.
 *
 * When exactly one service is allowed for a verb there is nothing to
 * disambiguate, so the `service` argument is omitted entirely and the call stays
 * a one-argument call.
 */
export function registerMergedTools(server, sessionManager, configs, tools, opts) {
    if (configs.length === 0) {
        return;
    }
    const { targetNoun, logLabel, disabledTools } = opts;
    const registerTool = createToolRegistrar(server, disabledTools);
    const notes = [];
    for (const tool of tools) {
        // Services this verb is still permitted on, after per-service disables.
        const allowed = configs.filter(c => !disabledTools?.has(`${sanitizeApiName(c.name)}_${tool.name}`));
        // Bare-name disable, or disabled everywhere: drop the tool entirely.
        if (disabledTools?.has(tool.name) || allowed.length === 0) {
            continue;
        }
        const blocked = configs.filter(c => !allowed.includes(c));
        if (blocked.length > 0) {
            notes.push(`${tool.name}: ${allowed.map(c => c.name).join(', ')} (blocked: ${blocked.map(c => c.name).join(', ')})`);
        }
        const allowedNames = allowed.map(c => c.name);
        const byName = new Map(allowed.map(c => [c.name, c]));
        const only = allowed.length === 1 ? allowed[0] : undefined;
        const schema = only
            ? tool.schema
            : tool.schema.extend({
                service: z
                    .enum(allowedNames)
                    .describe(`Which ${targetNoun} to target. One of: ${allowedNames.join(', ')}`)
            });
        const description = only
            ? `[${only.name}] ${tool.description}`
            : `${tool.description} Pass service= to choose the ${targetNoun} (${allowedNames.join(', ')}).`;
        registerTool(tool.name, tool.title, description, schema, async (params, context) => {
            const auth = getAuth(sessionManager, context.sessionId);
            if (only) {
                return tool.handler(params, context, only, auth, {});
            }
            const { service, ...rest } = (params ?? {});
            const apiConfig = service ? byName.get(service) : undefined;
            if (!apiConfig) {
                // Either no service was given, or one that is disabled for this verb
                // / not exposed at all. Say which, without leaking disabled names.
                return respond({
                    error: service
                        ? `"${tool.name}" is not available for service "${service}".`
                        : 'Missing required argument "service".',
                    available_services: allowedNames
                });
            }
            return tool.handler(rest, context, apiConfig, auth, { service: apiConfig.name });
        }, { serviceNames: only ? undefined : serviceNameMap(allowed) });
    }
    if (notes.length > 0) {
        console.log(`[${logLabel}] per-service disables enforced at call time; allowed services per verb: ` +
            notes.join('; '));
    }
}

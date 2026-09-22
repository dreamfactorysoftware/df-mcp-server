import { timingSafeEqual } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';
// Shared secret for the PHP proxy -> daemon hop. The PHP side (McpDaemonClient)
// uses MCP_INTERNAL_KEY when set, else generates a key and persists it to
// <app>/storage/framework/mcp_internal_key; the daemon reads the same value.
// Not storage/app: that is the root of the stock "files" service, so a key
// written there is downloadable over the REST API.
//
// The key is read lazily and cached, so a daemon that boots before PHP has
// written the file picks it up on the first request. Without it every /mcp
// request is rejected: the gate fails closed.
let cachedKey = '';
let warned = false;
export function resolveInternalKeyFile() {
    if (process.env.MCP_INTERNAL_KEY_FILE) {
        return process.env.MCP_INTERNAL_KEY_FILE;
    }
    if (process.env.DF_APP_ROOT) {
        return resolve(process.env.DF_APP_ROOT, 'storage/framework/mcp_internal_key');
    }
    // Walk up from this file looking for the app root (artisan beside storage/)
    // rather than counting directories: the package is five levels down in the
    // stock vendor layout, but not when it is symlinked in for development.
    let dir = dirname(fileURLToPath(import.meta.url));
    for (let i = 0; i < 8; i++) {
        if (existsSync(join(dir, 'artisan')) && existsSync(join(dir, 'storage', 'framework'))) {
            return join(dir, 'storage', 'framework', 'mcp_internal_key');
        }
        const parent = dirname(dir);
        if (parent === dir)
            break;
        dir = parent;
    }
    return '';
}
export function getInternalKey() {
    if (process.env.MCP_INTERNAL_KEY) {
        return process.env.MCP_INTERNAL_KEY;
    }
    if (cachedKey) {
        return cachedKey;
    }
    const file = resolveInternalKeyFile();
    if (!file) {
        warnNoKey('could not locate the DreamFactory app root from ' + dirname(fileURLToPath(import.meta.url)));
        return '';
    }
    try {
        cachedKey = readFileSync(file, 'utf8').trim();
    }
    catch {
        // Not written yet: PHP creates it on its first daemon call.
    }
    if (!cachedKey)
        warnNoKey('no key at ' + file);
    return cachedKey;
}
/** Constant-time compare; an empty expected key never matches. */
export function keyMatches(expected, presented) {
    if (typeof presented !== 'string' || expected === '' || presented === '') {
        return false;
    }
    const a = Buffer.from(expected);
    const b = Buffer.from(presented);
    return a.length === b.length && timingSafeEqual(a, b);
}
/**
 * True when the presented header matches the shared key. On a miss the cache is
 * dropped and the file re-read once, so a key PHP wrote or rotated after it was
 * cached does not need a daemon restart.
 */
export function authorizeInternalCall(presented) {
    if (keyMatches(getInternalKey(), presented)) {
        return true;
    }
    cachedKey = '';
    return keyMatches(getInternalKey(), presented);
}
/** Express gate for every /mcp route. /health and /ping stay open. */
export function internalKeyGate(req, res, next) {
    if (authorizeInternalCall(req.headers['x-mcp-internal-key'])) {
        next();
        return;
    }
    res.status(403).json({
        jsonrpc: '2.0',
        id: null,
        error: { code: -32001, message: 'Forbidden: invalid internal key' },
    });
}
// Said once, loudly: without a key every request gets a 403 that otherwise
// looks exactly like a genuinely unauthorized caller.
function warnNoKey(detail) {
    if (warned)
        return;
    warned = true;
    console.error(`[mcp-daemon] Shared secret unavailable (${detail}). Every /mcp request is ` +
        'rejected with 403 until it is. Set MCP_INTERNAL_KEY_FILE to the file the PHP ' +
        'side writes (storage/framework/mcp_internal_key), DF_APP_ROOT to the ' +
        'application root, or MCP_INTERNAL_KEY to the same value DreamFactory uses.');
}

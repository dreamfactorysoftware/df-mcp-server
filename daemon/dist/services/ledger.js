import { AsyncLocalStorage } from 'node:async_hooks';
const store = new AsyncLocalStorage();
export const runWithResponse = (res, fn) => store.run({ res, fields: {} }, fn);
function flush(s) {
    if (s.res.headersSent)
        return;
    s.res.setHeader('X-Mcp-Ledger', JSON.stringify(s.fields));
}
/** Overwrite ledger fields for this request. No-op outside a request. */
export function ledgerSet(fields) {
    const s = store.getStore();
    if (!s)
        return;
    Object.assign(s.fields, fields);
    flush(s);
}
/** Add to numeric ledger counters (a facade call normalises twice: the envelope, then the inner arguments). */
export function ledgerBump(fields) {
    const s = store.getStore();
    if (!s)
        return;
    for (const [k, n] of Object.entries(fields)) {
        s.fields[k] = Number(s.fields[k] ?? 0) + n;
    }
    flush(s);
}

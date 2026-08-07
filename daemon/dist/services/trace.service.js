import { AsyncLocalStorage } from 'node:async_hooks';
/**
 * Per-request trace context. The daemon is one long-lived process serving many
 * MCP sessions, so the platform trace id (X-DreamFactory-Trace-Id, minted by
 * DF PHP per inbound request) is carried through async continuations with ALS
 * and re-attached to every DreamFactory REST call the tool handlers make.
 * That joins mcp_request_log rows with the data-plane rows of the same action.
 */
const store = new AsyncLocalStorage();
const VALID = /^[A-Za-z0-9._-]{8,64}$/;
export function runWithTrace(traceId, fn) {
    if (traceId && VALID.test(traceId)) {
        return store.run(traceId, fn);
    }
    return fn();
}
export function currentTraceId() {
    return store.getStore();
}

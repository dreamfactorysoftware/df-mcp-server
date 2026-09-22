import { AsyncLocalStorage } from 'node:async_hooks';
import type { Response } from 'express';

/**
 * Per-request ledger: the express Response for the request being served plus
 * the fields tool handlers want PHP to persist into mcp_request_log. Every
 * update rewrites the X-Mcp-Ledger header; PHP strips it before the client
 * sees the response (McpStreamController) and stores the known keys.
 */
type Store = { res: Response; fields: Record<string, number | string> };

const store = new AsyncLocalStorage<Store>();

export const runWithResponse = <T>(res: Response, fn: () => T): T => store.run({ res, fields: {} }, fn);

function flush(s: Store): void {
  if (s.res.headersSent) return;
  s.res.setHeader('X-Mcp-Ledger', JSON.stringify(s.fields));
}

/** Overwrite ledger fields for this request. No-op outside a request. */
export function ledgerSet(fields: Record<string, number | string>): void {
  const s = store.getStore();
  if (!s) return;
  Object.assign(s.fields, fields);
  flush(s);
}

/** Add to numeric ledger counters (a facade call normalises twice: the envelope, then the inner arguments). */
export function ledgerBump(fields: Record<string, number>): void {
  const s = store.getStore();
  if (!s) return;
  for (const [k, n] of Object.entries(fields)) {
    s.fields[k] = Number(s.fields[k] ?? 0) + n;
  }
  flush(s);
}

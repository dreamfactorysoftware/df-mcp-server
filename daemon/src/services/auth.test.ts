import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { Request } from 'express';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createServer, updateSessionConfigFromHeaders } from '../utils/utils.js';
import { SessionService } from './session.service.js';
import type { ApiConfig } from '../types.js';

// Run: npm test (node --import tsx --test)
//
// API-key authentication (per-service opt-in on the PHP side) composed with
// lazy mode (issue #52): a key-only session must work identically whether a
// catalog tool is invoked directly by name or through the facade's call_tool —
// the DF REST sub-call must carry exactly the credential set the PHP proxy
// validated. And on session resume the stored credentials are REPLACED with
// what the current request carried: a stale session token must never be
// resurrected by a later key-only request on the same MCP session.

const API_KEY = 'a'.repeat(64); // DF keys are 64-hex (sha256)
const db = (name: string): ApiConfig => ({ name, baseUrl: `http://df/api/v2/${name}`, category: 'database', type: 'mysql' });

type CapturedCall = { url: string; headers: Record<string, string> };

/** Stub global fetch, capturing every DF REST sub-call the daemon makes. */
function stubFetch(calls: CapturedCall[]): () => void {
  const original = globalThis.fetch;
  globalThis.fetch = (async (input: any, init?: any) => {
    calls.push({
      url: String(input instanceof URL ? input.toString() : (input?.url ?? input)),
      headers: { ...(init?.headers ?? {}) },
    });
    return new Response(JSON.stringify({ resource: [{ name: 'orders' }] }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });
  }) as typeof fetch;
  return () => { globalThis.fetch = original; };
}

async function connect(sessions: SessionService, apiConfigs: ApiConfig[], service: string) {
  const server = createServer(service, apiConfigs, sessions, undefined, undefined, 'on');
  const client = new Client({ name: 'claude-code', version: '1' });
  const [a, b] = InMemoryTransport.createLinkedPair();
  await server.connect(a);
  await client.connect(b);
  return client;
}

const text = (r: any) => r.content[0].text as string;

test('key-only auth: the lazy facade forwards the API key to DF REST calls exactly as direct calls do', async () => {
  const sessions = new SessionService();
  // Stateless-mode slot: the PHP proxy validated an API-key-only request and
  // forwarded just the key — no DF session token exists for this session.
  sessions.setDefaultConfig({ url: 'http://df/api/v2', apiKey: API_KEY, apiConfigs: [db('ordersdb')] });

  const client = await connect(sessions, [db('ordersdb')], 'apikey-lazy');
  const calls: CapturedCall[] = [];
  const restore = stubFetch(calls);
  try {
    // Through the facade (lazy 'on' advertises only the facade).
    const viaFacade = await client.callTool({ name: 'call_tool', arguments: { name: 'ordersdb_get_tables', arguments: {} } });
    assert.notEqual(viaFacade.isError, true, `facade call failed: ${text(viaFacade)}`);
    assert.match(text(viaFacade), /orders/);

    // Directly by name (every catalog tool stays registered under lazy mode).
    const direct = await client.callTool({ name: 'ordersdb_get_tables', arguments: {} });
    assert.notEqual(direct.isError, true, `direct call failed: ${text(direct)}`);

    assert.equal(calls.length, 2, 'both invocations reach DF REST');
    for (const call of calls) {
      assert.equal(call.url, 'http://df/api/v2/ordersdb/_schema');
      assert.equal(call.headers['X-DreamFactory-API-Key'], API_KEY, 'key credential forwarded');
      assert.ok(!('X-DreamFactory-Session-Token' in call.headers), 'no session token invented for key-only auth');
    }
    // Audit parity: the facade path and the direct path emit the identical credential set.
    assert.deepEqual(calls[0].headers, calls[1].headers, 'facade and direct calls carry identical auth headers');
  } finally {
    restore();
  }
});

test('layered auth: session token + API key both flow through the facade', async () => {
  const sessions = new SessionService();
  sessions.setDefaultConfig({ url: 'http://df/api/v2', sessionToken: 'user-jwt', apiKey: API_KEY, apiConfigs: [db('ordersdb')] });

  const client = await connect(sessions, [db('ordersdb')], 'apikey-layered');
  const calls: CapturedCall[] = [];
  const restore = stubFetch(calls);
  try {
    const r = await client.callTool({ name: 'call_tool', arguments: { name: 'ordersdb_get_tables', arguments: {} } });
    assert.notEqual(r.isError, true, `facade call failed: ${text(r)}`);
    assert.equal(calls[0].headers['X-DreamFactory-Session-Token'], 'user-jwt');
    assert.equal(calls[0].headers['X-DreamFactory-API-Key'], API_KEY);
  } finally {
    restore();
  }
});

/** Minimal express-Request stand-in for updateSessionConfigFromHeaders. */
const fakeReq = (headers: Record<string, string | undefined>): Request =>
  ({ header: (name: string) => headers[name] } as unknown as Request);

test('session resume REPLACES credentials: a stale session token is not resurrected by a key-only request', () => {
  const sessions = new SessionService();
  const apiConfigs = [db('ordersdb')];
  // Session was initialized with a (since expired) user token layered on the key.
  sessions.setConfig('sid-1', { url: 'http://df/api/v2', sessionToken: 'stale-user-jwt', apiKey: API_KEY, apiConfigs });

  // The next request on the same MCP session authenticated key-only at the
  // PHP proxy, so only the key is forwarded. The stored credential set must
  // become exactly that — the stale token must not survive the resume.
  const updated = updateSessionConfigFromHeaders(
    fakeReq({ 'X-Mcp-Base-Url': 'http://df/api/v2', 'X-DreamFactory-API-Key': API_KEY }),
    sessions,
    'sid-1'
  );
  assert.equal(updated, true);
  const config = sessions.getConfig('sid-1');
  assert.equal(config?.sessionToken, undefined, 'stale session token replaced, not resurrected');
  assert.equal(config?.apiKey, API_KEY);
  assert.equal(config?.apiConfigs, apiConfigs, 'discovered apiConfigs survive the credential replacement');

  // Lazy-mode hot-tool tracking is keyed by service name and stores tool
  // names only — nothing it keeps can re-introduce credentials, so the
  // replaced config above is the entire credential state for the session.
});

test('session resume rejects credential-less updates and keeps the last validated set', () => {
  const sessions = new SessionService();
  sessions.setConfig('sid-2', { url: 'http://df/api/v2', apiKey: API_KEY });

  // No credentials at all -> not an update (the PHP proxy would have 401ed).
  assert.equal(
    updateSessionConfigFromHeaders(fakeReq({ 'X-Mcp-Base-Url': 'http://df/api/v2' }), sessions, 'sid-2'),
    false
  );
  // Whitespace-only headers normalize to absent credentials.
  assert.equal(
    updateSessionConfigFromHeaders(
      fakeReq({ 'X-Mcp-Base-Url': 'http://df/api/v2', 'X-DreamFactory-API-Key': '   ', 'X-DreamFactory-Session-Token': '' }),
      sessions,
      'sid-2'
    ),
    false
  );
  assert.equal(sessions.getConfig('sid-2')?.apiKey, API_KEY, 'last validated credentials kept');
});

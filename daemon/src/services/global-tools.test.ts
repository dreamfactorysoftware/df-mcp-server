import { test } from 'node:test';
import assert from 'node:assert/strict';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { SessionService } from './session.service.js';
import { registerGlobalTools } from './global-tools.service.js';

// Run: npm test (node --import tsx --test)
//
// Stateless mode (the default) issues no session IDs, so handlers run with an
// undefined sessionId and the per-request config sits in the SessionService
// default slot. discover_services and request_access used to require a session
// ID and failed every stateless call with 'DreamFactory session not found'.

const text = (r: any) => r.content[0].text as string;

async function connect(sessions: SessionService) {
  const server = new McpServer({ name: 'svc', version: '1' });
  registerGlobalTools(server, sessions);
  const client = new Client({ name: 'test', version: '1' });
  const [a, b] = InMemoryTransport.createLinkedPair();
  await server.connect(a);
  await client.connect(b);
  return client;
}

function stubFetch(calls: { url: string; method: string; headers: Record<string, string>; body?: string }[]) {
  const original = globalThis.fetch;
  globalThis.fetch = (async (input: any, init?: any) => {
    calls.push({ url: String(input), method: init?.method, headers: init?.headers ?? {}, body: init?.body });
    return new Response(JSON.stringify({ ok: true }), { status: 200 });
  }) as typeof fetch;
  return () => { globalThis.fetch = original; };
}

test('global tools use the stateless default config when there is no session ID', async () => {
  const sessions = new SessionService();
  sessions.setDefaultConfig({ url: 'http://df/api/v2', apiKey: 'app-key', sessionToken: 'jwt' });
  const client = await connect(sessions);
  const calls: { url: string; method: string; headers: Record<string, string>; body?: string }[] = [];
  const restore = stubFetch(calls);
  try {
    const discover: any = await client.callTool({ name: 'discover_services', arguments: {} });
    assert.ok(!discover.isError, text(discover));
    const request: any = await client.callTool({
      name: 'request_access',
      arguments: { services: ['db'], operations: ['GET'], note: 'why' },
    });
    assert.ok(!request.isError, text(request));
  } finally {
    restore();
  }
  assert.equal(calls.length, 2);
  assert.equal(calls[0].method, 'GET');
  assert.equal(calls[0].url, 'http://df/api/v2/agent/catalog');
  assert.equal(calls[0].headers['X-DreamFactory-API-Key'], 'app-key');
  assert.equal(calls[0].headers['X-DreamFactory-Session-Token'], 'jwt');
  assert.equal(calls[1].method, 'POST');
  assert.equal(calls[1].url, 'http://df/api/v2/agent/request_access');
  assert.deepEqual(JSON.parse(calls[1].body!), { services: ['db'], operations: ['GET'], note: 'why' });
});

test('global tools still refuse when no config is available', async () => {
  const client = await connect(new SessionService());
  const calls: { url: string; method: string; headers: Record<string, string>; body?: string }[] = [];
  const restore = stubFetch(calls);
  try {
    for (const name of ['discover_services', 'request_access']) {
      const r: any = await client.callTool({ name, arguments: name === 'request_access' ? { services: [], operations: [] } : {} });
      assert.ok(r.isError, name);
      assert.match(text(r), /DreamFactory session not found/);
    }
  } finally {
    restore();
  }
  assert.equal(calls.length, 0, 'no DreamFactory call without credentials');
});

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createServer } from '../utils/utils.js';
import { SessionService } from './session.service.js';
import { handleError, WRITE_VERBS } from './tool-utils.js';
import type { ApiConfig, ToolStyle } from '../types.js';
import type { LazyMode } from './lazy.service.js';

// Run: npm test (node --import tsx --test)
//
// Server-wide writes switch (issue #67): with allow_writes=false the daemon
// never registers a write verb — prefixed or merged style, database or file —
// so tools/list cannot advertise one and the lazy facade's call_tool cannot
// reach one. Reads, list_apis and the all_* aggregators are untouched.
// Separately, a DreamFactory role denial must surface as a permission error,
// never as an authentication error.

const db = (name: string): ApiConfig => ({ name, baseUrl: `http://df/api/v2/${name}`, category: 'database', type: 'mysql' });
const file = (name: string): ApiConfig => ({ name, baseUrl: `http://df/api/v2/${name}`, category: 'file', type: 'local_file' });

async function connect(apiConfigs: ApiConfig[], opts: { style?: ToolStyle; lazy?: LazyMode; writes?: boolean; sessions?: SessionService } = {}) {
  const server = createServer('svc', apiConfigs, opts.sessions ?? new SessionService(), undefined, undefined, opts.lazy ?? 'off', opts.style ?? 'prefixed', opts.writes ?? true);
  const client = new Client({ name: 'claude-code', version: '1' });
  const [a, b] = InMemoryTransport.createLinkedPair();
  await server.connect(a);
  await client.connect(b);
  return client;
}

const text = (r: any) => r.content[0].text as string;
const names = async (c: Client) => (await c.listTools()).tools.map(t => t.name);
const isWrite = (name: string) => [...WRITE_VERBS].some(v => name === v || name.endsWith(`_${v}`));

test('prefixed style: allow_writes=false drops every write verb, keeps reads and aggregators', async () => {
  const apis = [db('sales'), db('orders'), file('docs'), file('media')];
  const withWrites = await names(await connect(apis));
  const readOnly = await names(await connect(apis, { writes: false }));

  assert.ok(withWrites.some(isWrite), 'default advertises write verbs');
  assert.equal(readOnly.filter(isWrite).length, 0, `read-only must advertise no write verb, got ${readOnly.filter(isWrite)}`);
  for (const v of WRITE_VERBS) {
    assert.ok(!readOnly.includes(`sales_${v}`) && !readOnly.includes(`docs_${v}`), v);
  }
  // Everything that is not a write verb is still there.
  assert.deepEqual(readOnly.sort(), withWrites.filter(n => !isWrite(n)).sort());
  for (const keep of ['list_apis', 'all_get_tables', 'all_list_files', 'sales_get_table_data', 'sales_aggregate_data', 'docs_list_files', 'docs_get_file']) {
    assert.ok(readOnly.includes(keep), keep);
  }
});

test('merged style: allow_writes=false drops the shared write verbs', async () => {
  const apis = [db('sales'), db('orders')];
  const withWrites = await names(await connect(apis, { style: 'merged' }));
  const readOnly = await names(await connect(apis, { style: 'merged', writes: false }));

  assert.ok(withWrites.includes('delete_records'));
  assert.equal(readOnly.filter(isWrite).length, 0, `got ${readOnly.filter(isWrite)}`);
  assert.deepEqual(readOnly.sort(), withWrites.filter(n => !isWrite(n)).sort());
  assert.ok(readOnly.includes('get_table_data') && readOnly.includes('list_apis'));
});

test('lazy facade: call_tool / describe_tool / search_tools cannot reach a write verb', async () => {
  const client = await connect([db('sales'), file('docs')], { lazy: 'on', writes: false });
  assert.deepEqual((await names(client)).sort(), ['call_tool', 'describe_tool', 'fetch_more', 'list_tools', 'search_tools']);

  const call = await client.callTool({ name: 'call_tool', arguments: { name: 'sales_delete_records', arguments: { tableName: 't', ids: ['1'] } } });
  assert.equal(call.isError, true);
  assert.match(text(call), /unknown tool sales_delete_records/);

  const describe = await client.callTool({ name: 'describe_tool', arguments: { name: 'docs_delete_file' } });
  assert.equal(describe.isError, true);

  const hits = JSON.parse(text(await client.callTool({ name: 'search_tools', arguments: { query: 'create update delete records file folder procedure', limit: 50 } })));
  assert.ok(hits.tools.length > 0);
  assert.ok(hits.tools.every((t: any) => !isWrite(t.name)), `facade leaked ${hits.tools.map((t: any) => t.name)}`);

  // Reads still work through the facade.
  const ok = await client.callTool({ name: 'describe_tool', arguments: { name: 'sales_get_tables' } });
  assert.notEqual(ok.isError, true);
});

test('server instructions say the server is read-only only when writes are off', async () => {
  const on = await connect([db('sales'), file('docs')]);
  const off = await connect([db('sales'), file('docs')], { writes: false });
  assert.doesNotMatch(on.getInstructions() ?? '', /READ-ONLY/);
  assert.match(off.getInstructions() ?? '', /THIS SERVER IS READ-ONLY/);
  assert.doesNotMatch(off.getInstructions() ?? '', /create_records|call_stored_procedure|create_folder|delete_file/);
});

test('handleError: DF role denials are permission errors, never authentication errors', () => {
  const denied401 = new Error('{"error":{"code":401,"message":"User is not authenticated.","status_code":401}}');
  const denied403 = new Error('{"error":{"code":403,"message":"Access Forbidden.","status_code":403}}');
  for (const e of [denied401, denied403]) {
    const msg = handleError(e, 'sales_delete_records');
    assert.match(msg, /^Permission Error: the session's role may not sales_delete_records/);
    assert.doesNotMatch(msg, /Authentication Error/);
  }
  // A genuinely bad credential is still an authentication error.
  assert.match(handleError(new Error('{"error":{"code":401,"message":"Invalid token."}}'), 'x'), /^Authentication Error/);
});

test('a key-backed call denied by role reaches the client as isError Permission Error', async () => {
  const original = globalThis.fetch;
  globalThis.fetch = (async () => new Response(
    JSON.stringify({ error: { code: 401, message: 'User is not authenticated.', status_code: 401 } }),
    { status: 401, headers: { 'Content-Type': 'application/json' } }
  )) as typeof fetch;
  try {
    const sessions = new SessionService();
    sessions.setDefaultConfig({ url: 'http://df/api/v2', apiKey: 'a'.repeat(64), apiConfigs: [db('sales')] });
    const client = await connect([db('sales')], { sessions });
    const r = await client.callTool({ name: 'sales_delete_records', arguments: { tableName: 'orders', ids: ['1'] } });
    assert.equal(r.isError, true);
    assert.match(text(r), /^Permission Error/);
    assert.doesNotMatch(text(r), /Authentication Error/);
  } finally {
    globalThis.fetch = original;
  }
});

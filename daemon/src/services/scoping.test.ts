import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createServer } from '../utils/utils.js';
import { SessionService } from './session.service.js';
import type { ApiConfig } from '../types.js';
import type { LazyMode } from './lazy.service.js';

// Run: npm test (node --import tsx --test)
//
// Exposed Services scoping (issue #48) composed with lazy mode (issue #52):
// PHP resolves the scoped backend catalog and the daemon must treat it as the
// whole world. The lazy facade (search_tools / describe_tool / call_tool)
// operates on the catalog built from the scoped apiConfigs — it must never
// surface, describe or invoke a tool for a service PHP scoped out, an empty
// scoped catalog must stay empty, and the auto threshold must be computed
// over the scoped list, not the instance-wide one.

const db = (name: string): ApiConfig => ({ name, baseUrl: `http://df/api/v2/${name}`, category: 'database', type: 'mysql' });
const file = (name: string): ApiConfig => ({ name, baseUrl: `http://df/api/v2/${name}`, category: 'file', type: 'local_file' });

// Global (non-service-prefixed) tools that register regardless of scoping.
const GLOBAL = ['discover_services', 'request_access', 'list_apis', 'search', 'fetch'];
const FACADE = ['call_tool', 'describe_tool', 'fetch_more', 'list_tools', 'search_tools'];

async function connect(apiConfigs: ApiConfig[], service: string, mode: LazyMode, clientName = 'claude-code') {
  const server = createServer(service, apiConfigs, new SessionService(), undefined, undefined, mode);
  const client = new Client({ name: clientName, version: '1' });
  const [a, b] = InMemoryTransport.createLinkedPair();
  await server.connect(a);
  await client.connect(b);
  return client;
}

const text = (r: any) => r.content[0].text as string;
const names = async (c: Client) => (await c.listTools()).tools.map(t => t.name);

test('lazy facade operates on the scoped catalog only', async () => {
  // PHP scoped this connection to ordersdb; customersdb exists on the
  // instance but was not exposed, so it never reaches the daemon.
  const client = await connect([db('ordersdb'), file('docs')], 'scoped-one-db', 'on');

  assert.deepEqual((await names(client)).sort(), FACADE, 'on: facade only');

  // search_tools finds the scoped service's tools...
  const hits = JSON.parse(text(await client.callTool({ name: 'search_tools', arguments: { query: 'get tables' } })));
  assert.ok(hits.tools.some((t: any) => t.name === 'ordersdb_get_tables'));
  // ...and can never surface a tool for a service outside exposed_services.
  const leak = JSON.parse(text(await client.callTool({ name: 'search_tools', arguments: { query: 'customersdb get tables data', limit: 50 } })));
  assert.ok(leak.tools.every((t: any) => !t.name.startsWith('customersdb_')), 'unscoped service is not searchable');

  // Shortened per-verb descriptions survive into the facade's catalog.
  const desc = JSON.parse(text(await client.callTool({ name: 'describe_tool', arguments: { name: 'ordersdb_get_tables' } })));
  assert.equal(desc.description, '[ordersdb] List tables in this database. Prefer get_data_model for columns and relationships.');

  // describe/call refuse tools of unscoped services — nothing is invoked.
  const unknownDescribe = await client.callTool({ name: 'describe_tool', arguments: { name: 'customersdb_get_tables' } });
  assert.equal(unknownDescribe.isError, true);
  assert.match(text(unknownDescribe), /unknown tool customersdb_get_tables/);
  const unknownCall = await client.callTool({ name: 'call_tool', arguments: { name: 'customersdb_get_table_data', arguments: { tableName: 't' } } });
  assert.equal(unknownCall.isError, true);
  assert.match(text(unknownCall), /unknown tool/);
});

test('conditional all_* aggregators follow the scoped catalog into the facade', async () => {
  // One database + one file service: cross-service aggregators must not
  // register, so the facade must not know them either.
  const single = await connect([db('ordersdb'), file('docs')], 'scoped-single', 'on');
  const s1 = JSON.parse(text(await single.callTool({ name: 'search_tools', arguments: { query: 'all get tables every database', limit: 50 } })));
  assert.ok(s1.tools.every((t: any) => t.name !== 'all_get_tables'), 'all_get_tables absent with 1 scoped db');
  const d1 = await single.callTool({ name: 'describe_tool', arguments: { name: 'all_get_tables' } });
  assert.equal(d1.isError, true);
  const f1 = await single.callTool({ name: 'describe_tool', arguments: { name: 'all_list_files' } });
  assert.equal(f1.isError, true);

  // Two of each category in the scoped catalog: aggregators register and are
  // indexed/describable through the facade.
  const dual = await connect([db('ordersdb'), db('hrdb'), file('docs'), file('media')], 'scoped-dual', 'on');
  const d2 = JSON.parse(text(await dual.callTool({ name: 'describe_tool', arguments: { name: 'all_get_tables' } })));
  assert.equal(d2.name, 'all_get_tables');
  const f2 = JSON.parse(text(await dual.callTool({ name: 'describe_tool', arguments: { name: 'all_list_files' } })));
  assert.equal(f2.name, 'all_list_files');
  const s2 = JSON.parse(text(await dual.callTool({ name: 'search_tools', arguments: { query: 'tables from all databases', limit: 50 } })));
  assert.ok(s2.tools.some((t: any) => t.name === 'all_get_tables'), 'aggregator searchable with 2 scoped dbs');
});

test('an empty scoped catalog stays empty through every lazy path', async () => {
  // PHP sent [] (authoritative: no exposed services). No DB/file verbs may
  // appear through the facade, and nothing may rediscover them.
  const lazy = await connect([], 'scoped-empty-on', 'on');
  assert.deepEqual((await names(lazy)).sort(), FACADE);
  const hits = JSON.parse(text(await lazy.callTool({ name: 'search_tools', arguments: { query: 'table data rows database', limit: 50 } })));
  assert.ok(hits.tools.every((t: any) => GLOBAL.includes(t.name)), `only globals searchable, got ${JSON.stringify(hits.tools)}`);
  const call = await lazy.callTool({ name: 'call_tool', arguments: { name: 'db_get_table_data', arguments: { tableName: 't' } } });
  assert.equal(call.isError, true);
  assert.match(text(call), /unknown tool/);

  // auto with an empty catalog: small list → direct mode; the advertised
  // list is exactly the globals — no re-expansion into DB/file tools.
  const direct = await connect([], 'scoped-empty-auto', 'auto');
  assert.deepEqual((await names(direct)).sort(), [...GLOBAL].sort());
});

test('auto threshold is computed over the scoped tools/list', async () => {
  // Scoped down to one database, the serialized catalog is small, so auto
  // stays direct even though the instance could hold dozens of services.
  const small = await connect([db('ordersdb')], 'scoped-auto-small', 'auto');
  const smallNames = await names(small);
  assert.ok(smallNames.includes('ordersdb_get_table_data'), 'direct mode advertises the scoped tools');
  assert.ok(!smallNames.includes('search_tools'), 'facade hidden while direct');

  // The same MCP service scoped to many databases crosses ~32KB and flips to
  // the facade — the decision tracks the scoped catalog, not the instance.
  const many = Array.from({ length: 20 }, (_, i) => db(`warehouse${i}`));
  const large = await connect(many, 'scoped-auto-large', 'auto');
  assert.deepEqual((await names(large)).sort(), FACADE, 'auto goes lazy once the scoped list is large');
});

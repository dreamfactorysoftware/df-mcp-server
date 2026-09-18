import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createServer } from '../utils/utils.js';
import { SessionService } from './session.service.js';
import { annotationsFor, normalizeArgs, serviceNameMap, snake } from './args.js';
import { runWithResponse } from './ledger.js';
import type { ApiConfig, CustomToolDefinition } from '../types.js';

// Run: npm test (node --import tsx --test)
//
// Issue #66: snake_case argument names, camelCase aliases, a normaliser that
// rejects unknown keys with a suggestion, tool annotations, and arg_* ledger
// counters. Every assertion here fails on the pre-#66 daemon.

const db = (name: string, label?: string): ApiConfig =>
  ({ name, baseUrl: `http://df/api/v2/${name}`, category: 'database', type: 'mysql', label });

const text = (r: any) => r.content[0].text as string;

/** Minimal express Response stand-in: enough for the ledger header. */
function fakeRes() {
  const headers: Record<string, string> = {};
  return {
    headersSent: false,
    setHeader: (k: string, v: string) => { headers[k] = v; },
    ledger: () => JSON.parse(headers['X-Mcp-Ledger'] ?? '{}')
  };
}

async function connect(apiConfigs: ApiConfig[], opts: { mode?: 'on' | 'off' | 'auto'; style?: 'prefixed' | 'merged'; custom?: CustomToolDefinition[] } = {}) {
  const sessions = new SessionService();
  // A session with a (fake) API key so handlers pass getAuth and reach the REST layer, which we stub.
  const server = createServer('svc', apiConfigs, sessions, undefined, opts.custom, opts.mode ?? 'off', opts.style ?? 'prefixed');
  const client = new Client({ name: 'claude-code', version: '1' });
  const [a, b] = InMemoryTransport.createLinkedPair();
  await server.connect(a);
  await client.connect(b);
  return client;
}

test('snake: every casing collapses to snake_case', () => {
  for (const k of ['tableName', 'TableName', 'table-name', 'TABLE_NAME', 'table name', 'table_name']) {
    assert.equal(snake(k), 'table_name', k);
  }
  assert.equal(snake('asAccessList'), 'as_access_list');
  assert.equal(snake('groupBy'), 'group_by');
  assert.equal(snake('ids'), 'ids');
});

test('normalizeArgs: aliases, unknown keys with suggestion, duplicates, service labels', () => {
  const spec = { keys: ['table_name', 'count_only', 'limit'] };

  const ok = normalizeArgs(spec, { tableName: 't', COUNT_ONLY: true, limit: 5 });
  assert.deepEqual(ok, { args: { table_name: 't', count_only: true, limit: 5 }, aliases: ['tableName→table_name', 'COUNT_ONLY→count_only'], errors: [] });

  const typo = normalizeArgs(spec, { table_nam: 't' });
  assert.deepEqual(typo.errors, ['unknown argument table_nam, did you mean table_name']);
  assert.deepEqual(typo.args, {});

  const far = normalizeArgs(spec, { zzzzzzzzzz: 1 });
  assert.deepEqual(far.errors, ['unknown argument zzzzzzzzzz (valid: table_name, count_only, limit)']);
  assert.deepEqual(normalizeArgs({ keys: [] }, { x: 1 }).errors, ['unknown argument x (this tool takes no arguments)']);

  const dup = normalizeArgs(spec, { table_name: 'a', tableName: 'b' });
  assert.deepEqual(dup.errors, ['argument table_name given twice (as table_name and tableName)']);

  // Canonical camelCase keys (custom tools) still accept snake_case input.
  assert.deepEqual(normalizeArgs({ keys: ['userId'] }, { user_id: 7 }).args, { userId: 7 });

  // Merged mode: a service label or differently-cased name maps to the name.
  const svc = { keys: ['service', 'table_name'], serviceNames: serviceNameMap([db('ordersdb', 'Orders DB'), db('crm')]) };
  assert.deepEqual(normalizeArgs(svc, { service: 'orders db', tableName: 't' }), { args: { service: 'ordersdb', table_name: 't' }, aliases: ['tableName→table_name', 'service=orders db→ordersdb'], errors: [] });
  assert.deepEqual(normalizeArgs(svc, { service: 'CRM' }).args, { service: 'crm' });
  assert.deepEqual(normalizeArgs(svc, { service: 'nope' }).args, { service: 'nope' }, 'unmapped values are left for the enum to reject');

  // Non-object input is left for Zod.
  assert.deepEqual(normalizeArgs(spec, undefined), { args: undefined, aliases: [], errors: [] });
});

test('annotationsFor: verb prefix decides the hints', () => {
  assert.deepEqual(annotationsFor('db_get_table_data'), { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false });
  assert.equal(annotationsFor('db_aggregate_data').readOnlyHint, true);
  assert.equal(annotationsFor('all_find_table').readOnlyHint, true);
  assert.equal(annotationsFor('search').readOnlyHint, true);
  assert.deepEqual(annotationsFor('db_delete_records'), { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false });
  assert.equal(annotationsFor('db_update_records').destructiveHint, true);
  assert.deepEqual(annotationsFor('db_create_records'), { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false });
  assert.deepEqual(annotationsFor('db_call_stored_procedure'), { readOnlyHint: false, openWorldHint: false });
  assert.deepEqual(annotationsFor('call_tool'), { openWorldHint: false });
  assert.equal(annotationsFor('describe_tool').readOnlyHint, true);
});

test('tools/list: snake_case schemas and annotations on every tool', async () => {
  const client = await connect([db('ordersdb')], {
    custom: [{ name: 'lookup_user', description: 'x', tool_type: 'api', http_method: 'GET', url: 'http://x/{userId}',
               parameters: [{ name: 'userId', type: 'string', in: 'path', required: true }] }]
  });
  const tools = (await client.listTools()).tools;
  const by = Object.fromEntries(tools.map(t => [t.name, t]));

  assert.deepEqual(Object.keys((by.ordersdb_get_table_data.inputSchema as any).properties),
    ['table_name', 'fields', 'filter', 'offset', 'limit', 'order', 'continue', 'related', 'count_only', 'include_count', 'include_schema', 'ids']);
  assert.ok(by.ordersdb_call_stored_procedure.inputSchema.properties!.procedure_name);
  assert.ok(by.ordersdb_aggregate_data.inputSchema.properties!.group_by);
  assert.ok(tools.every(t => t.annotations && typeof t.annotations.openWorldHint === 'boolean'), 'every tool is annotated');
  assert.equal(by.ordersdb_get_tables.annotations!.readOnlyHint, true);
  assert.equal(by.ordersdb_delete_records.annotations!.destructiveHint, true);
  assert.deepEqual(by.lookup_user.annotations, { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true });
  assert.equal(by.request_access.annotations!.readOnlyHint, false);
});

test('tools/call: camelCase alias works, unknown key is an error, ledger counts both, custom tools included', async () => {
  const client = await connect([db('ordersdb'), db('crm')], {
    custom: [{ name: 'add_numbers', description: 'x', tool_type: 'function',
               parameters: [{ name: 'firstValue', type: 'number', in: 'body', required: true }, { name: 'b', type: 'number', in: 'body', required: true }],
               function: 'return { sum: firstValue + b };' }]
  });

  // Custom function tool: snake_case alias of a camelCase parameter.
  assert.equal(text(await client.callTool({ name: 'add_numbers', arguments: { first_value: 2, b: 3 } })), '{\n  "sum": 5\n}');

  // Unknown key: helpful error, nothing invoked, not an SDK -32602 throw.
  const bad = await client.callTool({ name: 'add_numbers', arguments: { first_value: 2, b: 3, c: 4 } });
  assert.equal(bad.isError, true);
  assert.equal(text(bad), 'Invalid arguments for add_numbers: unknown argument c, did you mean b');

  // Generated DB tool: the alias reaches the handler (fails later on auth, not on "table_name: Required").
  const aliased = await client.callTool({ name: 'ordersdb_get_table_schema', arguments: { tableName: 't' } });
  assert.equal(aliased.isError, true);
  assert.match(text(aliased), /DreamFactory session not found/, 'validation passed; handler ran');

  // A tool with no arguments rejects stray keys instead of ignoring them.
  const stray = await client.callTool({ name: 'ordersdb_get_tables', arguments: { table_name: 't' } });
  assert.equal(text(stray), 'Invalid arguments for ordersdb_get_tables: unknown argument table_name (this tool takes no arguments)');

  // Ledger: counters ride the X-Mcp-Ledger header of the request that made the call.
  const res = fakeRes();
  await runWithResponse(res as any, () => client.callTool({ name: 'add_numbers', arguments: { firstValue: 1, B: 2 } }));
  assert.deepEqual(res.ledger(), { arg_aliases: 1, arg_errors: 0 });
  const res2 = fakeRes();
  await runWithResponse(res2 as any, () => client.callTool({ name: 'add_numbers', arguments: { first_value: 1, b: 2, nope: 1 } }));
  assert.deepEqual(res2.ledger(), { arg_aliases: 1, arg_errors: 1 });
});

test('merged mode: service label and casing are accepted; lazy call_tool shares the normaliser', async () => {
  const merged = await connect([db('ordersdb', 'Orders DB'), db('crm', 'CRM')], { style: 'merged' });
  const r = await merged.callTool({ name: 'get_table_schema', arguments: { service: 'Orders DB', tableName: 't' } });
  assert.match(text(r), /DreamFactory session not found/, 'label mapped to name, alias mapped, handler ran');
  const nope = await merged.callTool({ name: 'get_table_schema', arguments: { service: 'warehouse', table_name: 't' } });
  assert.match(text(nope), /not available for service "warehouse"|Invalid enum|invalid/i);

  const lazy = await connect([db('ordersdb')], { mode: 'on' });
  await lazy.listTools(); // the mode is decided on the first tools/list
  const res = fakeRes();
  const viaFacade = await runWithResponse(res as any, () =>
    lazy.callTool({ name: 'call_tool', arguments: { name: 'ordersdb_get_table_schema', arguments: { tableNam: 't' } } }));
  assert.equal(viaFacade.isError, true);
  const body = JSON.parse(text(viaFacade));
  assert.deepEqual(body.issues, ['unknown argument tableNam, did you mean table_name']);
  assert.ok(body.schema.properties.table_name, 'schema ships with the error');
  assert.equal(res.ledger().arg_errors, 1);
  assert.equal(res.ledger().mode, 'lazy');

  const desc = JSON.parse(text(await lazy.callTool({ name: 'describe_tool', arguments: { Name: 'ordersdb_delete_records' } })));
  assert.equal(desc.read_only, false);
  assert.equal(desc.annotations.destructiveHint, true);
});

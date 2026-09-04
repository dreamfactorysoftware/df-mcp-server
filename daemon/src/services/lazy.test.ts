import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as z from 'zod/v4';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { ToolListChangedNotificationSchema } from '@modelcontextprotocol/sdk/types.js';
import { createToolRegistrar, respond } from './tool-utils.js';
import { registerCustomTools } from './custom-tools.service.js';
import { SessionService } from './session.service.js';
import { createLazyState, installLazyFacade, SearchIndex, shapeText, PAGE_CHARS, type LazyMode } from './lazy.service.js';

// Run: npm test (node --import tsx --test)

async function connect(server: McpServer, clientName: string) {
  const client = new Client({ name: clientName, version: '1' });
  let changed = 0;
  client.setNotificationHandler(ToolListChangedNotificationSchema, async () => { changed++; });
  const [a, b] = InMemoryTransport.createLinkedPair();
  await server.connect(a);
  await client.connect(b);
  return { client, changed: () => changed };
}

function build(mode: Exclude<LazyMode, 'off'>, service = 'svc') {
  const server = new McpServer({ name: 'test', version: '0' });
  const state = createLazyState(server, service, mode);
  const reg = createToolRegistrar(server);
  reg('db_get_table_data', 'Get Table Data', 'Retrieve table data with filtering, pagination, and sorting.',
    z.object({ tableName: z.string(), limit: z.number().optional() }),
    async ({ tableName }) => respond({ rows: Array.from({ length: 400 }, (_, i) => ({ id: i, table: tableName })) }));
  reg('db_get_tables', 'List Tables', 'Get tables available in the database.', z.object({}),
    async () => respond({ resource: [{ name: 't1' }] }));
  reg('db_create_records', 'Create Records', 'Create one or more records in a table.',
    z.object({ tableName: z.string(), records: z.array(z.record(z.string(), z.unknown())) }),
    async () => ({ isError: true, content: [{ type: 'text', text: 'Error during db_create_records: {"error":{"code":403,"message":"no","trace":["a","b"]}}' }] }));
  registerCustomTools(server, [
    { name: 'add_numbers', description: 'Add two numbers', tool_type: 'function',
      parameters: [{ name: 'a', type: 'number', in: 'body', required: true }, { name: 'b', type: 'number', in: 'body', required: true }],
      function: 'return { sum: a + b };' },
    { name: 'broken_fn', description: 'Throws', tool_type: 'function', parameters: [], function: 'throw new Error("boom");' }
  ], new SessionService());
  installLazyFacade(server, state);
  return server;
}

const text = (r: any) => r.content[0].text as string;

test('lazy on: facade only, call_tool validates, pages, fetch_more, no list_changed', async () => {
  const { client, changed } = await connect(build('on', 'svc-a'), 'claude-code');
  const names = (await client.listTools()).tools.map(t => t.name).sort();
  assert.deepEqual(names, ['call_tool', 'describe_tool', 'fetch_more', 'search_tools']);

  const hits = JSON.parse(text(await client.callTool({ name: 'search_tools', arguments: { query: 'table data' } })));
  assert.equal(hits.tools[0].name, 'db_get_table_data');
  assert.equal(hits.tools[0].read_only, true);

  const desc = JSON.parse(text(await client.callTool({ name: 'describe_tool', arguments: { name: 'db_get_table_data' } })));
  assert.deepEqual(Object.keys(desc.inputSchema.properties), ['tableName', 'limit']);
  assert.equal(desc.inputSchema.$schema, undefined);

  const bad = await client.callTool({ name: 'call_tool', arguments: { name: 'db_get_table_data', arguments: { limit: 'x' } } });
  assert.equal(bad.isError, true);
  const body = JSON.parse(text(bad));
  assert.ok(body.issues.some((i: string) => i.startsWith('tableName')));
  assert.ok(body.schema.properties.tableName, 'schema ships with the error');

  const big = await client.callTool({ name: 'call_tool', arguments: { name: 'db_get_table_data', arguments: { tableName: 't' } } });
  const page = text(big);
  const m = page.match(/handle":"([^"]+)","offset":(\d+)/);
  assert.ok(m, 'result over PAGE_CHARS is paged');
  assert.ok(page.startsWith('{"rows":[{"id":0'), 'minified');
  const more = JSON.parse(text(await client.callTool({ name: 'fetch_more', arguments: { handle: m![1], offset: Number(m![2]) } })));
  assert.equal(more.offset, PAGE_CHARS);
  assert.ok(more.total > PAGE_CHARS && more.text.length > 0);

  const err = await client.callTool({ name: 'call_tool', arguments: { name: 'db_create_records', arguments: { tableName: 't', records: [{}] } } });
  assert.equal(text(err), 'Error during db_create_records: {"error":{"code":403,"message":"no"}}');

  // Custom function tools run through the facade and directly, errors included.
  assert.equal(text(await client.callTool({ name: 'call_tool', arguments: { name: 'add_numbers', arguments: { a: 2, b: 3 } } })), '{"sum":5}');
  assert.equal(text(await client.callTool({ name: 'add_numbers', arguments: { a: 2, b: 3 } })), '{"sum":5}');
  const boom = await client.callTool({ name: 'call_tool', arguments: { name: 'broken_fn' } });
  assert.equal(boom.isError, true);
  assert.equal(text(boom), 'Function execution error: boom');

  // Direct call to a hidden tool still works (tools remain registered).
  const direct = await client.callTool({ name: 'db_get_tables', arguments: {} });
  assert.equal(text(direct), '{"resource":[{"name":"t1"}]}');
  assert.equal(changed(), 0, 'never emits tools/list_changed');

  // Hot tools from this session are advertised to the next session of the same service.
  const next = await connect(build('on', 'svc-a'), 'claude-code');
  const nextNames = (await next.client.listTools()).tools.map(t => t.name);
  assert.ok(nextNames.includes('db_get_table_data') && nextNames.includes('db_get_tables'));
});

test('passthrough client and small auto catalog get the full list, unshaped', async () => {
  for (const [client, mode] of [['codex-cli', 'on'], ['claude-code', 'auto']] as const) {
    const { client: c } = await connect(build(mode, `svc-${client}`), client);
    const names = (await c.listTools()).tools.map(t => t.name).sort();
    assert.deepEqual(names, ['add_numbers', 'broken_fn', 'db_create_records', 'db_get_table_data', 'db_get_tables']);
    const r = text(await c.callTool({ name: 'db_get_table_data', arguments: { tableName: 't' } }));
    assert.ok(r.startsWith('{\n  "rows"'), 'pretty JSON, no paging');
    assert.ok(!r.includes('[paged:'));
  }
});

test('pure helpers', () => {
  const idx = new SearchIndex([
    { name: 'unrelated_create', description: 'Create a widget in the fleet.' },
    { name: 'issue_watcher', description: 'Watch an issue for status changes.' },
    { name: 'github_create_issue', description: 'Create a GitHub issue in a repository.' }
  ]);
  assert.equal(idx.rank('create github issue', 10)[0], 2);
  assert.equal(idx.rank('github_create_issue', 10)[0], 2);
  assert.deepEqual(new SearchIndex([{ name: 'pagerduty_create_incident', description: 'Open a PagerDuty incident.' }]).rank('pager duty incident', 5), [0]);
  assert.equal(shapeText('{\n  "a": 1,\n  "trace": ["x"]\n}'), '{"a":1}');
  assert.equal(shapeText('plain text\nwith lines'), 'plain text\nwith lines');
  assert.equal(shapeText('broken: {not json'), 'broken: {not json');
});

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { previewCatalog, type PreviewTool } from './catalog-preview.service.js';

// Run: npm test (node --import tsx --test)
//
// Catalog preview (issue #64): the same _mcpConfig / _mcpAvailableServices the
// PHP proxy envelopes, run through the real registration path in a throwaway
// server, must yield exactly the catalog a session for that role would get.

const db = (name: string) => ({ name, type: 'mysql', category: 'database' });
const file = (name: string) => ({ name, type: 'local_file', category: 'file' });
const FACADE = ['call_tool', 'describe_tool', 'fetch_more', 'list_tools', 'search_tools'];
const GLOBAL = ['discover_services', 'fetch', 'list_apis', 'request_access', 'search'];

const byName = (tools: PreviewTool[]) => new Map(tools.map(t => [t.name, t]));

test('prefixed catalog: per-service verbs classified with service, category and write flag', async () => {
  const r = await previewCatalog({
    serviceName: 'preview-prefixed',
    _mcpConfig: { lazy_mode: 'off', tool_style: 'prefixed' },
    _mcpAvailableServices: [db('ordersdb'), db('orders_eu'), file('docs')]
  });
  const tools = byName(r.tools);

  assert.equal(r.count, r.tools.length);
  assert.equal(r.lazy, 'direct');
  assert.deepEqual(r.facade, []);
  assert.ok(r.bytes > 1000, 'bytes is the serialized size of the full schemas, not of this summary');
  assert.ok(r.tools.every(t => !FACADE.includes(t.name)), 'no facade tools in the catalog');

  assert.deepEqual(tools.get('ordersdb_get_table_data'), {
    name: 'ordersdb_get_table_data', title: 'ordersdb: Get Table Data',
    description: tools.get('ordersdb_get_table_data')!.description,
    category: 'database', write: false, service: 'ordersdb'
  });
  assert.equal(tools.get('ordersdb_create_records')!.write, true);
  assert.equal(tools.get('ordersdb_call_stored_procedure')!.write, true);
  // Longest prefix wins: orders_eu tools bind to orders_eu, not ordersdb/orders.
  assert.equal(tools.get('orders_eu_get_tables')!.service, 'orders_eu');
  assert.deepEqual(
    [tools.get('docs_list_files')!.category, tools.get('docs_list_files')!.service, tools.get('docs_delete_file')!.write],
    ['file', 'docs', true]
  );
  // Two databases: cross-service aggregators register; one file service: all_list_files does not.
  assert.equal(tools.get('all_get_tables')!.category, 'database');
  assert.equal(tools.has('all_list_files'), false);
  for (const g of GLOBAL) assert.equal(tools.get(g)!.category, 'global', g);
  assert.equal(tools.get('request_access')!.write, true);
  assert.equal(tools.get('discover_services')!.write, false);
});

test('merged catalog: bare verbs, no service binding, prefixed names absent', async () => {
  const r = await previewCatalog({
    serviceName: 'preview-merged',
    _mcpConfig: { lazy_mode: 'off', tool_style: 'merged' },
    _mcpAvailableServices: [db('ordersdb'), db('hrdb')]
  });
  const tools = byName(r.tools);
  assert.equal(tools.has('ordersdb_get_table_data'), false);
  assert.deepEqual(
    [tools.get('get_table_data')!.category, tools.get('get_table_data')!.service, tools.get('get_table_data')!.write],
    ['database', undefined, false]
  );
  assert.equal(tools.get('delete_records')!.write, true);
});

test('lazy on: catalog stays full, facade lists what the client will actually see', async () => {
  const r = await previewCatalog({
    serviceName: 'preview-lazy-on',
    _mcpConfig: { lazy_mode: 'on' },
    _mcpAvailableServices: [db('ordersdb')]
  });
  assert.equal(r.lazy, 'lazy');
  assert.deepEqual([...r.facade].sort(), FACADE);
  assert.ok(r.tools.some(t => t.name === 'ordersdb_get_table_data'), 'catalog behind the facade is still listed');
  assert.ok(r.tools.every(t => t.category !== 'facade'));
});

test('lazy auto follows the catalog size; lazyMode overrides the config; passthrough clients never get the facade', async () => {
  const small = await previewCatalog({ serviceName: 'preview-auto-small', _mcpConfig: { lazy_mode: 'auto' }, _mcpAvailableServices: [db('ordersdb')] });
  assert.equal(small.lazy, 'direct');

  const many = Array.from({ length: 20 }, (_, i) => db(`warehouse${i}`));
  const large = await previewCatalog({ serviceName: 'preview-auto-large', _mcpConfig: { lazy_mode: 'auto' }, _mcpAvailableServices: many });
  assert.equal(large.lazy, 'lazy');
  assert.ok(large.bytes > 32 * 1024, `catalog bytes ${large.bytes} exceed the auto threshold`);
  assert.equal(large.count, 20 * 16 + 5 + 5, '16 verbs x 20 dbs + 5 aggregators + 5 globals');

  const forcedOff = await previewCatalog({ serviceName: 'preview-forced-off', _mcpConfig: { lazy_mode: 'on' }, _mcpAvailableServices: many, lazyMode: 'off' });
  assert.equal(forcedOff.lazy, 'direct');
  assert.deepEqual(forcedOff.facade, []);
  assert.equal(forcedOff.count, large.count, 'off and auto describe the same catalog');

  const codex = await previewCatalog({ serviceName: 'preview-codex', _mcpConfig: { lazy_mode: 'on' }, _mcpAvailableServices: many, clientName: 'codex-cli' });
  assert.equal(codex.lazy, 'passthrough');
  assert.deepEqual(codex.facade, []);
});

test('disabled_tools and custom tools are honoured exactly as in a session', async () => {
  const r = await previewCatalog({
    serviceName: 'preview-disabled',
    _mcpConfig: {
      lazy_mode: 'off',
      disabled_tools: ['ordersdb_delete_records', 'request_access'],
      custom_tools: [
        { name: 'ping_crm', description: 'Ping', tool_type: 'api', http_method: 'GET', url: 'https://crm/ping', parameters: [] },
        { name: 'open_ticket', description: 'Open', tool_type: 'api', http_method: 'POST', url: 'https://crm/t', parameters: [] },
        { name: 'sum', description: 'Add', tool_type: 'function', parameters: [], function: 'return 1;' },
        { name: 'off_tool', description: 'Disabled', tool_type: 'function', parameters: [], function: 'return 1;', enabled: false }
      ]
    },
    _mcpAvailableServices: [db('ordersdb')]
  });
  const tools = byName(r.tools);
  assert.equal(tools.has('ordersdb_delete_records'), false);
  assert.equal(tools.has('request_access'), false);
  assert.equal(tools.has('off_tool'), false);
  assert.deepEqual(
    ['ping_crm', 'open_ticket', 'sum'].map(n => [tools.get(n)!.category, tools.get(n)!.write]),
    [['custom', false], ['custom', true], ['custom', true]]
  );
});

test('empty exposure: globals only, and a missing catalog is an empty catalog (never rediscovered)', async () => {
  for (const services of [[], undefined]) {
    const r = await previewCatalog({ serviceName: 'preview-empty', _mcpConfig: {}, _mcpAvailableServices: services });
    assert.deepEqual(r.tools.map(t => t.name).sort(), GLOBAL);
    assert.equal(r.lazy, 'direct');
  }
});

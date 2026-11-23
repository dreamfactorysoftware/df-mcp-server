import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createServer, parseMcpConfig, type ServerOptions } from '../utils/utils.js';
import { SessionService } from './session.service.js';
import { DreamFactoryService } from './dreamfactory.service.js';
import type { ApiConfig } from '../types.js';

// Run: npm test (node --import tsx --test)
//
// Endpoint opt-ins semantic_catalog and compact_results: off (the default)
// every tool result and every DreamFactory request is 1.4.1's; on, each turns
// on only its own behaviour. tools/list and instructions are covered by
// tools.snapshot.test.ts.

const db = (name: string): ApiConfig => ({ name, baseUrl: `http://df/api/v2/${name}`, category: 'database', type: 'mysql' });
const MODEL = {
  service: 'hr',
  tables: { employees: { columns: [{ name: 'emp_no' }], row_count: 3, sample_data: [{ emp_no: 1 }, { emp_no: 2 }, { emp_no: 3 }] } },
  query_templates: { count: { tool: 'get_table_data', params: { table_name: 'employees', count_only: true } } },
  semantics: { metrics: [{ name: 'headcount', run_with: { tool: 'aggregate_data', service: 'hr', arguments: {} } }] }
};

const original = { getApiSpec: DreamFactoryService.getApiSpec, getTableData: DreamFactoryService.getTableData };
const specCalls: any[] = [];
const tableCalls: any[] = [];
beforeEach(() => {
  specCalls.length = 0;
  tableCalls.length = 0;
  (DreamFactoryService as any).getApiSpec = async (_b: string, _a: unknown, opts: any) => { specCalls.push(opts); return structuredClone(MODEL); };
  (DreamFactoryService as any).getTableData = async (_b: string, _a: unknown, args: any) => {
    tableCalls.push(args);
    if (args.group) return { resource: [{ dept_no: 'd1', SUM_salary: 10 }, { dept_no: 'd2', SUM_salary: 20 }] };
    if (Array.isArray(args.fields) && args.fields.some((f: string) => f.includes('('))) return { resource: [{ SUM_salary: 30 }] };
    return { resource: [{ emp_no: 1 }] };
  };
});
test.after(() => Object.assign(DreamFactoryService, original));

async function connect(opts: ServerOptions) {
  const sessions = new SessionService();
  sessions.setDefaultConfig({ url: 'http://df', apiKey: 'k', apiConfigs: [db('hr')] });
  const server = createServer('svc', [db('hr')], sessions, undefined, undefined, 'off', 'prefixed', true, opts);
  const client = new Client({ name: 'claude-code', version: '1' });
  const [a, b] = InMemoryTransport.createLinkedPair();
  await server.connect(a);
  await client.connect(b);
  return client;
}
const text = (r: any) => r.content[0].text as string;
const SUM = [{ function: 'SUM', field: 'salary', alias: 'payroll' }];

test('flags parse: only true, 1, "1", "true" switch semantic_catalog / compact_results on', () => {
  for (const v of [true, 1, '1', 'true']) {
    assert.equal(parseMcpConfig({ semantic_catalog: v }).semanticCatalog, true, String(v));
    assert.equal(parseMcpConfig({ compact_results: v }).compactResults, true, String(v));
  }
  for (const v of [undefined, false, 0, '0', 'false', 'yes', 'on', 2]) {
    assert.equal(parseMcpConfig({ semantic_catalog: v }).semanticCatalog, false, String(v));
    assert.equal(parseMcpConfig({ compact_results: v }).compactResults, false, String(v));
  }
  assert.deepEqual(
    [parseMcpConfig({}).semanticCatalog, parseMcpConfig({}).compactResults, parseMcpConfig({}).semanticTools],
    [false, false, false]
  );
});

test('all off: 1.4.1 results — pretty JSON, unbounded reads, plain model, no aliases, 1000-row group pages', async () => {
  const c = await connect({});
  const rows = text(await c.callTool({ name: 'hr_get_table_data', arguments: { table_name: 'employees' } }));
  assert.equal(rows, JSON.stringify({ resource: [{ emp_no: 1 }] }, null, 2));
  assert.deepEqual(tableCalls[0], { table_name: 'employees' }, 'no default limit / include_count');

  const model = JSON.parse(text(await c.callTool({ name: 'hr_get_data_model', arguments: {} })));
  assert.deepEqual(specCalls[0], { model: true, refresh: undefined }, 'no enrich=true');
  assert.equal(model.tables.employees.sample_data.length, 3, 'no sample trimming');
  assert.equal(model.query_templates.count.tool, 'hr_get_table_data', 'templates kept');
  assert.equal(model.semantics.metrics[0].run_with.tool, 'aggregate_data', 'semantics not adapted');

  const whole = JSON.parse(text(await c.callTool({ name: 'hr_aggregate_data', arguments: { table_name: 's', aggregates: SUM } })));
  assert.deepEqual(whole.results, [{ SUM_salary: 30 }], 'no alias renaming');
  tableCalls.length = 0;
  const grouped = JSON.parse(text(await c.callTool({ name: 'hr_aggregate_data', arguments: { table_name: 's', aggregates: SUM, group_by: ['dept_no'] } })));
  assert.equal(tableCalls[0].limit, 1000, '1.4.1 pages 1000 groups at a time');
  assert.equal(grouped.groups, 2);
  const rejected = await c.callTool({ name: 'hr_aggregate_data', arguments: { table_name: 's', aggregates: SUM, limit: 5 } });
  assert.match(text(rejected), /limit/, 'limit is not an aggregate_data argument when compact_results is off');
});

test('compact_results: compact JSON, default 50 rows + count, 100-group cap, sample trimming', async () => {
  const c = await connect({ compactResults: true });
  const rows = text(await c.callTool({ name: 'hr_get_table_data', arguments: { table_name: 'employees' } }));
  assert.equal(rows, '{"resource":[{"emp_no":1}]}');
  assert.deepEqual(tableCalls[0], { table_name: 'employees', limit: 50, include_count: true });
  const model = JSON.parse(text(await c.callTool({ name: 'hr_get_data_model', arguments: {} })));
  assert.equal(specCalls[0].enrich, undefined, 'enrich only with semantic_catalog');
  assert.equal(model.tables.employees.sample_data.length, 1);
  assert.ok(model.query_templates, 'templates are a semantic_catalog decision');
  tableCalls.length = 0;
  await c.callTool({ name: 'hr_aggregate_data', arguments: { table_name: 's', aggregates: SUM, group_by: ['dept_no'] } });
  assert.equal(tableCalls[0].limit, 101, 'fetches one past the default 100-group cap');
});

test('semantic_catalog: enrich=true, templates dropped, semantics adapted, aliases applied', async () => {
  const c = await connect({ semanticCatalog: true });
  const model = JSON.parse(text(await c.callTool({ name: 'hr_get_data_model', arguments: {} })));
  assert.equal(specCalls[0].enrich, true);
  assert.equal(model.query_templates, undefined);
  assert.equal(model.semantics.metrics[0].run_with.tool, 'hr_aggregate_data');
  assert.equal(model.tables.employees.sample_data.length, 3, 'trimming is a compact_results decision');
  const kept = JSON.parse(text(await c.callTool({ name: 'hr_get_data_model', arguments: { templates: true } })));
  assert.ok(kept.query_templates);
  const whole = JSON.parse(text(await c.callTool({ name: 'hr_aggregate_data', arguments: { table_name: 's', aggregates: SUM } })));
  assert.deepEqual(whole.results, [{ payroll: 30 }]);
  assert.ok(text(await c.callTool({ name: 'hr_get_table_data', arguments: { table_name: 'employees' } })).includes('\n  '), 'pretty JSON without compact_results');
  assert.equal(tableCalls.at(-1).limit, undefined, 'no default row limit without compact_results');
});

test('semantic_catalog without df-semantic: get_semantics says there is no catalog, never relays the OpenAPI spec', async () => {
  // Stock df-core ignores model=semantics and builds the full OpenAPI document.
  (DreamFactoryService as any).getApiSpec = async () => ({ openapi: '3.0.0', info: { title: 'hr' }, paths: { '/_table/employees': { get: { summary: 'x'.repeat(5000) } } } });
  const c = await connect({ semanticCatalog: true });
  const out = text(await c.callTool({ name: 'hr_get_semantics', arguments: {} }));
  assert.match(out, /no semantic catalog on this server/i);
  assert.ok(out.length < 300, `short answer, got ${out.length} chars`);
  assert.doesNotMatch(out, /openapi|paths/);

  // df-semantic installed but nothing approved yet: a different, equally short note.
  (DreamFactoryService as any).getApiSpec = async () => ({ service: 'hr', semantics: null });
  const empty = text(await c.callTool({ name: 'hr_get_semantics', arguments: {} }));
  assert.match(empty, /no approved business definitions/i);
});

test('catalog preview honours the flags and classifies get_semantics as a database tool in both styles', async () => {
  const { previewCatalog } = await import('./catalog-preview.service.js');
  const services = [{ name: 'hr', type: 'mysql', category: 'database' }];
  const off = await previewCatalog({ serviceName: 'p-off', _mcpConfig: { tool_style: 'merged' }, _mcpAvailableServices: services });
  assert.ok(!off.tools.some(t => t.name.endsWith('get_semantics')), 'off: no get_semantics');
  for (const style of ['merged', 'prefixed']) {
    const on = await previewCatalog({ serviceName: `p-${style}`, _mcpConfig: { tool_style: style, semantic_catalog: true }, _mcpAvailableServices: services });
    const t = on.tools.find(x => x.name.endsWith('get_semantics'));
    assert.ok(t, `${style}: get_semantics in the preview`);
    assert.equal(t!.category, 'database', `${style}: classified as a database tool`);
    assert.equal(t!.write, false);
  }
});

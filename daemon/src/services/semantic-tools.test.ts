import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createServer, parseMcpConfig } from '../utils/utils.js';
import { SessionService } from './session.service.js';
import { DreamFactoryService } from './dreamfactory.service.js';
import {
  clearSemanticCache, fillArguments, loadSemanticRunnables, runnablesFrom, semanticCacheSize, toolNameFor, type SemanticRunnable
} from './semantic-tools.service.js';
import type { ApiConfig, CustomToolDefinition, ToolStyle } from '../types.js';
import type { LazyMode } from './lazy.service.js';

// Run: npm test (node --import tsx --test)
//
// Named semantic tools (endpoint opt-in, mcp_server_config.semantic_tools):
// every approved metric / verified query becomes its own tool that runs the
// stored definition through aggregate_data / get_table_data.

const db = (name: string): ApiConfig => ({ name, baseUrl: `http://df/api/v2/${name}`, category: 'database', type: 'mysql' });

const SEMANTICS = {
  metrics: [
    {
      name: 'Headcount', description: 'Current employees.', examples: ['How many people work here?'],
      run_with: { tool: 'aggregate_data', service: 'hr', arguments: { table_name: 'dept_emp', aggregates: [{ function: 'COUNT', field: '*', alias: 'headcount' }], filter: "to_date = '9999-01-01'" } }
    },
    {
      name: 'payroll by dept', params: [{ name: 'dept', type: 'string', required: true, description: 'department code' }],
      run_with: { tool: 'aggregate_data', service: 'hr', arguments: { table_name: 'salaries', aggregates: [{ function: 'SUM', field: 'salary', alias: 'payroll' }], filter: 'dept_no = {dept}' } }
    }
  ],
  verified_queries: [
    {
      name: 'employee current salary', question: 'What is this employee paid now?',
      params: [{ name: 'emp_no', type: 'integer', required: true }],
      run_with: { tool: 'get_table_data', service: 'hr', arguments: { table_name: 'salaries', filter: "emp_no = {emp_no} and to_date = '9999-01-01'", limit: 5 } }
    }
  ]
};

const calls: Array<{ op: string; baseUrl: string; args: any }> = [];
beforeEach(() => {
  calls.length = 0;
  clearSemanticCache();
  (DreamFactoryService as any).getApiSpec = async (baseUrl: string) =>
    baseUrl.endsWith('/hr') ? { semantics: SEMANTICS } : { semantics: null };
  (DreamFactoryService as any).aggregateData = async (baseUrl: string, _auth: unknown, args: any) => {
    calls.push({ op: 'aggregate', baseUrl, args });
    return { resource: [{ headcount: 3 }] };
  };
  (DreamFactoryService as any).getTableData = async (baseUrl: string, _auth: unknown, args: any) => {
    calls.push({ op: 'table', baseUrl, args });
    return { resource: [{ salary: 1 }] };
  };
});

async function connect(apis: ApiConfig[], runnables: SemanticRunnable[], opts: { style?: ToolStyle; lazy?: LazyMode; disabled?: string[]; custom?: CustomToolDefinition[]; writes?: boolean } = {}) {
  const sessions = new SessionService();
  sessions.setDefaultConfig({ url: 'http://df', apiKey: 'k', apiConfigs: apis });
  const disabled = opts.disabled ? new Set(opts.disabled) : undefined;
  const server = createServer('svc', apis, sessions, disabled, opts.custom, opts.lazy ?? 'off', opts.style ?? 'merged', opts.writes ?? false, { semanticRunnables: runnables });
  const client = new Client({ name: 'claude-code', version: '1' });
  const [a, b] = InMemoryTransport.createLinkedPair();
  await server.connect(a);
  await client.connect(b);
  return client;
}
const names = async (c: Client) => (await c.listTools()).tools.map(t => t.name);
const auth = { apiKey: 'k' };

test('opt-in: semantic_tools is off unless explicitly on', () => {
  assert.equal(parseMcpConfig({}).semanticTools, false);
  assert.equal(parseMcpConfig({ semantic_tools: false }).semanticTools, false);
  assert.equal(parseMcpConfig({ semantic_tools: 'yes' }).semanticTools, false);
  assert.equal(parseMcpConfig({ semantic_tools: true }).semanticTools, true);
  assert.equal(parseMcpConfig({ semantic_tools: 1 }).semanticTools, true);
});

test('no runnables: the tool list is unchanged', async () => {
  const off = await names(await connect([db('hr')], []));
  assert.ok(!off.some(n => n.startsWith('metric_') || n.startsWith('query_')));
});

test('merged single database: bare metric_/query_ names, typed params', async () => {
  const runnables = await loadSemanticRunnables([db('hr')], auth);
  const c = await connect([db('hr')], runnables);
  const tools = (await c.listTools()).tools;
  const byName = new Map(tools.map(t => [t.name, t]));
  for (const n of ['metric_headcount', 'metric_payroll_by_dept', 'query_employee_current_salary']) assert.ok(byName.has(n), n);
  const q = byName.get('query_employee_current_salary')!;
  assert.equal((q.inputSchema as any).properties.emp_no.type, 'integer');
  assert.deepEqual((q.inputSchema as any).required, ['emp_no']);
  assert.equal(q.annotations?.readOnlyHint, true);
  assert.match(q.description!, /Verified query/);
});

test('prefixed style or several databases: names carry the service', async () => {
  const rs = await loadSemanticRunnables([db('hr'), db('crm')], auth);
  assert.ok((await names(await connect([db('hr'), db('crm')], rs))).includes('hr_metric_headcount'));
  assert.ok((await names(await connect([db('hr')], rs.filter(r => r.service === 'hr'), { style: 'prefixed' }))).includes('hr_metric_headcount'));
});

test('a metric runs the stored aggregate on its service', async () => {
  const c = await connect([db('hr'), db('crm')], await loadSemanticRunnables([db('hr'), db('crm')], auth));
  const r = await c.callTool({ name: 'hr_metric_headcount', arguments: {} });
  assert.ok(!r.isError);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].op, 'aggregate');
  assert.equal(calls[0].baseUrl, 'http://df/api/v2/hr');
  assert.equal(calls[0].args.filter, "to_date = '9999-01-01'");
  assert.equal(calls[0].args.aggregates[0].alias, 'headcount');
});

test('params fill placeholders: strings quoted, numbers raw, limit override', async () => {
  const c = await connect([db('hr')], await loadSemanticRunnables([db('hr')], auth));
  await c.callTool({ name: 'metric_payroll_by_dept', arguments: { dept: 'd001' } });
  assert.equal(calls[0].args.filter, "dept_no = 'd001'");
  await c.callTool({ name: 'query_employee_current_salary', arguments: { emp_no: 10001, limit: 2 } });
  assert.equal(calls[1].op, 'table');
  assert.equal(calls[1].args.filter, "emp_no = 10001 and to_date = '9999-01-01'");
  assert.equal(calls[1].args.limit, 2);
});

test('a value that could break out of the filter is an error result, never a query', async () => {
  const c = await connect([db('hr')], await loadSemanticRunnables([db('hr')], auth));
  const r = await c.callTool({ name: 'metric_payroll_by_dept', arguments: { dept: "d0'1" } });
  assert.match(JSON.parse((r.content as any)[0].text).error, /parameter "dept"/);
  assert.equal(calls.length, 0);
});

test('a placeholder already in quotes is not double-quoted; a missing value is an error, not a query', () => {
  const r: SemanticRunnable = { kind: 'query', service: 'hr', name: 'x', tool: 'get_table_data', params: [{ name: 'd', type: 'date' }],
    arguments: { table_name: 't', filter: "hire_date > '{d}'" } };
  assert.equal(fillArguments(r, { d: '2001-01-01' }).filter, "hire_date > '2001-01-01'");
  assert.throws(() => fillArguments(r, {}), /missing value for parameter "d"/);
});

// Shared placeholder-fill vectors (contract 2026-11-23; df-semantic DryRun covers the same).
const fill = (filter: string, name: string, value: unknown, type?: string) =>
  fillArguments({ kind: 'query', service: 'hr', name: 'v', tool: 'get_table_data', params: [{ name, type }], arguments: { table_name: 't', filter } }, { [name]: value }).filter;

test('contract vectors: accepted values', () => {
  assert.equal(fill("(dept_no = '{d}')", 'd', 'd001'), "(dept_no = 'd001')");
  assert.equal(fill('(dept_no = {d})', 'd', 'd001'), "(dept_no = 'd001')");
  assert.equal(fill("(dept_name like '%{q}%')", 'q', 'ale'), "(dept_name like '%ale%')");
  assert.equal(fill('(emp_no = {n})', 'n', 10001, 'integer'), '(emp_no = 10001)');
  assert.equal(fill('(hire_date > {t})', 't', '1999-01-01', 'date'), "(hire_date > '1999-01-01')");
  assert.equal(fill('(dept_no in ({d}))', 'd', 'd001'), "(dept_no in ('d001'))");
});

test('contract vectors: rejected values name the parameter', () => {
  for (const filter of ["(dept_no = '{d}')", '(dept_no = {d})']) {
    for (const v of ["x') OR (dept_no != 'zz", 'a) OR (dept_no != zz', "O'Brien", 'a\\b', 'say "hi"', 'tab\there', 'nl\nx', 'nul\u0000']) {
      assert.throws(() => fill(filter, 'd', v), /parameter "d"/, `${filter} <- ${JSON.stringify(v)}`);
    }
  }
  assert.throws(() => fill('(dept_no in ({d}))', 'd', 'd001,d002'), /parameter "d".*comma/);
  assert.throws(() => fill("(dept_no in ('{d}'))", 'd', 'd001,d002'), /parameter "d".*comma/);
  assert.throws(() => fill('(emp_no = {n})', 'n', '1 OR 1=1', 'integer'), /parameter "n"/);
  assert.throws(() => fill('(emp_no = {n})', 'n', Infinity, 'number'), /parameter "n"/);
  assert.throws(() => fill('(hire_date > {t})', 't', "1999-01-01') OR (1=1", 'date'), /parameter "t"/);
  assert.throws(() => fill('(hire_date > {t})', 't', '01/02/1999', 'date'), /parameter "t"/);
});

test('quote state comes from a scan of the filter, not from adjacent characters', () => {
  // Inside a literal but not next to a quote: raw value, no added quotes.
  assert.equal(fill("(name like 'a {q} b')", 'q', 'x'), "(name like 'a x b')");
  // After a closed literal: outside again, so quoted.
  assert.equal(fill("(a = 'x') AND (b = {q})", 'q', 'y'), "(a = 'x') AND (b = 'y')");
  // Numbers and booleans are bare either way; "false" is false.
  assert.equal(fill('(n = {n})', 'n', '42', 'number'), '(n = 42)');
  assert.equal(fill('(f = {b})', 'b', 'false', 'boolean'), '(f = false)');
  assert.equal(fill('(f = {b})', 'b', true, 'boolean'), '(f = true)');
  assert.throws(() => fill('(f = {b})', 'b', 'maybe', 'boolean'), /parameter "b"/);
  // Dates with a time part are fine.
  assert.equal(fill("(t >= '{t}')", 't', '1999-01-01 10:00:00', 'date'), "(t >= '1999-01-01 10:00:00')");
});

test('lazy facade on: named tools stay listed beside the facade', async () => {
  const c = await connect([db('hr')], await loadSemanticRunnables([db('hr')], auth), { lazy: 'on' });
  const listed = await names(c);
  for (const n of ['metric_headcount', 'query_employee_current_salary', 'call_tool', 'search_tools']) assert.ok(listed.includes(n), n);
  assert.ok(!listed.includes('get_table_data'), 'ordinary tools stay behind the facade');
});

test('a service whose semantics fail to load contributes no tools and does not fail the rest', async () => {
  (DreamFactoryService as any).getApiSpec = async (baseUrl: string) => {
    if (baseUrl.endsWith('/crm')) throw new Error('boom');
    return { semantics: SEMANTICS };
  };
  const rs = await loadSemanticRunnables([db('hr'), db('crm')], auth);
  assert.ok(rs.length === 3 && rs.every(r => r.service === 'hr'));
});

test('names: slug, length cap, unusable entries skipped', () => {
  const long = 'a'.repeat(100);
  const n = toolNameFor({ kind: 'metric', service: 'hr', name: long, tool: 'aggregate_data', arguments: {} }, true);
  assert.ok(n.length <= 64 && n.startsWith('hr_metric_a'));
  assert.equal(toolNameFor({ kind: 'query', service: 'hr', name: 'Top earners (10)!', tool: 'get_table_data', arguments: {} }, false), 'query_top_earners_10');
  assert.equal(runnablesFrom('hr', { metrics: [{ name: 'no run_with' }] }).length, 0);
});

const metric = (over: Partial<SemanticRunnable> = {}): SemanticRunnable => ({
  kind: 'metric', service: 'hr', name: 'headcount', tool: 'aggregate_data',
  arguments: { table_name: 'dept_emp', aggregates: [{ function: 'COUNT', field: '*', alias: 'headcount' }] }, ...over
});

test('only get_table_data / aggregate_data runnables become tools; write verbs never do', async () => {
  const rs: SemanticRunnable[] = [
    metric(),
    metric({ kind: 'query', name: 'wipe', tool: 'delete_records', arguments: { table_name: 'salaries', filter: 'emp_no > 0' } }),
    metric({ kind: 'query', name: 'add', tool: 'create_records', arguments: { table_name: 'salaries', records: [] } }),
    metric({ kind: 'query', name: 'proc', tool: 'call_stored_procedure', arguments: { procedure_name: 'p' } }),
    metric({ kind: 'query', name: 'model', tool: 'get_data_model', arguments: {} }),
  ];
  const listed = await names(await connect([db('hr')], rs, { writes: true }));
  assert.ok(listed.includes('metric_headcount'));
  for (const n of ['query_wipe', 'query_add', 'query_proc', 'query_model']) assert.ok(!listed.includes(n), `${n} must not be registered`);
});

test('a runnable whose base tool is disabled for its service (or by bare name) is skipped', async () => {
  const rs = [metric(), metric({ service: 'crm' })];
  const perService = await names(await connect([db('hr'), db('crm')], rs, { disabled: ['hr_aggregate_data'] }));
  assert.ok(!perService.includes('hr_metric_headcount'), 'hr_aggregate_data disabled: hr metric skipped');
  assert.ok(perService.includes('crm_metric_headcount'), 'crm keeps its metric');
  const bare = await names(await connect([db('hr'), db('crm')], rs, { disabled: ['aggregate_data'] }));
  assert.ok(!bare.some(n => n.includes('metric_')), 'bare-name disable skips them all');
});

async function withClock<T>(fn: (set: (ms: number) => void) => Promise<T>): Promise<T> {
  const real = Date.now;
  let now = real();
  Date.now = () => now;
  try {
    return await fn(ms => { now = ms; });
  } finally {
    Date.now = real;
  }
}

test('cache is bounded: expired entries go first, then the oldest', async () => {
  let fetches = 0;
  (DreamFactoryService as any).getApiSpec = async () => { fetches++; return { semantics: SEMANTICS }; };
  await withClock(async set => {
    set(1_000_000);
    for (let i = 0; i < 500; i++) await loadSemanticRunnables([db('hr')], { apiKey: `k${i}` });
    assert.equal(semanticCacheSize(), 500);
    await loadSemanticRunnables([db('hr')], { apiKey: 'k500' });
    assert.equal(semanticCacheSize(), 500, 'never above the bound');
    fetches = 0;
    await loadSemanticRunnables([db('hr')], { apiKey: 'k0' });
    assert.equal(fetches, 1, 'the oldest entry was the one evicted');
    set(1_000_000 + 31_000);
    await loadSemanticRunnables([db('hr')], { apiKey: 'fresh' });
    assert.equal(semanticCacheSize(), 1, 'all expired entries are dropped before anything live');
  });
});

test('a failed load is cached briefly, so a slow or broken DreamFactory is not asked on every request', async () => {
  let fetches = 0;
  (DreamFactoryService as any).getApiSpec = async () => { fetches++; throw new Error('boom'); };
  await withClock(async set => {
    set(2_000_000);
    assert.deepEqual(await loadSemanticRunnables([db('hr')], auth), []);
    assert.deepEqual(await loadSemanticRunnables([db('hr')], auth), []);
    assert.equal(fetches, 1, 'second request inside the negative TTL does not refetch');
    set(2_000_000 + 11_000);
    await loadSemanticRunnables([db('hr')], auth);
    assert.equal(fetches, 2, 'retried after ~10 s');
  });
});

test('the fetch timeout timer is cleared when the load finishes', async () => {
  const timers = () => process.getActiveResourcesInfo().filter(r => r === 'Timeout').length;
  const before = timers();
  await loadSemanticRunnables([db('hr')], auth);
  assert.equal(timers(), before, 'no 10 s timer left behind');
});

test('a semantic tool whose name is already taken is skipped with a warning, never fails the request', async () => {
  const custom: CustomToolDefinition[] = [{ name: 'metric_headcount', description: 'an admin tool of the same name', tool_type: 'api', http_method: 'GET', url: 'http://x.invalid/', parameters: [] }];
  const warnings: string[] = [];
  const warn = console.warn;
  console.warn = (...a: unknown[]) => { warnings.push(a.join(' ')); };
  try {
    for (const lazy of ['off', 'on'] as const) {
      const c = await connect([db('hr')], [metric(), metric({ name: 'payroll' })], { custom, lazy });
      const tools = (await c.listTools()).tools;
      const same = tools.filter(t => t.name === 'metric_headcount');
      // Lazy on: the custom tool sits behind the facade; the semantic one must not take its place.
      assert.equal(same.length, lazy === 'off' ? 1 : 0, `${lazy}: only the custom tool has that name`);
      if (same.length) assert.match(same[0].description!, /admin tool/, `${lazy}: the custom tool keeps its name`);
      assert.ok(tools.some(t => t.name === 'metric_payroll'), `${lazy}: the other semantic tools still register`);
    }
  } finally {
    console.warn = warn;
  }
  assert.ok(warnings.some(w => /metric_headcount/.test(w) && /already/.test(w)), 'collision is logged');
});

async function captureLogs<T>(fn: () => Promise<T>): Promise<string[]> {
  const lines: string[] = [];
  const log = console.log;
  console.log = (...a: unknown[]) => { lines.push(a.join(' ')); };
  try {
    await fn();
  } finally {
    console.log = log;
  }
  return lines.filter(l => l.includes('[semantic-tools]'));
}

test('names are logged once per catalog load, not on every request', async () => {
  const first = await captureLogs(async () => { await connect([db('hr')], await loadSemanticRunnables([db('hr')], auth)); });
  assert.equal(first.length, 1, `one line for the fresh load: ${first.join(' | ')}`);
  const again = await captureLogs(async () => { await connect([db('hr')], await loadSemanticRunnables([db('hr')], auth)); });
  assert.deepEqual(again, [], 'a cached load and the per-request registration stay quiet');
});

test('declared params the filter never uses are dropped; limit/offset params reject the entry', () => {
  const warn = console.warn;
  const warnings: string[] = [];
  console.warn = (...a: unknown[]) => { warnings.push(a.join(' ')); };
  try {
    const rs = runnablesFrom('hr', {
      metrics: [{
        name: 'by dept', params: [{ name: 'dept', type: 'string' }, { name: 'unused', type: 'string' }],
        run_with: { tool: 'aggregate_data', arguments: { table_name: 't', aggregates: [], filter: "dept_no = '{dept}'" } }
      }],
      verified_queries: [
        { name: 'paged', params: [{ name: 'limit', type: 'integer' }], run_with: { tool: 'get_table_data', arguments: { table_name: 't', filter: 'n < {limit}' } } },
        { name: 'offset', params: [{ name: 'offset', type: 'integer' }], run_with: { tool: 'get_table_data', arguments: { table_name: 't' } } },
        { name: 'undeclared', params: [], run_with: { tool: 'get_table_data', arguments: { table_name: 't', filter: 'n = {n}' } } }
      ]
    });
    assert.deepEqual(rs.map(r => r.name), ['by dept']);
    assert.deepEqual(rs[0].params!.map(p => p.name), ['dept']);
  } finally {
    console.warn = warn;
  }
  assert.ok(warnings.some(w => w.includes('unused')), 'the dropped param is named');
  assert.ok(warnings.some(w => w.includes('paged') && w.includes('limit')));
  assert.ok(warnings.some(w => w.includes('undeclared') && w.includes('{n}')));
});

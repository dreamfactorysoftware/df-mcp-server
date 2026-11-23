import { test } from 'node:test';
import assert from 'node:assert/strict';
import { adaptQueryTemplates, adaptSemantics, dropTemplates, indexDataModel, slimDataModel } from './tools.service.js';
import { applyAggregateAliases, DreamFactoryService } from './dreamfactory.service.js';

const model = () => ({
  query_templates: {
    count_driver: { tool: 'get_table_data', params: { table_name: 'driver', count_only: true } },
    traverse_hierarchy_dept: { description: 'steps only', steps: ['1'] }
  }
});

test('prefixed: tool name gets the service prefix, no service arg', () => {
  const t = adaptQueryTemplates(model(), { prefix: 'logistics' }).query_templates;
  assert.equal(t.count_driver.tool, 'logistics_get_table_data');
  assert.equal(t.count_driver.params.service, undefined);
});

test('merged: bare tool name, service arg added', () => {
  const t = adaptQueryTemplates(model(), { service: 'logistics' }).query_templates;
  assert.equal(t.count_driver.tool, 'get_table_data');
  assert.deepEqual(t.count_driver.params, { table_name: 'driver', count_only: true, service: 'logistics' });
});

test('single-service merged collapse and step-only templates are left alone', () => {
  const t = adaptQueryTemplates(model(), {}).query_templates;
  assert.deepEqual(t.count_driver, model().query_templates.count_driver);
  assert.deepEqual(t.traverse_hierarchy_dept, model().query_templates.traverse_hierarchy_dept);
});

test('models without templates pass through', () => {
  assert.deepEqual(adaptQueryTemplates({ tables: [] }, { prefix: 'x' }), { tables: [] });
  assert.equal(adaptQueryTemplates('raw', { prefix: 'x' }), 'raw');
});

const semantics = () => ({
  service: 'hr',
  semantics: {
    guidance: 'g',
    metrics: [{ name: 'headcount', run_with: { tool: 'aggregate_data', service: 'hr', arguments: { table_name: 'dept_emp', aggregates: [{ function: 'COUNT', field: '*', alias: 'headcount' }] } } }],
    verified_queries: [{ name: 'managers', run_with: { tool: 'get_table_data', service: 'hr', arguments: { table_name: 'dept_manager' } } }]
  }
});

test('semantics prefixed: run_with names the prefixed tool and drops the service key', () => {
  const s = adaptSemantics(semantics(), { prefix: 'hr' }).semantics;
  assert.equal(s.metrics[0].run_with.tool, 'hr_aggregate_data');
  assert.equal(s.verified_queries[0].run_with.tool, 'hr_get_table_data');
  assert.equal(s.metrics[0].run_with.service, undefined);
  assert.equal(s.metrics[0].run_with.arguments.service, undefined);
});

test('semantics merged multi-db: bare verb, service in the arguments', () => {
  const s = adaptSemantics(semantics(), { service: 'hr' }).semantics;
  assert.equal(s.metrics[0].run_with.tool, 'aggregate_data');
  assert.equal(s.metrics[0].run_with.arguments.service, 'hr');
  assert.equal(s.verified_queries[0].run_with.arguments.service, 'hr');
  assert.equal(s.verified_queries[0].run_with.service, undefined);
});

test('semantics merged single-db: bare verb, no service anywhere; non-semantic payloads pass through', () => {
  const s = adaptSemantics(semantics(), {}).semantics;
  assert.equal(s.metrics[0].run_with.tool, 'aggregate_data');
  assert.deepEqual(s.metrics[0].run_with.arguments, { table_name: 'dept_emp', aggregates: [{ function: 'COUNT', field: '*', alias: 'headcount' }] });
  assert.equal(s.metrics[0].run_with.service, undefined);
  assert.deepEqual(adaptSemantics({ semantics: null, note: 'n' }, { prefix: 'x' }), { semantics: null, note: 'n' });
  assert.equal(adaptSemantics('raw', { prefix: 'x' }), 'raw');
});

test('aggregate aliases replace DreamFactory FN_column keys, COUNT(*) is COUNT_ALL', () => {
  const rows = [{ dept_no: 'd001', COUNT_ALL: 3, SUM_salary: 10, AVG_salary: 5 }];
  const out = applyAggregateAliases(rows, [
    { function: 'count', field: '*', alias: 'headcount' },
    { function: 'SUM', field: 'salary', alias: 'payroll' },
    { function: 'AVG', field: 'salary' }
  ]);
  assert.deepEqual(out, [{ dept_no: 'd001', headcount: 3, payroll: 10, AVG_salary: 5 }]);
  assert.deepEqual(applyAggregateAliases(rows, [{ function: 'SUM', field: 'salary' }]), rows, 'no aliases: untouched');
});

const fullModel = () => ({
  service: 'hr',
  tables: {
    employees: { columns: [{ name: 'emp_no' }], row_count: 3, sample_data: [{ emp_no: 1 }, { emp_no: 2 }, { emp_no: 3 }], enum_values: { gender: ['F', 'M'] } },
    departments: { columns: [{ name: 'dept_no' }], row_count: 30, sample_data: [{ dept_no: 'd001' }], enum_values: { dept_no: Array.from({ length: 30 }, (_, i) => `d${i}`) } }
  },
  relationships: { employees: [{ to: 'departments' }], departments: [] },
  query_templates: { count: { tool: 'get_table_data' } },
  semantics: { metrics: [{ name: 'headcount' }] }
});

test('data model: one sample row, capped enums, templates dropped when the catalog has runnable entries', () => {
  const m = dropTemplates(slimDataModel(fullModel()));
  assert.equal(m.tables.employees.sample_data.length, 1);
  assert.equal(m.tables.departments.enum_values.dept_no.length, 21);
  assert.match(String(m.tables.departments.enum_values.dept_no[20]), /10 more/);
  assert.equal(m.query_templates, undefined);
  assert.equal(m.tables_omitted, undefined);
});

test('data model: templates kept without a catalog or when asked; samples 0 removes them', () => {
  const noCatalog = fullModel(); delete noCatalog.semantics;
  assert.ok(dropTemplates(noCatalog).query_templates);
  assert.ok(dropTemplates(fullModel(), true).query_templates);
  assert.equal(dropTemplates(noCatalog, false).query_templates, undefined);
  assert.ok(slimDataModel(fullModel()).query_templates, 'compact trimming alone never drops templates');
  assert.equal(slimDataModel(fullModel(), { samples: 0 }).tables.employees.sample_data, undefined);
});

test('data model: tables filter keeps the named tables and their relationships, counts the rest', () => {
  const m = slimDataModel(fullModel(), { tables: ['employees'] });
  assert.deepEqual(Object.keys(m.tables), ['employees']);
  assert.deepEqual(Object.keys(m.relationships), ['employees']);
  assert.equal(m.tables_omitted, 1);
  assert.equal(slimDataModel('raw' as any), 'raw');
});

const wideModel = (n: number) => {
  const m: any = fullModel();
  for (let i = 0; i < n; i++) {
    m.tables[`t${i}`] = { columns: [{ name: 'id', type: 'integer', pk: true }, { name: 'ref', type: 'reference', fk: 'employees.emp_no' }], row_count: i, sample_data: [{ id: 1 }], enum_values: { id: [1] } };
  }
  return m;
};

test('data model: a large database answers with an index unless tables or detail is asked', () => {
  const idx = slimDataModel(wideModel(20));
  assert.equal(idx.index_only, true);
  assert.deepEqual(idx.tables.t3, { row_count: 3, columns: ['id:integer pk', 'ref:reference -> employees.emp_no'], enum_values: { id: [1] } });
  assert.deepEqual(idx.tables.employees.enum_values, { gender: ['F', 'M'] }, 'small enumerations stay in the index');
  assert.equal(idx.tables.departments.enum_values, undefined, 'a 30-value enumeration does not');
  assert.equal(idx.tables.employees.sample_data, undefined);
  assert.deepEqual(idx.relationships.employees, ['? (rel -> ?)']);
  assert.ok(idx.semantics, 'the catalog block rides along');
  assert.equal(idx.query_templates, undefined);
  assert.match(idx.description, /Index of 22 tables/);

  assert.equal(slimDataModel(wideModel(20), { detail: true }).index_only, undefined);
  assert.deepEqual(Object.keys(slimDataModel(wideModel(20), { tables: ['t1'] }).tables), ['t1']);
  assert.equal(slimDataModel(fullModel()).index_only, undefined, 'small databases get the slim model');
  assert.equal(indexDataModel({ service: 's', tables: {} }).tables && Object.keys(indexDataModel({ service: 's', tables: {} }).tables).length, 0);
});

test('aggregate aliases never overwrite a group column or an earlier alias', () => {
  const rows = [{ dept_no: 'd001', COUNT_ALL: 3, SUM_salary: 10, AVG_salary: 5 }];
  const out = applyAggregateAliases(rows, [
    { function: 'COUNT', field: '*', alias: 'dept_no' },
    { function: 'SUM', field: 'salary', alias: 'pay' },
    { function: 'AVG', field: 'salary', alias: 'pay' }
  ], ['dept_no']);
  assert.deepEqual(out, [{ dept_no: 'd001', COUNT_ALL: 3, pay: 10, AVG_salary: 5 }]);
});

test('client-side fallback with aliases on: an alias equal to a group column keeps the group value', async () => {
  const original = DreamFactoryService.getTableData;
  (DreamFactoryService as any).getTableData = async (_b: string, _a: unknown, args: any) => {
    if (Array.isArray(args.fields) && args.fields.some((f: string) => f.includes('('))) throw new Error('aggregate fields unsupported');
    return { resource: args.offset ? [] : [{ dept_no: 'd1', salary: 1 }, { dept_no: 'd1', salary: 2 }, { dept_no: 'd2', salary: 5 }] };
  };
  try {
    const r: any = await DreamFactoryService.aggregateData('http://df/api/v2/hr', { apiKey: 'k' },
      { table_name: 's', aggregates: [{ function: 'COUNT', field: '*', alias: 'dept_no' }, { function: 'SUM', field: 'salary', alias: 'total' }], group_by: ['dept_no'] },
      { aliases: true });
    assert.deepEqual(r.results, [{ dept_no: 'd1', COUNT_ALL: 2, total: 3 }, { dept_no: 'd2', COUNT_ALL: 1, total: 5 }]);
  } finally {
    (DreamFactoryService as any).getTableData = original;
  }
});

test('client-side fallback under the group cap keeps the first groups by group_by order and says so', async () => {
  const original = DreamFactoryService.getTableData;
  (DreamFactoryService as any).getTableData = async (_b: string, _a: unknown, args: any) => {
    if (Array.isArray(args.fields) && args.fields.some((f: string) => f.includes('('))) throw new Error('aggregate fields unsupported');
    return { resource: args.offset ? [] : [{ g: 'c', n: 1 }, { g: 10, n: 1 }, { g: 'a', n: 1 }, { g: 9, n: 1 }, { g: 'b', n: 1 }] };
  };
  try {
    const r: any = await DreamFactoryService.aggregateData('http://df/api/v2/hr', { apiKey: 'k' },
      { table_name: 's', aggregates: [{ function: 'COUNT', field: '*' }], group_by: ['g'] }, { groupLimit: 3 });
    assert.deepEqual(r.results.map((x: any) => x.g), [9, 10, 'a'], 'sorted like the server-side ORDER BY, not first-seen');
    assert.equal(r.truncated, true);
    assert.equal(r.groups, 5);
    assert.match(r.note, /first 3 of 5 groups/);
    assert.match(r.note, /ordered by g/);
  } finally {
    (DreamFactoryService as any).getTableData = original;
  }
});

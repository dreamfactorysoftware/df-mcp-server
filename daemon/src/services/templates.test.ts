import { test } from 'node:test';
import assert from 'node:assert/strict';
import { adaptQueryTemplates } from './tools.service.js';

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

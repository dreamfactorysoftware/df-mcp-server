import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createServer } from '../utils/utils.js';
import { SessionService } from './session.service.js';
import { FILE_TOOL_NAMES } from './file-api.tools.js';
import type { ApiConfig } from '../types.js';

// Merged mode for file services (tool_style = 'merged'). Prefixed mode emits
// every file verb once per service; merged registers each verb once with a
// `service` argument, the same shape the database tools already use.

const file = (name: string): ApiConfig =>
  ({ name, baseUrl: `http://df/api/v2/${name}`, category: 'file', type: 'local_file' }) as ApiConfig;

async function listTools(configs: ApiConfig[], disabled?: Set<string>, style: 'merged' | 'prefixed' = 'merged') {
  const server = createServer('t', configs, new SessionService(), disabled, undefined, 'off', style);
  const client = new Client({ name: 'test', version: '1' });
  const [a, b] = InMemoryTransport.createLinkedPair();
  await server.connect(a);
  await client.connect(b);
  return (await client.listTools()).tools;
}

const names = (tools: Array<{ name: string }>) => tools.map(t => t.name);
const serviceEnum = (tool: any): string[] | undefined =>
  tool?.inputSchema?.properties?.service?.enum;

test('merged: each file verb registered once, bare, with no prefixed duplicates', async () => {
  const tools = await listTools([file('files'), file('logs')]);
  const got = names(tools);
  for (const verb of FILE_TOOL_NAMES) {
    assert.equal(got.filter(n => n === verb).length, 1, `${verb} should be registered exactly once`);
  }
  assert.equal(got.some(n => n.startsWith('files_') || n.startsWith('logs_')), false,
    'merged mode must not register per-service prefixed file tools');
});

test('merged: the service enum lists every exposed file service', async () => {
  const tools = await listTools([file('files'), file('logs'), file('dev_scripts')]);
  const listFiles = tools.find(t => t.name === 'list_files');
  assert.deepEqual(serviceEnum(listFiles), ['files', 'logs', 'dev_scripts']);
});

test('merged: a single file service drops the service argument', async () => {
  const tools = await listTools([file('files')]);
  const listFiles = tools.find(t => t.name === 'list_files');
  assert.equal(serviceEnum(listFiles), undefined, 'nothing to disambiguate with one service');
  assert.match(listFiles!.description!, /^\[files\]/);
});

test('merged: a per-service disable removes only that service from the enum', async () => {
  const tools = await listTools(
    [file('files'), file('logs'), file('dev_scripts')],
    new Set(['logs_delete_file'])
  );
  const del = tools.find(t => t.name === 'delete_file');
  assert.ok(del, 'delete_file stays registered for the services that still allow it');
  assert.deepEqual(serviceEnum(del), ['files', 'dev_scripts'], 'the disabled service is not offered');
  // Untouched verbs keep every service.
  assert.deepEqual(serviceEnum(tools.find(t => t.name === 'list_files')), ['files', 'logs', 'dev_scripts']);
});

test('merged: a disable that leaves one service collapses the service argument', async () => {
  const tools = await listTools([file('files'), file('logs')], new Set(['logs_delete_file']));
  const del = tools.find(t => t.name === 'delete_file');
  assert.ok(del, 'still registered for the one service that allows it');
  assert.equal(serviceEnum(del), undefined, 'nothing left to disambiguate');
  assert.match(del!.description!, /^\[files\]/);
});

test('merged: disabled for every service, or by bare name, drops the tool', async () => {
  const everywhere = await listTools(
    [file('files'), file('logs')],
    new Set(['files_delete_file', 'logs_delete_file'])
  );
  assert.equal(names(everywhere).includes('delete_file'), false);

  const bare = await listTools([file('files'), file('logs')], new Set(['delete_file']));
  assert.equal(names(bare).includes('delete_file'), false);
});

test('merged: the cross-service aggregator survives, and only with 2+ services', async () => {
  const two = await listTools([file('files'), file('logs')]);
  assert.equal(names(two).includes('all_list_files'), true);

  const one = await listTools([file('files')]);
  assert.equal(names(one).includes('all_list_files'), false);
});

test('prefixed: file tools are unchanged', async () => {
  const tools = await listTools([file('files'), file('logs')], undefined, 'prefixed');
  const got = names(tools);
  for (const verb of FILE_TOOL_NAMES) {
    assert.ok(got.includes(`files_${verb}`), `files_${verb} missing`);
    assert.ok(got.includes(`logs_${verb}`), `logs_${verb} missing`);
    assert.equal(got.includes(verb), false, `${verb} must stay prefixed in prefixed mode`);
  }
});

test('merged: file and database verbs coexist, each with their own service enum', async () => {
  const db = (name: string): ApiConfig =>
    ({ name, baseUrl: `http://df/api/v2/${name}`, category: 'database', type: 'mysql' }) as ApiConfig;
  const tools = await listTools([file('files'), file('logs'), db('ordersdb'), db('crm')]);
  assert.deepEqual(serviceEnum(tools.find(t => t.name === 'list_files')), ['files', 'logs']);
  assert.deepEqual(serviceEnum(tools.find(t => t.name === 'get_tables')), ['ordersdb', 'crm']);
});

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createServer } from '../utils/utils.js';
import { SessionService } from './session.service.js';
import type { ApiConfig } from '../types.js';

// Run: npm test (node --import tsx --test)
//
// Golden snapshot of tools/list for a prefixed two-database server (issue #66).
// Tool names, argument names, descriptions and annotations are the contract
// MCP clients (and their prompt caches) depend on, so a change here must be
// deliberate: review the diff, then `UPDATE_SNAPSHOT=1 npm test`.
//
// tools.snapshot.json is the 1.4.1 release snapshot, byte for byte, and must
// stay so: with semantic_catalog, semantic_tools and compact_results all off
// the daemon is indistinguishable from 1.4.1. That matters beyond tidiness:
// the stock demo catalog is 32,758 B, 10 B under the 32,768 B lazy threshold,
// so one extra optional argument flips a client onto the lazy facade.
// tools.baseline-1.4.1.json holds tools/list AND the server instructions that
// the 1.4.1 tree produced for five endpoint shapes (generated from
// `git archive 1.4.1 daemon`, never by UPDATE_SNAPSHOT).

const SNAPSHOT = new URL('./tools.snapshot.json', import.meta.url);
const SNAPSHOT_ON = new URL('./tools.snapshot.flags-on.json', import.meta.url);
const BASELINE = new URL('./tools.baseline-1.4.1.json', import.meta.url);
const db = (name: string): ApiConfig => ({ name, baseUrl: `http://df/api/v2/${name}`, category: 'database', type: 'mysql' });

async function list(server: ReturnType<typeof createServer>) {
  const client = new Client({ name: 'claude-code', version: '1' });
  const [a, b] = InMemoryTransport.createLinkedPair();
  await server.connect(a);
  await client.connect(b);
  const tools = (await client.listTools()).tools;
  const instructions = client.getInstructions();
  await client.close();
  return { tools, instructions };
}

test('tools/list golden snapshot: prefixed, two databases, lazy off, all flags off == 1.4.1', async () => {
  const server = createServer('snap', [db('ordersdb'), db('crm')], new SessionService(), undefined, undefined, 'off', 'prefixed');
  const actual = JSON.stringify((await list(server)).tools, null, 2) + '\n';
  const expected = readFileSync(SNAPSHOT, 'utf8');
  assert.equal(actual, expected, 'tools/list with every opt-in flag off must equal the 1.4.1 snapshot');
});

test('all flags off: tools/list and instructions equal 1.4.1 for every recorded endpoint shape', async () => {
  const baseline = JSON.parse(readFileSync(BASELINE, 'utf8')) as any[];
  assert.ok(baseline.length >= 5);
  for (const s of baseline) {
    const flags = { semanticCatalog: false, compactResults: false, semanticRunnables: [] };
    const server = createServer('snap', s.apis, new SessionService(), undefined, undefined, s.lazy, s.style, s.writes, flags);
    const { tools, instructions } = await list(server);
    assert.equal(instructions, s.instructions, `${s.name}: instructions differ from 1.4.1`);
    // Compared as the JSON a client receives (undefined fields are not on the wire).
    assert.deepEqual(JSON.parse(JSON.stringify(tools)), s.tools, `${s.name}: tools/list differs from 1.4.1`);
  }
});

test('tools/list golden snapshot: semantic_catalog + compact_results on', async () => {
  const server = createServer('snap', [db('ordersdb'), db('crm')], new SessionService(), undefined, undefined, 'off', 'prefixed', true,
    { semanticCatalog: true, compactResults: true });
  const { tools, instructions } = await list(server);
  const actual = JSON.stringify({ instructions, tools }, null, 2) + '\n';
  if (process.env.UPDATE_SNAPSHOT) {
    writeFileSync(SNAPSHOT_ON, actual);
    return;
  }
  assert.equal(actual, readFileSync(SNAPSHOT_ON, 'utf8'), 'flags-on tools/list changed — if intended, run UPDATE_SNAPSHOT=1 npm test and commit tools.snapshot.flags-on.json');
  const names = tools.map(t => t.name);
  assert.ok(names.includes('ordersdb_get_semantics'));
  const props = (n: string) => Object.keys((tools.find(t => t.name === n)!.inputSchema as any).properties ?? {});
  assert.deepEqual(props('crm_get_data_model').sort(), ['detail', 'refresh', 'samples', 'tables', 'templates']);
  assert.ok(props('crm_aggregate_data').includes('limit'));
  assert.match(instructions ?? '', /get_semantics` FIRST/);
});

test('each flag alone changes only its own surface', async () => {
  const build = (f: { semanticCatalog?: boolean; compactResults?: boolean }) =>
    list(createServer('snap', [db('hr')], new SessionService(), undefined, undefined, 'off', 'prefixed', true, f));
  const catalog = await build({ semanticCatalog: true });
  const cNames = catalog.tools.map(t => t.name);
  assert.ok(cNames.includes('hr_get_semantics'));
  const dm = catalog.tools.find(t => t.name === 'hr_get_data_model')!;
  assert.deepEqual(Object.keys((dm.inputSchema as any).properties).sort(), ['refresh', 'templates']);
  assert.match(dm.description!, /Call get_semantics first/);
  assert.doesNotMatch(dm.description!, /index/);
  assert.ok(!Object.keys((catalog.tools.find(t => t.name === 'hr_aggregate_data')!.inputSchema as any).properties).includes('limit'));

  const compact = await build({ compactResults: true });
  assert.ok(!compact.tools.some(t => t.name.endsWith('get_semantics')));
  assert.doesNotMatch(compact.instructions ?? '', /get_semantics/);
  const cdm = compact.tools.find(t => t.name === 'hr_get_data_model')!;
  assert.deepEqual(Object.keys((cdm.inputSchema as any).properties).sort(), ['detail', 'refresh', 'samples', 'tables']);
  assert.match(cdm.description!, /^\[hr\] BEST FIRST CALL/);
  assert.match(compact.tools.find(t => t.name === 'hr_get_table_data')!.description!, /50 rows unless limit/);
});

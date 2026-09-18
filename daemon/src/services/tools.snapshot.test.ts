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

const SNAPSHOT = new URL('./tools.snapshot.json', import.meta.url);
const db = (name: string): ApiConfig => ({ name, baseUrl: `http://df/api/v2/${name}`, category: 'database', type: 'mysql' });

test('tools/list golden snapshot: prefixed, two databases, lazy off', async () => {
  const server = createServer('snap', [db('ordersdb'), db('crm')], new SessionService(), undefined, undefined, 'off', 'prefixed');
  const client = new Client({ name: 'claude-code', version: '1' });
  const [a, b] = InMemoryTransport.createLinkedPair();
  await server.connect(a);
  await client.connect(b);
  const actual = JSON.stringify((await client.listTools()).tools, null, 2) + '\n';

  if (process.env.UPDATE_SNAPSHOT) {
    writeFileSync(SNAPSHOT, actual);
    return;
  }
  const expected = readFileSync(SNAPSHOT, 'utf8');
  assert.equal(actual, expected, 'tools/list changed — if intended, run UPDATE_SNAPSHOT=1 npm test and commit tools.snapshot.json');
});

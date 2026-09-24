import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createServer } from '../utils/utils.js';
import { SessionService } from './session.service.js';
import { executeFunctionToolRequest } from './custom-tools.service.js';
import type { CustomToolDefinition } from '../types.js';

// Run: npm test (node --import tsx --test)
//
// Function custom tools run admin-authored JS through new Function(), so they
// are opt-in: only MCP_ALLOW_FUNCTION_TOOLS=true registers and runs them.

const tools: CustomToolDefinition[] = [
  { name: 'add_numbers', description: 'fn', tool_type: 'function', parameters: [], function: 'return 1;' },
  // No tool_type, no url, a body: still a function tool.
  { name: 'implicit_fn', description: 'fn', parameters: [], function: 'return 2;' } as CustomToolDefinition,
  { name: 'lookup', description: 'api', tool_type: 'api', http_method: 'GET', url: 'http://x/', parameters: [] } as CustomToolDefinition,
];

async function connect() {
  const server = createServer('svc', [], new SessionService(), undefined, tools, 'off');
  const client = new Client({ name: 'test', version: '1' });
  const [a, b] = InMemoryTransport.createLinkedPair();
  await server.connect(a);
  await client.connect(b);
  return client;
}

async function withFlag(value: string | undefined, fn: () => Promise<void>) {
  const prev = process.env.MCP_ALLOW_FUNCTION_TOOLS;
  if (value === undefined) delete process.env.MCP_ALLOW_FUNCTION_TOOLS;
  else process.env.MCP_ALLOW_FUNCTION_TOOLS = value;
  try { await fn(); } finally {
    if (prev === undefined) delete process.env.MCP_ALLOW_FUNCTION_TOOLS;
    else process.env.MCP_ALLOW_FUNCTION_TOOLS = prev;
  }
}

for (const value of [undefined, 'false', '1', 'yes']) {
  test(`MCP_ALLOW_FUNCTION_TOOLS=${value ?? '(unset)'}: function tools are not registered and the instructions say why`, () => withFlag(value, async () => {
    const client = await connect();
    const names = (await client.listTools()).tools.map(t => t.name);
    assert.ok(names.includes('lookup'), 'api custom tools are unaffected');
    assert.ok(!names.includes('add_numbers') && !names.includes('implicit_fn'), `function tools hidden, got ${names}`);
    const instructions = client.getInstructions() ?? '';
    assert.match(instructions, /2 function tools are configured but not available/);
    assert.match(instructions, /MCP_ALLOW_FUNCTION_TOOLS=true/);
    assert.match(instructions, /add_numbers, implicit_fn/);

    // Backstop: even called directly, the body never runs.
    const r: any = await executeFunctionToolRequest(tools[0], {});
    assert.equal(r.isError, true);
    assert.match(r.content[0].text, /disabled/);
  }));
}

test('MCP_ALLOW_FUNCTION_TOOLS=true: function tools are registered and run', () => withFlag('TRUE', async () => {
  const client = await connect();
  const names = (await client.listTools()).tools.map(t => t.name);
  assert.ok(names.includes('add_numbers') && names.includes('implicit_fn') && names.includes('lookup'));
  assert.doesNotMatch(client.getInstructions() ?? '', /not available/);
  const r: any = await client.callTool({ name: 'add_numbers', arguments: {} });
  assert.equal(r.isError ?? false, false);
  assert.equal(r.content[0].text, '1');
}));

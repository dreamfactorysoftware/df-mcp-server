import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, type ChildProcess } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// Run: npm test (node --import tsx --test)
//
// Boots the real daemon (src/server.ts) on an ephemeral port per session mode
// and drives it over HTTP the way the PHP proxy does: an envelope carrying the
// MCP payload plus a function custom tool, and an empty service catalog so no
// DreamFactory call is ever made.

const here = path.dirname(fileURLToPath(import.meta.url));
const daemonRoot = path.join(here, '..', '..');
const tsx = path.join(daemonRoot, 'node_modules', 'tsx', 'dist', 'cli.cjs');

async function boot(env: Record<string, string>): Promise<{ proc: ChildProcess; url: string }> {
  const proc = spawn(process.execPath, [tsx, path.join(daemonRoot, 'src', 'server.ts')], {
    env: { PATH: process.env.PATH ?? '', MCP_DAEMON_PORT: '0', MCP_DAEMON_HOST: '127.0.0.1', ...env },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  const url = await new Promise<string>((resolve, reject) => {
    let out = '';
    proc.stdout!.on('data', (d: Buffer) => {
      out += d.toString();
      const m = out.match(/listening on (http:\/\/[^\s]+)/);
      if (m) resolve(m[1]);
    });
    proc.stderr!.on('data', (d: Buffer) => { out += d.toString(); });
    proc.on('exit', code => reject(new Error(`daemon exited ${code}: ${out}`)));
    setTimeout(() => reject(new Error(`daemon did not start: ${out}`)), 20_000).unref();
  });
  return { proc, url };
}

const envelope = (payload: unknown, lazyMode = 'off') => JSON.stringify({
  _mcpPayload: payload,
  _mcpConfig: {
    lazy_mode: lazyMode,
    custom_tools: [
      { name: 'add_numbers', description: 'Add', tool_type: 'function', enabled: true,
        parameters: [{ name: 'a', type: 'number', in: 'body', required: true }, { name: 'b', type: 'number', in: 'body', required: true }],
        function: 'return { sum: a + b };' },
      { name: 'big_text', description: 'Long result', tool_type: 'function', enabled: true, parameters: [],
        function: 'return { text: "x".repeat(20000) };' },
    ],
  },
  _mcpAvailableServices: [],
});

async function rpc(url: string, payload: unknown, sessionId?: string, lazyMode?: string, extraHeaders: Record<string, string> = {}) {
  const res = await fetch(`${url}/mcp/svc`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json, text/event-stream',
      'X-Mcp-Base-Url': 'http://127.0.0.1:1/api/v2',
      'X-DreamFactory-Session-Token': 'test-token',
      ...(sessionId ? { 'Mcp-Session-Id': sessionId } : {}),
      ...extraHeaders,
    },
    body: envelope(payload, lazyMode),
  });
  const text = await res.text();
  return { status: res.status, sessionId: res.headers.get('mcp-session-id'), ledger: res.headers.get('x-mcp-ledger'), body: text ? JSON.parse(text) : null };
}

const initialize = { jsonrpc: '2.0', id: 1, method: 'initialize',
  params: { protocolVersion: '2025-03-26', capabilities: {}, clientInfo: { name: 'test', version: '0' } } };
const initialized = { jsonrpc: '2.0', method: 'notifications/initialized' };
const toolsList = { jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} };
const toolsCall = { jsonrpc: '2.0', id: 3, method: 'tools/call', params: { name: 'add_numbers', arguments: { a: 2, b: 3 } } };

async function fullFlow(url: string, sessionId?: string) {
  assert.equal((await rpc(url, initialized, sessionId)).status, 202);
  const list = await rpc(url, toolsList, sessionId);
  assert.equal(list.status, 200, JSON.stringify(list.body));
  assert.ok(list.body.result.tools.some((t: any) => t.name === 'add_numbers'), 'custom tool advertised');
  const call = await rpc(url, toolsCall, sessionId);
  assert.equal(call.status, 200, JSON.stringify(call.body));
  assert.equal(JSON.parse(call.body.result.content[0].text).sum, 5);
}

test('default (unset) is stateless: no session id, every request stands alone', async () => {
  const { proc, url } = await boot({});
  try {
    const health = await (await fetch(`${url}/health`)).json();
    assert.equal(health.mode, 'stateless');

    const init = await rpc(url, initialize);
    assert.equal(init.status, 200);
    assert.equal(init.sessionId, null);
    assert.match(init.body.result.serverInfo.name, /svc/);

    // Separate HTTP requests, no Mcp-Session-Id — the load-balanced case.
    await fullFlow(url);
    // A stale/foreign session id (another node's) is ignored, not rejected.
    await fullFlow(url, 'some-other-nodes-session');
    // tools/call with no prior handshake at all (rpc bridge style) also works.
    assert.equal((await rpc(url, toolsCall)).status, 200);

    const get = await fetch(`${url}/mcp/svc`, { headers: { 'X-DreamFactory-Session-Token': 't' } });
    assert.equal(get.status, 405);
  } finally { proc.kill(); }
});

test('stateless: lazy facade page handles survive across separate requests on one node', async () => {
  const { proc, url } = await boot({});
  try {
    const call = { jsonrpc: '2.0', id: 4, method: 'tools/call', params: { name: 'call_tool', arguments: { name: 'big_text', arguments: {} } } };
    const first = await rpc(url, call, undefined, 'on');
    assert.equal(first.status, 200, JSON.stringify(first.body));
    const m = (first.body.result.content[0].text as string).match(/handle":"([^"]+)","offset":(\d+)/);
    assert.ok(m, `first page carries a fetch_more handle: ${JSON.stringify(first.body).slice(0, 300)}`);
    // The savings ledger PHP copies into mcp_request_log is attached per request too.
    assert.equal(JSON.parse(first.ledger!).mode, 'lazy');
    const more = await rpc(url, { jsonrpc: '2.0', id: 5, method: 'tools/call',
      params: { name: 'fetch_more', arguments: { handle: m![1], offset: Number(m![2]) } } }, undefined, 'on');
    assert.equal(more.status, 200, JSON.stringify(more.body));
    const page = JSON.parse(more.body.result.content[0].text);
    assert.equal(page.handle, m![1]);
    assert.ok(page.text.length > 0);
  } finally { proc.kill(); }
});

test('stateless: X-Mcp-Client-Name drives lazy passthrough when no initialize was seen', async () => {
  const { proc, url } = await boot({});
  try {
    const names = async (headers: Record<string, string>) => {
      const list = await rpc(url, toolsList, undefined, 'on', headers);
      assert.equal(list.status, 200, JSON.stringify(list.body));
      return list.body.result.tools.map((t: any) => t.name) as string[];
    };
    const facade = await names({});
    assert.ok(facade.includes('search_tools'), 'unknown client gets the facade');
    assert.ok(!facade.includes('big_text'));

    const full = await names({ 'X-Mcp-Client-Name': 'Codex CLI' });
    assert.ok(!full.includes('search_tools'), 'passthrough client gets no facade');
    assert.ok(full.includes('big_text') && full.includes('add_numbers'), 'full catalog');
  } finally { proc.kill(); }
});

test('MCP_STATELESS=false keeps stateful sessions pinned to the process', async () => {
  const { proc, url } = await boot({ MCP_STATELESS: 'false' });
  try {
    const health = await (await fetch(`${url}/health`)).json();
    assert.equal(health.mode, 'stateful');

    const init = await rpc(url, initialize);
    assert.equal(init.status, 200);
    assert.ok(init.sessionId, 'stateful mode issues Mcp-Session-Id');

    await fullFlow(url, init.sessionId!);

    // Without the session id a fresh transport sees a non-initialize request:
    // the exact failure a load balancer produces in stateful mode.
    const orphan = await rpc(url, toolsList);
    assert.equal(orphan.status, 400);
    assert.match(orphan.body.error.message, /Server not initialized/);
  } finally { proc.kill(); }
});

test('MCP_STATELESS=true still selects stateless explicitly', async () => {
  const { proc, url } = await boot({ MCP_STATELESS: 'true' });
  try {
    assert.equal((await (await fetch(`${url}/health`)).json()).mode, 'stateless');
  } finally { proc.kill(); }
});

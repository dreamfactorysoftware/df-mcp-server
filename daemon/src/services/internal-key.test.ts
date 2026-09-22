import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, type ChildProcess } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

// Run: npm test (node --import tsx --test)
//
// The shared-secret gate on the real daemon (src/server.ts), booted on an
// ephemeral port: every /mcp route needs X-Mcp-Internal-Key matching
// MCP_INTERNAL_KEY or the key file PHP writes; /health stays open.

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

const initialize = { jsonrpc: '2.0', id: 1, method: 'initialize',
  params: { protocolVersion: '2025-03-26', capabilities: {}, clientInfo: { name: 'test', version: '0' } } };

async function call(url: string, route: string, key?: string, body: unknown = {}) {
  const res = await fetch(`${url}${route}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json, text/event-stream',
      'X-Mcp-Base-Url': 'http://127.0.0.1:1/api/v2',
      'X-DreamFactory-Session-Token': 'test-token',
      ...(key !== undefined ? { 'X-Mcp-Internal-Key': key } : {}),
    },
    body: JSON.stringify(body),
  });
  await res.text();
  return res.status;
}

const mcpBody = { _mcpPayload: initialize, _mcpConfig: {}, _mcpAvailableServices: [] };
const previewBody = { serviceName: 'svc', _mcpConfig: {}, _mcpAvailableServices: [] };
const ROUTES: Array<[string, unknown]> = [
  ['/mcp/svc', mcpBody],
  ['/MCP/svc', mcpBody], // express routing is case-insensitive; so is the gate
  ['/mcp/catalog/preview', previewBody],
  ['/mcp/cache/clear', {}],
];

test('MCP_INTERNAL_KEY: no key and wrong key are 403 on every /mcp route, right key passes, /health open', async () => {
  const { proc, url } = await boot({ MCP_INTERNAL_KEY: 'right-key' });
  try {
    assert.equal((await fetch(`${url}/health`)).status, 200);
    for (const [route, body] of ROUTES) {
      assert.equal(await call(url, route, undefined, body), 403, `${route} without key`);
      assert.equal(await call(url, route, 'wrong-key', body), 403, `${route} wrong key`);
      assert.equal(await call(url, route, 'right-ke', body), 403, `${route} prefix of key`);
      assert.equal(await call(url, route, 'right-key', body), 200, `${route} right key`);
    }
  } finally { proc.kill(); }
});

test('key file: read lazily (written after boot), fails closed until it exists', async () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'mcp-key-'));
  const file = path.join(dir, 'mcp_internal_key');
  const { proc, url } = await boot({ MCP_INTERNAL_KEY_FILE: file });
  try {
    // No file yet: nothing gets through, not even an empty header.
    assert.equal(await call(url, '/mcp/svc', undefined, mcpBody), 403);
    assert.equal(await call(url, '/mcp/svc', '', mcpBody), 403);
    assert.equal(await call(url, '/mcp/svc', 'anything', mcpBody), 403);

    writeFileSync(file, 'file-key\n', { mode: 0o600 });
    assert.equal(await call(url, '/mcp/svc', 'wrong', mcpBody), 403);
    assert.equal(await call(url, '/mcp/svc', 'file-key', mcpBody), 200, 'trailing newline trimmed');

    // Rotated by PHP: the new key works without a restart, the old one stops.
    writeFileSync(file, 'rotated-key');
    assert.equal(await call(url, '/mcp/svc', 'rotated-key', mcpBody), 200);
    assert.equal(await call(url, '/mcp/svc', 'file-key', mcpBody), 403);
  } finally { proc.kill(); }
});

test('DF_APP_ROOT: key resolved under storage/framework, not storage/app', async () => {
  const root = mkdtempSync(path.join(tmpdir(), 'mcp-app-'));
  mkdirSync(path.join(root, 'storage', 'framework'), { recursive: true });
  mkdirSync(path.join(root, 'storage', 'app'), { recursive: true });
  writeFileSync(path.join(root, 'storage', 'app', 'mcp_internal_key'), 'app-key');
  writeFileSync(path.join(root, 'storage', 'framework', 'mcp_internal_key'), 'framework-key');
  const { proc, url } = await boot({ DF_APP_ROOT: root });
  try {
    assert.equal(await call(url, '/mcp/svc', 'app-key', mcpBody), 403);
    assert.equal(await call(url, '/mcp/svc', 'framework-key', mcpBody), 200);
  } finally { proc.kill(); }
});

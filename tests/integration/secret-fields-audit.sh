#!/usr/bin/env bash
# Regression test: every secret config field DreamFactory knows about is masked by df-system-mcp-server.
#
# Builds the secret field manifest (src/Support/SecretFieldManifest.php) from every service type registered
# in a running DreamFactory. Then a record of each type, with a sentinel in every secret field and in a
# credential-named key of every map field, goes through df-system-mcp-server's masker (build/redact.js).
# Fails if a sentinel survives, a readable value is masked, the daemon's parser would drop part of the
# manifest, or a known type-specific credential is missing from it.
#
# Prereqs: DreamFactory >= 7.7.0 with this package and a built df-system-mcp-server.
# Usage:
#   WEB_EXEC="docker exec -i -u www-data <web>" bash tests/integration/secret-fields-audit.sh
#   (docker compose: WEB_EXEC="docker compose exec -T -u www-data web")
# Optional: DF_ROOT (default /opt/dreamfactory)
#           MCP_PKG_DIR     df-mcp-server checkout inside the container (default: the vendor copy)
#           SYSTEM_MCP_DIR  df-system-mcp-server inside the container (default: the vendor copy)
set -u
WEB_EXEC=${WEB_EXEC:-sudo -n docker exec -i -u www-data df770_web_1}
DF_ROOT=${DF_ROOT:-/opt/dreamfactory}
MCP_PKG_DIR=${MCP_PKG_DIR:-$DF_ROOT/vendor/dreamfactory/df-mcp-server}
SYSTEM_MCP_DIR=${SYSTEM_MCP_DIR:-$DF_ROOT/vendor/dreamfactory/df-system-mcp-server}

echo "## 1. build the manifest from the registered service types"
MANIFEST=$($WEB_EXEC env DF_ROOT="$DF_ROOT" MCP_PKG_DIR="$MCP_PKG_DIR" php -d display_errors=stderr <<'PHP'
<?php
chdir(getenv('DF_ROOT'));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$class = DreamFactory\Core\McpServer\Support\SecretFieldManifest::class;
if (!class_exists($class, false)) {
    require getenv('MCP_PKG_DIR') . '/src/Support/SecretFieldManifest.php';
}
$types = \ServiceManager::getServiceTypes();
echo json_encode([
    'builder'  => (new ReflectionClass($class))->getFileName(),
    'types'    => array_values(array_map(fn ($t) => $t->getName(), $types)),
    'manifest' => $class::build($types),
]);
PHP
)
if [ -z "$MANIFEST" ]; then echo "  FAIL: no manifest from PHP (see errors above)"; echo "== 0 passed, 1 failed"; exit 1; fi

echo "## 2. mask a record of every type with df-system-mcp-server"
NODE_SCRIPT=$(cat <<'JS'
const { maskSecrets, parseSecretFieldManifest } = require(process.env.SYSTEM_MCP_DIR + "/build/redact.js");
const input = JSON.parse(require("fs").readFileSync(0, "utf8"));
const { manifest, types } = input;
let pass = 0, fail = 0;
const check = (cond, msg, detail) => {
  if (cond) { pass++; console.log(`  PASS: ${msg}`); } else { fail++; console.log(`  FAIL: ${msg} :: ${detail}`); }
};
const env = {}; // masking on, regardless of the container's MCP_EXPOSE_* settings

console.log(`  builder: ${input.builder}`);
const names = Object.keys(manifest);
check(types.length > 0 && names.length > 0, `manifest has entries (${names.length} of ${types.length} registered types)`, JSON.stringify(input).slice(0, 300));

const parsed = parseSecretFieldManifest(manifest) || {};
check(JSON.stringify({ ...parsed }) === JSON.stringify(manifest), "daemon accepts the whole manifest (nothing dropped by its limits)",
  names.filter((n) => JSON.stringify(parsed[n]) !== JSON.stringify(manifest[n])).join(", "));

const readable = names.filter((n) => manifest[n].secret.some((f) => f === "username" || f === "account_name"));
check(readable.length === 0, "username and account_name stay readable", readable.join(", "));

const leaks = [], overmasked = [], nameRulesMiss = [];
let fields = 0;
for (const type of names) {
  const entry = manifest[type];
  const config = { probe_host: "keep.example.com", username: "keep-user" };
  // PROBE_KEY looks like a credential as a map key, but no property-name rule catches it.
  for (const m of entry.maps) config[m] = { PROBE_KEY: `SENTINEL:${type}:${m}.PROBE_KEY`, PROBE_REGION: "keep-region" };
  for (const f of entry.secret) config[f] = `SENTINEL:${type}:${f}`;
  fields += entry.secret.length + entry.maps.length;
  const record = { resource: [{ id: 1, name: "probe", type, config }] };

  const masked = JSON.stringify(maskSecrets(record, env, { manifest: parsed }));
  for (const s of masked.match(/SENTINEL:[^"]+/g) || []) leaks.push(s);
  for (const keep of ["keep.example.com", "keep-user", ...(entry.maps.some((m) => !entry.secret.includes(m)) ? ["keep-region"] : [])]) {
    if (!masked.includes(keep)) overmasked.push(`${type}:${keep}`);
  }
  for (const s of JSON.stringify(maskSecrets(record, env)).match(/SENTINEL:[^"]+/g) || []) nameRulesMiss.push(s.slice(9));
}
check(leaks.length === 0, `every secret field and credential map key masked (${fields} fields)`, leaks.join(", "));
check(overmasked.length === 0, "non-secret config values stay readable", overmasked.join(", "));
console.log(`  info: ${nameRulesMiss.length} field(s) masked only thanks to the manifest: ${nameRulesMiss.join(", ")}`);

// Credentials the name rules missed in the 2026-09 audit; checked when the type is installed.
const expected = [
  ["gcm", "secret", "api_key"], ["openstack", "secret", "api_key"], ["rackspace", "secret", "api_key"],
  ["snowflake", "secret", "passcode"], ["apns", "secret", "certificate"],
  ["nodejs", "maps", "config"], ["python3", "maps", "config"], ["php", "maps", "config"],
  ["smtp_email", "secret", "password"], ["rws", "maps", "options"],
];
for (const [type, list, field] of expected) {
  if (!types.includes(type)) continue;
  check((manifest[type]?.[list] || []).includes(field), `manifest lists ${type} ${list} '${field}'`, JSON.stringify(manifest[type]));
}

console.log(`== ${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
JS
)
printf '%s' "$MANIFEST" | $WEB_EXEC env SYSTEM_MCP_DIR="$SYSTEM_MCP_DIR" node -e "$NODE_SCRIPT"

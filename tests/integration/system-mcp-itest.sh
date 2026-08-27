#!/usr/bin/env bash
# Integration test: System API MCP Server (system_mcp) service type, end-to-end against a running DreamFactory.
#
# Prereqs: DreamFactory >= 7.7.0 with this package installed, the data MCP daemon (MCP_DAEMON_URL) and
# df-system-mcp-server (MCP_SYSTEM_DAEMON_URL) both running and reachable from PHP.
# Usage:
#   BASE=http://localhost EMAIL=admin@example.com PASS='secret' bash tests/integration/system-mcp-itest.sh
# Optional: WEB_EXEC="docker exec <web>" (for the daemon-disabled check, edits .env + config:clear)
#           MYSQL_EXEC="docker exec <mysql> mysql -u.. -p.. -N -e" (for the audit-log check)
set -u
BASE=${BASE:-http://127.0.0.1:8770}
EMAIL=${EMAIL:-admin@df770.local}
PASS=${PASS:-Df770Passw0rdLong2026}
WEB_EXEC=${WEB_EXEC:-sudo -n docker exec df770_web_1}
MYSQL_EXEC=${MYSQL_EXEC:-sudo -n docker exec df770_mysql_1 mysql -udf_admin -pdf_admin -N -e}
SVC=sysmcp
DATA_SVC=datamcp
PASSC=0; FAILC=0
ok(){ echo "  PASS: $1"; PASSC=$((PASSC+1)); }
fail(){ echo "  FAIL: $1"; FAILC=$((FAILC+1)); }
check(){ if [ "$1" = "1" ]; then ok "$2"; else fail "$2 :: $3"; fi; }
jq_(){ python3 -c "import sys,json; d=json.load(sys.stdin); print(eval(sys.argv[1]))" "$1" 2>/dev/null; }

echo "## 0. login"
T=$(curl -s -X POST $BASE/api/v2/system/admin/session --data-urlencode email=$EMAIL --data-urlencode password=$PASS | jq_ 'd["session_token"]')
check "$([ ${#T} -gt 50 ] && echo 1)" "admin session token obtained" "empty token"
H=(-H "X-DreamFactory-Session-Token: $T" -H "Content-Type: application/json")

echo "## 1. service types"
TYPES=$(curl -s "${H[@]}" "$BASE/api/v2/system/service_type?group=MCP&fields=name,label")
echo "  $TYPES"
check "$(echo "$TYPES" | grep -q '"system_mcp"' && echo 1)" "system_mcp service type registered in group MCP" "$TYPES"
check "$(echo "$TYPES" | grep -q '"System API MCP Server"' && echo 1)" "label is 'System API MCP Server'" "$TYPES"
SCHEMA=$(curl -s "${H[@]}" "$BASE/api/v2/system/service_type/system_mcp")
check "$(echo "$SCHEMA" | grep -q 'oauth_client_id' && echo 1)" "system_mcp config schema exposes oauth fields" "$(echo $SCHEMA | head -c 300)"
check "$(echo "$SCHEMA" | grep -q 'custom_tools' && echo || echo 1)" "system_mcp config schema hides custom_tools" "schema mentions custom_tools"

echo "## 2. create services"
sid(){ curl -s "${H[@]}" "$BASE/api/v2/system/service?filter=name%3D$1&fields=id" | jq_ '(d["resource"] or [{}])[0].get("id","")'; }
for s in $SVC $DATA_SVC; do OLD=$(sid $s); [ -n "$OLD" ] && curl -s -o /dev/null "${H[@]}" -X DELETE "$BASE/api/v2/system/service/$OLD"; done
CREATE=$(curl -s "${H[@]}" -X POST "$BASE/api/v2/system/service?fields=id,name,type" -d "{\"resource\":[{\"name\":\"$SVC\",\"label\":\"System MCP\",\"type\":\"system_mcp\",\"is_active\":true,\"config\":{}}]}")
echo "  $CREATE"
check "$(echo "$CREATE" | grep -q "\"name\":\"$SVC\"" && echo 1)" "created system_mcp service '$SVC' with empty config (defaults auto-generated)" "$CREATE"
CREATE2=$(curl -s "${H[@]}" -X POST "$BASE/api/v2/system/service?fields=id,name,type" -d "{\"resource\":[{\"name\":\"$DATA_SVC\",\"label\":\"Data MCP\",\"type\":\"mcp\",\"is_active\":true,\"config\":{}}]}")
check "$(echo "$CREATE2" | grep -q "\"name\":\"$DATA_SVC\"" && echo 1)" "created regular mcp service '$DATA_SVC' (regression)" "$CREATE2"
SVCID=$(sid $SVC); SVCGET=$(curl -s "${H[@]}" "$BASE/api/v2/system/service/$SVCID")
CID=$(echo "$SVCGET" | jq_ 'd["config"]["oauth_client_id"]'); CSEC=$(echo "$SVCGET" | jq_ 'd["config"]["oauth_client_secret"]')
check "$(echo "$SVCGET" | grep -q '"custom_tools":\[\]' && echo 1)" "system_mcp getConfig returns custom_tools=[]" "$(echo $SVCGET | head -c 300)"

echo "## 3. service GET endpoint"
EP=$(curl -s "${H[@]}" "$BASE/api/v2/$SVC")
echo "  $EP"
check "$(echo "$EP" | grep -q "/mcp/$SVC" && echo 1)" "GET /api/v2/$SVC returns mcp_endpoint" "$EP"

echo "## 4. /rpc bridge (session-authenticated) -> system daemon"
rpc(){ curl -s "${H[@]}" -X POST "$BASE/api/v2/$1/rpc" -d "$2"; }
TL=$(rpc $SVC '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}')
N=$(echo "$TL" | jq_ 'len(d["result"]["tools"])')
echo "  tools/list -> $N tools"
check "$([ "$N" = "17" ] && echo 1)" "system_mcp tools/list returns 17 System API tools" "$(echo $TL | head -c 400)"
check "$(echo "$TL" | grep -q '"list_services"' && echo 1)" "tools include list_services" ""
check "$(echo "$TL" | grep -q '"call_system_api"' && echo 1)" "tools include call_system_api" ""
check "$(echo "$TL" | grep -q '"query_table"\|"list_tables"' && echo || echo 1)" "no data-plane tools leaked into system_mcp" "$(echo $TL | head -c 200)"
LS=$(rpc $SVC '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"list_services","arguments":{"fields":"id,name,type"}}}')
check "$(echo "$LS" | grep -q '\\"name\\": \\"system\\"\|\\"name\\":\\"system\\"' && echo 1)" "tools/call list_services reaches DF System API and lists the 'system' service" "$(echo $LS | head -c 400)"
check "$(echo "$LS" | grep -q '"isError":true' && echo || echo 1)" "list_services not flagged isError" "$(echo $LS | head -c 300)"
RN="mcp_itest_role_$RANDOM"
CR=$(rpc $SVC "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"create_role\",\"arguments\":{\"name\":\"$RN\",\"description\":\"created via System MCP\",\"is_active\":true}}}")
ROLE=$(curl -s "${H[@]}" "$BASE/api/v2/system/role?filter=name%3D$RN&fields=id,name")
check "$(echo "$ROLE" | grep -q "\"name\":\"$RN\"" && echo 1)" "tools/call create_role actually created role '$RN' (write path)" "$(echo $CR | head -c 300) || $ROLE"
ENV=$(rpc $SVC '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"get_environment","arguments":{}}}')
check "$(echo "$ENV" | grep -q '7.7.0' && echo 1)" "get_environment reports platform 7.7.0" "$(echo $ENV | head -c 300)"
GUARD=$(rpc $SVC '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"call_system_api","arguments":{"method":"GET","path":"db/_table/x"}}}')
check "$(echo "$GUARD" | grep -qi 'path rejected' && echo 1)" "call_system_api rejects non system/user path" "$(echo $GUARD | head -c 300)"

echo "## 5. disabled_tools honored"
curl -s -o /dev/null "${H[@]}" -X PATCH "$BASE/api/v2/system/service/$SVCID" -d '{"description":"","config":{"disabled_tools":["delete_service","call_system_api"]}}'
TL2=$(rpc $SVC '{"jsonrpc":"2.0","id":6,"method":"tools/list","params":{}}')
N2=$(echo "$TL2" | jq_ 'len(d["result"]["tools"])')
check "$([ "$N2" = "15" ] && echo 1)" "after disabling 2 tools, tools/list returns 15" "n=$N2 $(echo $TL2 | head -c 200)"
check "$(echo "$TL2" | grep -q '"delete_service"' && echo || echo 1)" "delete_service absent when disabled" ""
curl -s -o /dev/null "${H[@]}" -X PATCH "$BASE/api/v2/system/service/$SVCID" -d '{"description":"","config":{"disabled_tools":[]}}'
TL2b=$(rpc $SVC '{"jsonrpc":"2.0","id":7,"method":"tools/list","params":{}}'); N2b=$(echo "$TL2b" | jq_ 'len(d["result"]["tools"])')
check "$([ "$N2b" = "17" ] && echo 1)" "re-enabling (disabled_tools=[]) restores 17 tools" "n=$N2b"

echo "## 6. OAuth 2.1 flow on /mcp/$SVC (external MCP client path)"
WK=$(curl -s "$BASE/.well-known/oauth-authorization-server/mcp/$SVC")
check "$(echo "$WK" | grep -q 'authorization_endpoint' && echo 1)" "RFC8414 metadata served for $SVC" "$(echo $WK | head -c 200)"
REG=$(curl -s -X POST "$BASE/mcp/$SVC/register" -H 'Content-Type: application/json' -d '{"client_name":"itest","redirect_uris":["http://localhost:1/cb"]}')
RCID=$(echo "$REG" | jq_ 'd.get("client_id","")'); RSEC=$(echo "$REG" | jq_ 'd.get("client_secret","")')
check "$([ -n "$RCID" ] && echo 1)" "dynamic client registration returns client_id" "$(echo $REG | head -c 300)"
CID=$(curl -s "${H[@]}" "$BASE/api/v2/system/service/$SVCID" | jq_ '(d.get("config") or {}).get("oauth_client_id") or ""')
check "$([ ${#CID} -eq 32 ] && echo 1)" "service config now holds the 32-hex oauth_client_id (populated on first DCR, same as type mcp)" "cid=$CID"
VER=$(head -c 48 /dev/urandom | base64 | tr -d '=+/' | head -c 43)
CHAL=$(printf '%s' "$VER" | openssl dgst -sha256 -binary | base64 | tr '+/' '-_' | tr -d '=')
LOGIN=$(curl -s -X POST "$BASE/mcp/$SVC/login" --data-urlencode email=$EMAIL --data-urlencode password=$PASS --data-urlencode client_id=$RCID --data-urlencode redirect_uri=http://localhost:1/cb --data-urlencode code_challenge=$CHAL --data-urlencode code_challenge_method=S256 --data-urlencode state=abc)
CODE=$(echo "$LOGIN" | grep -o 'code=[A-Za-z0-9_.-]*' | head -1 | cut -d= -f2)
check "$([ -n "$CODE" ] && echo 1)" "login issued an authorization code" "$(echo $LOGIN | head -c 300)"
TOK=$(curl -s -X POST "$BASE/mcp/$SVC/token" --data-urlencode grant_type=authorization_code --data-urlencode code=$CODE --data-urlencode redirect_uri=http://localhost:1/cb --data-urlencode client_id=$RCID --data-urlencode client_secret=$RSEC --data-urlencode code_verifier=$VER)
AT=$(echo "$TOK" | jq_ 'd.get("access_token","")')
check "$([ -n "$AT" ] && echo 1)" "token endpoint returned access_token" "$(echo $TOK | head -c 300)"
INIT=$(curl -s -D /tmp/itest_hdr -X POST "$BASE/mcp/$SVC" -H "Authorization: Bearer $AT" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"itest","version":"1"}}}')
SID=$(grep -i '^mcp-session-id:' /tmp/itest_hdr | awk '{print $2}' | tr -d '\r')
check "$(echo "$INIT" | grep -q 'df-system-mcp' && echo 1)" "initialize via bearer reaches df-system-mcp server (serverInfo)" "$(echo $INIT | head -c 300)"
check "$([ -n "$SID" ] && echo 1)" "Mcp-Session-Id propagated back through PHP proxy" "hdr: $(cat /tmp/itest_hdr | tr '\n' ' ' | head -c 300)"
curl -s -o /dev/null -X POST "$BASE/mcp/$SVC" -H "Authorization: Bearer $AT" -H "Mcp-Session-Id: $SID" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d '{"jsonrpc":"2.0","method":"notifications/initialized"}'
TL3=$(curl -s -X POST "$BASE/mcp/$SVC" -H "Authorization: Bearer $AT" -H "Mcp-Session-Id: $SID" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}')
N3=$(echo "$TL3" | sed -n 's/^data: //p' | head -1 | jq_ 'len(d["result"]["tools"])'); [ -z "$N3" ] && N3=$(echo "$TL3" | jq_ 'len(d["result"]["tools"])')
check "$([ "$N3" = "17" ] && echo 1)" "OAuth bearer tools/list returns 17 tools" "n=$N3 $(echo $TL3 | head -c 300)"
CALL=$(curl -s -X POST "$BASE/mcp/$SVC" -H "Authorization: Bearer $AT" -H "Mcp-Session-Id: $SID" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"list_admins","arguments":{}}}')
check "$(echo "$CALL" | grep -q "$EMAIL" && echo 1)" "OAuth bearer tools/call list_admins returns the admin" "$(echo $CALL | head -c 300)"
NOAUTH=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/mcp/$SVC" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
check "$([ "$NOAUTH" = "401" ] && echo 1)" "no bearer -> 401" "http=$NOAUTH"

echo "## 7. regression: regular mcp service still proxies to data daemon"
TLD=$(rpc $DATA_SVC '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}')
ND=$(echo "$TLD" | jq_ 'len(d["result"]["tools"])')
check "$([ -n "$ND" ] && [ "$ND" -gt 0 ] && echo 1)" "mcp (data) service tools/list returns $ND tools" "$(echo $TLD | head -c 300)"
check "$(echo "$TLD" | grep -q '"list_services"' && echo || echo 1)" "data mcp service does not expose System tools" ""

echo "## 8. audit log"
USAGE=$($MYSQL_EXEC "SELECT method, tool_name, status, COUNT(*) FROM dreamfactory.mcp_request_log WHERE service_id=$SVCID GROUP BY 1,2,3" 2>/dev/null)
echo "$USAGE" | sed 's/^/  /'
check "$(echo "$USAGE" | grep -q "tools/call" && echo 1)" "mcp_request_log recorded tools/call rows for $SVC (service_id=$SVCID)" "$USAGE"

echo "## 9. daemon disabled -> clear 503"
$WEB_EXEC bash -c 'cd /opt/dreamfactory && sed -i "s/^MCP_SYSTEM_DAEMON_ENABLED=.*/MCP_SYSTEM_DAEMON_ENABLED=false/" .env && php artisan config:clear -q'
DIS=$(curl -s -X POST "$BASE/mcp/$SVC" -H "Authorization: Bearer $AT" -H "Mcp-Session-Id: $SID" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d '{"jsonrpc":"2.0","id":9,"method":"tools/list","params":{}}')
check "$(echo "$DIS" | grep -qi 'MCP_SYSTEM_DAEMON_ENABLED' && echo 1)" "disabled system daemon yields actionable error naming MCP_SYSTEM_DAEMON_ENABLED" "$(echo $DIS | head -c 300)"
$WEB_EXEC bash -c 'cd /opt/dreamfactory && sed -i "s/^MCP_SYSTEM_DAEMON_ENABLED=.*/MCP_SYSTEM_DAEMON_ENABLED=true/" .env && php artisan config:clear -q'

echo "## cleanup"
curl -s -o /dev/null "${H[@]}" -X DELETE "$BASE/api/v2/system/role?filter=name%3D$RN"
echo "== RESULT: $PASSC passed, $FAILC failed"
[ $FAILC -eq 0 ]

#!/usr/bin/env bash
# Integration test: "Require role access" switch (#63), end-to-end against a running DreamFactory.
#
# Needs: an MCP service ($SVC) exposing a database ($DB_SVC), a non-admin user ($USER_EMAIL) whose only
# role ($ROLE) has grants on $DB_SVC and NONE on $SVC, and the data daemon running.
# Usage:
#   BASE=http://localhost:8081 EMAIL=admin@dreamfactory.com PASS=passwordpassword \
#   SVC=demo_mcp DB_SVC=demo_mysql ROLE=orders_analyst USER_EMAIL=analyst@dreamfactory.com USER_PASS=passwordpassword \
#   WEB_EXEC="docker exec df-development-web-1" bash tests/integration/role-access-itest.sh
BASE=${BASE:-http://127.0.0.1:8770}; EMAIL=${EMAIL:-admin@df770.local}; PASS=${PASS:-Df770Passw0rdLong2026}
SVC=${SVC:-demo_mcp}; DB_SVC=${DB_SVC:-demo_mysql}; ROLE=${ROLE:-orders_analyst}
USER_EMAIL=${USER_EMAIL:-analyst@dreamfactory.com}; USER_PASS=${USER_PASS:-passwordpassword}
WEB_EXEC=${WEB_EXEC:-sudo -n docker exec df770_web_1}
PASSC=0; FAILC=0
jq_(){ python3 -c "import sys,json;d=json.load(sys.stdin);print($1)"; }
check(){ if [ "$1" = "1" ]; then PASSC=$((PASSC+1)); echo "  PASS: $2"; else FAILC=$((FAILC+1)); echo "  FAIL: $2 -- $3"; fi; }
T=$(curl -s -X POST $BASE/api/v2/system/admin/session --data-urlencode email=$EMAIL --data-urlencode password=$PASS | jq_ 'd["session_token"]')
H=(-H "X-DreamFactory-Session-Token: $T" -H "Content-Type: application/json")
SVCID=$(curl -s "${H[@]}" "$BASE/api/v2/system/service?filter=name%3D$SVC&fields=id" | jq_ 'd["resource"][0]["id"]')
ROLEID=$(curl -s "${H[@]}" "$BASE/api/v2/system/role?filter=name%3D$ROLE&fields=id" | jq_ 'd["resource"][0]["id"]')
setflag(){ curl -s -o /dev/null "${H[@]}" -X PATCH "$BASE/api/v2/system/service/$SVCID" -d "{\"config\":{\"require_role_access\":$1}}"; $WEB_EXEC php artisan cache:clear -q >/dev/null 2>&1; }
# Unlink via the role API: a related row sent with role_id=null is removed by df-core (BaseModel hasMany handling).
grant_rm(){ ids=$(curl -s "${H[@]}" "$BASE/api/v2/system/role/$ROLEID?related=role_service_access_by_role_id" | python3 -c "import sys,json;d=json.load(sys.stdin);print(' '.join(str(r['id']) for r in d.get('role_service_access_by_role_id',[]) if r['service_id']==$SVCID))"); for id in $ids; do curl -s -o /dev/null "${H[@]}" -X PATCH "$BASE/api/v2/system/role/$ROLEID" -d "{\"role_service_access_by_role_id\":[{\"id\":$id,\"role_id\":null}]}"; done; $WEB_EXEC php artisan cache:clear -q >/dev/null 2>&1; }
grant_add(){ curl -s -o /dev/null "${H[@]}" -X PATCH "$BASE/api/v2/system/role/$ROLEID" -d "{\"role_service_access_by_role_id\":[{\"service_id\":$SVCID,\"component\":\"*\",\"verb_mask\":1,\"requestor_mask\":3}]}"; $WEB_EXEC php artisan cache:clear -q >/dev/null 2>&1; }
# OAuth login as the non-admin user -> HTTP status of initialize
connect_as_user(){
  local reg rcid rsec ver chal login code tok at
  reg=$(curl -s -X POST "$BASE/mcp/$SVC/register" -H 'Content-Type: application/json' -d '{"client_name":"role-itest","redirect_uris":["http://localhost:1/cb"]}')
  rcid=$(echo "$reg" | jq_ 'd.get("client_id","")'); rsec=$(echo "$reg" | jq_ 'd.get("client_secret","")')
  ver=$(head -c 48 /dev/urandom | base64 | tr -d '=+/' | head -c 43); chal=$(printf '%s' "$ver" | openssl dgst -sha256 -binary | base64 | tr '+/' '-_' | tr -d '=')
  login=$(curl -s -X POST "$BASE/mcp/$SVC/login" --data-urlencode email=$USER_EMAIL --data-urlencode password=$USER_PASS --data-urlencode client_id=$rcid --data-urlencode redirect_uri=http://localhost:1/cb --data-urlencode code_challenge=$chal --data-urlencode code_challenge_method=S256 --data-urlencode state=abc)
  code=$(echo "$login" | grep -o 'code=[A-Za-z0-9_.-]*' | head -1 | cut -d= -f2)
  tok=$(curl -s -X POST "$BASE/mcp/$SVC/token" --data-urlencode grant_type=authorization_code --data-urlencode code=$code --data-urlencode redirect_uri=http://localhost:1/cb --data-urlencode client_id=$rcid --data-urlencode client_secret=$rsec --data-urlencode code_verifier=$ver)
  at=$(echo "$tok" | jq_ 'd.get("access_token","")')
  curl -s -o /tmp/role_itest_body -w '%{http_code}' -X POST "$BASE/mcp/$SVC" -H "Authorization: Bearer $at" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"role-itest","version":"1"}}}'
}
connect_as_admin(){ curl -s -o /dev/null -w '%{http_code}' "${H[@]}" -X POST "$BASE/api/v2/$SVC/rpc" -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'; }

echo "## 0. schema exposes the switch"
SCHEMA=$(curl -s "${H[@]}" "$BASE/api/v2/system/service_type/mcp")
check "$(echo "$SCHEMA" | grep -q '"require_role_access"' && echo 1)" "config_schema has require_role_access" "$(echo $SCHEMA | head -c 200)"
echo "## 1. switch OFF (upgrade default): user with no grant on $SVC still connects"
grant_rm; setflag false
S=$(connect_as_user); check "$([ "$S" = "200" ] && echo 1)" "no grant + switch off -> 200" "http=$S $(head -c 200 /tmp/role_itest_body)"
echo "## 2. switch ON: same user refused with 403 / -32003"
setflag true
S=$(connect_as_user); check "$([ "$S" = "403" ] && echo 1)" "no grant + switch on -> 403" "http=$S"
check "$(grep -q '"code":-32003' /tmp/role_itest_body && echo 1)" "JSON-RPC error code -32003" "$(head -c 200 /tmp/role_itest_body)"
check "$(grep -q "$SVC" /tmp/role_itest_body && echo 1)" "message names the MCP service" "$(head -c 200 /tmp/role_itest_body)"
echo "## 3. admin unaffected"
S=$(connect_as_admin); check "$([ "$S" = "200" ] && echo 1)" "admin rpc bridge -> 200 with switch on" "http=$S"
echo "## 4. grant the role GET on $SVC -> connects again"
grant_add
S=$(connect_as_user); check "$([ "$S" = "200" ] && echo 1)" "grant + switch on -> 200" "http=$S $(head -c 200 /tmp/role_itest_body)"
echo "## 5. who-can-connect endpoint"
ACC=$(curl -s "${H[@]}" "$BASE/_internal/ai/mcp-access?service=$SVC&period=1d")
check "$(echo "$ACC" | python3 -c "import sys,json;d=json.load(sys.stdin);print(1 if d.get('require_role_access') is True else 0)")" "reports the switch state" "$(echo $ACC | head -c 300)"
check "$(echo "$ACC" | python3 -c "import sys,json;d=json.load(sys.stdin);r=[x for x in d['roles'] if x['name']=='$ROLE'];print(1 if r and r[0]['granted'] and r[0]['denied']>=1 and r[0]['requests']>=2 else 0)")" "lists $ROLE as granted, with its denied attempt counted" "$(echo $ACC | head -c 400)"
echo "## 6. removing the grant refuses again (no cached admission)"
grant_rm
S=$(connect_as_user); check "$([ "$S" = "403" ] && echo 1)" "grant removed -> 403" "http=$S"
echo "## cleanup: switch back off (this service predates the feature)"
setflag false
echo "== RESULT: $PASSC passed, $FAILC failed"; [ $FAILC -eq 0 ]

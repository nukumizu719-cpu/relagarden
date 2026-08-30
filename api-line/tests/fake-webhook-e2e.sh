#!/bin/sh
# 本物と同じHTTPで、受信から取り込みまでを通しで確かめる。
#
# 本物のLINEへはつながない。署名は手元で作る。
# 並行して届いた場合に、問い合わせが消えたり増えたりしないかを見る。
#
#   sh api-line/tests/fake-webhook-e2e.sh

set -eu

PORT=${PORT:-8791}
SECRET='e2e-channel-secret-for-local-only'
TOKEN='0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'
BASE="http://127.0.0.1:$PORT"

# 前回の確認用サーバーが残っていると、そちらへつないでしまう。
if curl -s --max-time 2 -o /dev/null "http://127.0.0.1:${PORT:-8791}/inbox" 2>/dev/null; then
  echo "127.0.0.1:${PORT:-8791} が使われています。前回の確認用サーバーを止めてください:"
  echo "  pkill -f 'php -S 127.0.0.1:${PORT:-8791}'"
  exit 1
fi

work=$(mktemp -d)
cleanup() {
  if [ -n "${server_pid:-}" ]; then
    kill "$server_pid" 2>/dev/null || true
    # 終了の知らせを画面へ出さない（失敗と見間違えるため）
    wait "$server_pid" 2>/dev/null || true
  fi
  rm -rf "$work"
}
trap cleanup EXIT

cat > "$work/line-config.php" <<PHP
<?php
return [
    'channel_secret' => '$SECRET',
    'channel_access_token' => '',
    'inbox_token' => '$TOKEN',
    'storage_dir' => '$work/storage',
    // 手元の確認なので、ここだけHTTPを許す。本番の設定では true のまま。
    'require_https' => false,
];
PHP

# 並行して受けられるように、作業プロセスを増やして起動する。
PHP_CLI_SERVER_WORKERS=10 \
RELAGARDEN_LINE_CONFIG="$work/line-config.php" \
RELAGARDEN_LINE_SOURCE="$(pwd)/api-line/src" \
  php -S "127.0.0.1:$PORT" -t api-line/public > "$work/server.log" 2>&1 &
server_pid=$!
sleep 1

sign() { printf '%s' "$1" | openssl dgst -sha256 -hmac "$SECRET" -binary | base64; }

post_hook() {
  body="$1"
  curl -s --max-time 15 -o /dev/null -w '%{http_code}' -X POST "$BASE/webhook" \
    -H "X-Line-Signature: $(sign "$body")" -d "$body"
}

event() {
  printf '{"events":[{"type":"message","webhookEventId":"%s","timestamp":%s,"mode":"active","source":{"type":"user","userId":"%s"},"replyToken":"tok","message":{"id":"%s","type":"text","text":"%s"}}]}' \
    "$1" "$4" "$3" "$2" "$5"
}

inbox_count() {
  curl -s --max-time 15 "$BASE/inbox" -H "Authorization: Bearer $TOKEN" \
    | tr ',' '\n' | grep -c '"eventKey"' || true
}

fail=0
ok() { printf '  ✓ %s\n' "$1"; }
ng() { printf '  ✗ %s\n' "$1"; fail=1; }
expect() { [ "$2" = "$3" ] && ok "$1" || ng "$1（期待=$3 実際=$2）"; }

echo '実HTTPでの通し確認'

expect '署名が無ければ断る' "$(curl -s --max-time 15 -o /dev/null -w '%{http_code}' -X POST "$BASE/webhook" -d '{"events":[]}')" 400
expect '壊れた内容は断る' "$(post_hook '{壊れている')" 400
expect '合言葉なしでは受信箱を読めない' "$(curl -s --max-time 15 -o /dev/null -w '%{http_code}' "$BASE/inbox")" 401
expect 'LINEの検証（イベントなし）は通る' "$(post_hook '{"destination":"U0","events":[]}')" 200

# 同じ配信を10回、同時に送る
same=$(event 'E2E-SAME' 'M-SAME' 'Ue2e0000000000000000000000000001' 1756000000000 '同じ配信を並行で')
# サーバーも同じシェルの子なので、待つのは curl の番号だけにする
# （引数なしの wait だとサーバーの終了まで待ってしまう）。
i=0
pids=''
while [ "$i" -lt 10 ]; do
  post_hook "$same" > /dev/null &
  pids="$pids $!"
  i=$((i + 1))
done
for pid in $pids; do wait "$pid" || true; done
expect '同じ配信を10回同時に送っても1件だけ' "$(inbox_count)" 1

# 同じお客様から、別のメッセージを同時に送る
i=0
pids=''
while [ "$i" -lt 5 ]; do
  post_hook "$(event "E2E-M$i" "M-M$i" 'Ue2e0000000000000000000000000001' "$((1756000100000 + i))" "並行の$i通目")" > /dev/null &
  pids="$pids $!"
  i=$((i + 1))
done
for pid in $pids; do wait "$pid" || true; done
expect '同じお客様の別メッセージは5件とも残る' "$(inbox_count)" 6

# 取り込み済みの印
ids=$(curl -s --max-time 15 "$BASE/inbox" -H "Authorization: Bearer $TOKEN" \
  | grep -o '"id":"[^"]*"' | cut -d'"' -f4 | sed 's/.*/"&"/' | paste -sd, -)
expect '取り込み済みを伝えられる' \
  "$(curl -s --max-time 15 -o /dev/null -w '%{http_code}' -X POST "$BASE/sync" -H "Authorization: Bearer $TOKEN" -d "{\"ids\":[$ids]}")" 200
expect '取り込み済みは次から渡らない' "$(inbox_count)" 0

# 上限
many=$(i=0; printf '{"ids":['; while [ "$i" -lt 201 ]; do [ "$i" -gt 0 ] && printf ','; printf '"id%s"' "$i"; i=$((i+1)); done; printf ']}')
expect '201件の取り込み済みは断る' \
  "$(curl -s --max-time 15 -o /dev/null -w '%{http_code}' -X POST "$BASE/sync" -H "Authorization: Bearer $TOKEN" -d "$many")" 413
expect '掲載の入口は無い' \
  "$(curl -s --max-time 15 -o /dev/null -w '%{http_code}' -X POST "$BASE/publish" -H "Authorization: Bearer $TOKEN" -d '{}')" 404

# 記録に本文・ユーザーID・秘密が出ていないこと
log=$(cat "$work/storage/logs/"*.log 2>/dev/null || true)
for word in '並行' 'Ue2e0000000000000000000000000001' "$SECRET" "$TOKEN" 'E2E-SAME'; do
  case "$log" in
    *"$word"*) ng "記録に「$word」が混ざっている" ;;
    *) : ;;
  esac
done
ok '記録に本文・ユーザーID・秘密が出ていない'

echo
if [ "$fail" -eq 0 ]; then
  echo '結果: 問題なし'
else
  echo '結果: 失敗あり'
  exit 1
fi

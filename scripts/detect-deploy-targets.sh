#!/bin/sh
# 今回の更新で、Xserverのどこまで配置し直すかを決める。
#
# 変更されたファイルの一覧を標準入力から受け取り（1行に1ファイル）、
# 結果を GitHub Actions の出力の形（name=value）で標準出力へ出す。
#
#   git diff --name-only <前> <後> | sh scripts/detect-deploy-targets.sh
#
# 出す値
#   api=true|false   通常API（api/）を配置し直すか
#   line=true|false  公式LINEの受信API（api-line/）と設定を配置し直すか
#
# ホームページ本体（src/ public/ など）は毎回配置するので、ここでは扱わない。
#
# 迷ったら必ず true（配置する）へ倒す。
# 必要な更新を飛ばすより、要らない更新をするほうが安全なため。

set -eu

api=false
line=false

# 1行も受け取らなかった場合は「判定できなかった」とみなす。
received=false

while IFS= read -r path; do
  [ -n "$path" ] || continue
  received=true
  case "$path" in
    # 公式LINEの受信API
    api-line/*)
      line=true
      ;;
    # 通常API
    api/*)
      api=true
      ;;
    # ワークフロー自体が変わったときは、配置の手順が変わった可能性がある。
    # LINEの設定もここで作っているので、両方を置き直す。
    .github/*)
      api=true
      line=true
      ;;
    # ホームページ本体・手順書・確認用スクリプトなど。
    # Xserver上のAPIには影響しないので、配置し直さない。
    *)
      ;;
  esac
done

if [ "$received" = false ]; then
  api=true
  line=true
fi

echo "api=$api"
echo "line=$line"

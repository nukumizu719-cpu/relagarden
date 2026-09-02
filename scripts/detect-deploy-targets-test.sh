#!/bin/sh
# scripts/detect-deploy-targets.sh が、配置する場所を正しく決めるか確かめる。
#
#   sh scripts/detect-deploy-targets-test.sh
#
# 本番へはつながない。文字列を入れて、返ってくる答えを見るだけ。

set -eu

here=$(dirname "$0")
target="$here/detect-deploy-targets.sh"

fail=0
ok() { printf '  ✓ %s\n' "$1"; }
ng() { printf '  ✗ %s（期待 %s / 実際 %s）\n' "$1" "$2" "$3"; fail=1; }

# 使い方: check '説明' '入れるファイル一覧' '期待する答え'
check() {
  got=$(printf '%s' "$2" | sh "$target" | tr '\n' ' ' | sed 's/ *$//')
  if [ "$got" = "$3" ]; then
    ok "$1"
  else
    ng "$1" "$3" "$got"
  fi
}

echo '施工事例・ホームページだけの更新'
check '施工事例を1件追加しただけなら、APIは置き直さない' \
  'src/content/cases/case-20260902-1116.md
public/works/case-20260902-1116-after.jpg
' 'api=false line=false'
check 'ページの見た目を直しただけなら、APIは置き直さない' \
  'src/pages/index.astro
src/styles/global.css
' 'api=false line=false'
check '手順書だけの更新でも、APIは置き直さない' \
  'docs/LINE_INBOX_SETUP.md
' 'api=false line=false'

echo
echo '通常APIの更新'
check 'api/ が変わったら、通常APIだけを置き直す' \
  'api/src/Publisher.php
' 'api=true line=false'
check 'api/public/ が変わっても、通常APIだけ' \
  'api/public/index.php
' 'api=true line=false'

echo
echo '公式LINE受信APIの更新'
check 'api-line/ が変わったら、LINEだけを置き直す' \
  'api-line/src/Inbox.php
' 'api=false line=true'
check 'api-line/public/ が変わっても、LINEだけ' \
  'api-line/public/.htaccess
' 'api=false line=true'

echo
echo '両方が絡む更新'
check 'api/ と api-line/ が両方変わったら、両方置き直す' \
  'api/src/Publisher.php
api-line/src/Inbox.php
' 'api=true line=true'
check 'ホームページとAPIが混ざっていても、APIは置き直す' \
  'src/pages/index.astro
api-line/src/Inbox.php
' 'api=false line=true'

echo
echo '安全側へ倒すところ'
check 'ワークフロー自体が変わったら、両方置き直す（設定の作り方が変わるため）' \
  '.github/workflows/deploy.yml
' 'api=true line=true'
check '変更ファイルが分からないときは、両方置き直す' \
  '' 'api=true line=true'
check '空行しか来なかったときも、両方置き直す' \
  '

' 'api=true line=true'

echo
if [ "$fail" -eq 0 ]; then
  echo '結果: 問題なし'
else
  echo '結果: 失敗あり'
  exit 1
fi

#!/bin/sh
# ホームページ更新の rsync が、公式LINEの受信口を消さないことを確かめる。
#
# 本番へはつながない。手元に本番と同じ形の入れ物を作り、
# deploy.yml と同じ switches で実際に rsync を動かして結果を見る。
#
#   sh scripts/deploy-exclude-test.sh
#
# deploy.yml の switches を変えたら、ここも合わせて変えること。

set -eu

WEB_SWITCHES="-avzr --delete --exclude=api/"
API_SWITCHES="-avzr --delete --exclude=line/"

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

src="$work/dist"
api_src="$work/api-public"
dst="$work/public_html"
mkdir -p "$src/works" "$api_src" "$dst/works" "$dst/api/line" "$dst/uploads"

# ビルド結果（これが正）
echo '<html>あたらしいホームページ</html>' > "$src/index.html"
echo 'keep' > "$src/works/010.jpg"
echo 'new-api' > "$api_src/index.php"

# サーバーにいまあるもの
echo '<html>ふるい</html>' > "$dst/index.html"
echo 'keep' > "$dst/works/010.jpg"
echo 'stale' > "$dst/works/999-old-case.jpg"   # 消えてほしい（古い施工事例画像）
echo 'line' > "$dst/api/line/index.php"        # 消えてはいけない
echo 'line' > "$dst/api/line/.htaccess"        # 消えてはいけない
echo 'legacy' > "$dst/api/old.php"             # 通常API更新で消えてほしい
echo 'photo' > "$dst/uploads/photo.jpg"        # 今は保護対象ではない（記録のため）

# 実際に流す（--dry-run ではなく本当に動かして、結果のファイルを見る）
# shellcheck disable=SC2086
rsync $WEB_SWITCHES "$src/" "$dst/" > /dev/null
# shellcheck disable=SC2086
rsync $API_SWITCHES "$api_src/" "$dst/api/" > /dev/null

fail=0
ok() { printf '  ✓ %s\n' "$1"; }
ng() { printf '  ✗ %s\n' "$1"; fail=1; }

[ -f "$dst/api/line/index.php" ] && ok 'LINEの受信口(index.php)が残る' || ng 'LINEの受信口が消えた'
[ -f "$dst/api/line/.htaccess" ] && ok 'LINEの.htaccessが残る' || ng 'LINEの.htaccessが消えた'
[ -f "$dst/works/999-old-case.jpg" ] && ng '古い施工事例画像が消えていない（掃除が効いていない）' || ok '古い施工事例画像は今までどおり消える'
[ -f "$dst/api/old.php" ] && ng '古い通常APIが消えていない' || ok '古い通常APIは消える'
grep -q new-api "$dst/api/index.php" && ok '通常APIは新しい内容へ入れ替わる' || ng '通常APIが更新されていない'
grep -q あたらしい "$dst/index.html" && ok 'ホームページ本体は新しい内容へ入れ替わる' || ng 'ホームページが更新されていない'

# deploy.yml と食い違っていないか
if grep -q -- '--exclude=api/' .github/workflows/deploy.yml \
  && grep -q -- '--exclude=line/' .github/workflows/deploy.yml; then
  ok 'deploy.yml にWeb用とAPI用の除外が入っている'
else
  ng 'deploy.yml の2段階除外が不足している'
fi

echo
if [ "$fail" -eq 0 ]; then
  echo '結果: 問題なし'
else
  echo '結果: 失敗あり'
  exit 1
fi

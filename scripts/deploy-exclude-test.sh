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

SWITCHES="-avzr --delete --exclude=api/line/"

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

src="$work/dist"
dst="$work/public_html"
mkdir -p "$src/works" "$dst/works" "$dst/api/line" "$dst/api" "$dst/uploads"

# ビルド結果（これが正）
echo '<html>あたらしいホームページ</html>' > "$src/index.html"
echo 'keep' > "$src/works/010.jpg"

# サーバーにいまあるもの
echo '<html>ふるい</html>' > "$dst/index.html"
echo 'keep' > "$dst/works/010.jpg"
echo 'stale' > "$dst/works/999-old-case.jpg"   # 消えてほしい（古い施工事例画像）
echo 'line' > "$dst/api/line/index.php"        # 消えてはいけない
echo 'line' > "$dst/api/line/.htaccess"        # 消えてはいけない
echo 'legacy' > "$dst/api/index.php"           # api/ 全体は守らない → 消えてよい
echo 'photo' > "$dst/uploads/photo.jpg"        # 今は保護対象ではない（記録のため）

# 実際に流す（--dry-run ではなく本当に動かして、結果のファイルを見る）
# shellcheck disable=SC2086
rsync $SWITCHES "$src/" "$dst/" > /dev/null

fail=0
ok() { printf '  ✓ %s\n' "$1"; }
ng() { printf '  ✗ %s\n' "$1"; fail=1; }

[ -f "$dst/api/line/index.php" ] && ok 'LINEの受信口(index.php)が残る' || ng 'LINEの受信口が消えた'
[ -f "$dst/api/line/.htaccess" ] && ok 'LINEの.htaccessが残る' || ng 'LINEの.htaccessが消えた'
[ -f "$dst/works/999-old-case.jpg" ] && ng '古い施工事例画像が消えていない（掃除が効いていない）' || ok '古い施工事例画像は今までどおり消える'
[ -f "$dst/api/index.php" ] && ng 'api/ 全体を守ってしまっている' || ok 'api/ 全体は守っていない（line だけ）'
grep -q あたらしい "$dst/index.html" && ok 'ホームページ本体は新しい内容へ入れ替わる' || ng 'ホームページが更新されていない'

# deploy.yml と食い違っていないか
if grep -q -- '--exclude=api/line/' .github/workflows/deploy.yml; then
  ok 'deploy.yml にも同じ除外が入っている'
else
  ng 'deploy.yml に --exclude=api/line/ が無い'
fi

echo
if [ "$fail" -eq 0 ]; then
  echo '結果: 問題なし'
else
  echo '結果: 失敗あり'
  exit 1
fi

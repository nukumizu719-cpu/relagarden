# 管理画面 セットアップ・バックアップ・復旧手順

Before / After 画像管理を Xserver へ設置するときの手順です。

> **重要**: この手順はまだ**実行していません**。
> Xserver の PHP / MySQL / 非公開領域が確認できてから進めます。

---

## 0. 先に確認すること（未確認）

Xserverサーバーパネルへログインして、次を確認してください。

- [ ] PHPのバージョン（8.1以上が望ましい）
- [ ] PHPセッションが使えるか
- [ ] MySQL / MariaDB を作れるか（データベース数の上限）
- [ ] `public_html` の外に書き込めるか（`/home/xs674757/relagarden.jp/private/` など）
- [ ] SSH接続が有効か（ポート10022）
- [ ] cron が使えるか（バックアップ用）
- [ ] 無料SSLが `relagarden.jp` に適用済みか
- [ ] 自動バックアップの有無と復元方法

**これが確認できるまで、本番の管理者アカウント作成・画像アップロード・公開は行いません。**

---

## 1. ディレクトリ構成

```text
/home/xs674757/relagarden.jp/
├── public_html/              ← 公開領域。rsync --delete の対象
│   ├── (Astroのビルド結果)    ← mainへpushするたび入れ替わる
│   ├── admin/                ← 管理画面（rsync除外で保護）
│   │   ├── images/           ← 谷口さんが開く画面
│   │   └── api/
│   │       └── public-cases.json  ← ホームページが読むファイル
│   └── uploads/              ← 公開用画像（rsync除外で保護）
│
└── private/                  ← ★public_html の外。Webから直接見えない
    ├── originals/            ← 原本画像（EXIF除去前）
    ├── config/
    │   └── db.php            ← DB接続情報。Gitには入れない
    ├── backups/
    └── logs/
```

### なぜ原本を `private/` に置くのか

1. `rsync --delete` は `public_html` だけを対象にするため、`private/` は影響を受けません
2. Webから直接ダウンロードされません
3. 公開用画像を作り直したくなったとき、原本から作り直せます

---

## 2. rsync から守る仕組み（三重）

### ① ワークフローの除外設定（このリポジトリで対応済み）

`.github/workflows/deploy.yml` に次を入れてあります。

```yaml
switches: >-
  -avzr --delete
  --exclude=admin/
  --exclude=uploads/
  --exclude=.well-known/
  --exclude=.htaccess
```

### ② データを public_html の外へ置く

原本・DB接続情報・バックアップは `private/` に置きます。
除外設定を誰かが消してしまっても、原本は残ります。

### ③ 公開前バックアップ

デプロイ前・公開前に `uploads/` とDBをバックアップします（次項）。

> **注意**: ①だけでは不十分です。除外行が消えた状態でmainへpushすると
> `uploads/` が消えます。②があるため原本から復元できます。

---

## 3. バックアップ

### 自動（cron・1日1回）

```bash
# 例: 毎日 3:00
0 3 * * * /home/xs674757/relagarden.jp/private/backup.sh
```

`backup.sh` が行うこと。

1. MySQLを `mysqldump` でダンプする
2. `uploads/` と `originals/` を tar で固める
3. `private/backups/` へ日付つきで保存する
4. 30日より古いものを消す

### 手動（作業前に必ず）

```bash
ssh -p 10022 <ユーザー名>@<ホスト名>
cd /home/xs674757/relagarden.jp
tar czf private/backups/manual-$(date +%Y%m%d-%H%M).tar.gz \
  public_html/uploads private/originals
```

---

## 4. 復旧手順

### 画像が消えたとき

```bash
cd /home/xs674757/relagarden.jp
tar xzf private/backups/<戻したい日付>.tar.gz
```

そのあと管理画面で「公開データを作り直す」を実行し、
`public-cases.json` を再生成します。

### 原本だけ残っている場合

管理画面の再生成機能で、`private/originals/` から
公開用画像（EXIF除去済み）と `public-cases.json` を作り直します。

### ホームページの表示がおかしいとき

Before / After欄はホームページ本体と切り離してあります。
`public-cases.json` が壊れていても、**その欄が表示されないだけ**で
他のページには影響しません。

---

## 5. ロールバック（このPRを取り消したいとき）

このPRはホームページ側の変更だけで、Xserverには何も設置しません。

```bash
git revert <マージコミットのSHA>
git push origin main
```

これで自動デプロイが走り、Before / After欄が消えた状態に戻ります。
既存の施工事例11件・お問い合わせフォーム・その他のページは影響を受けません。

---

## 6. まだ実装していないもの

| 項目 | 状態 |
| --- | --- |
| ホームページのBefore / After表示欄 | ✅ このPRに含む |
| API仕様の定義 | ✅ このPRに含む |
| 管理画面のUIモック（動かない見本） | ✅ このPRに含む |
| rsync除外設定 | ✅ このPRに含む |
| **管理画面のログイン処理** | ❌ 未実装（Xserver確認後） |
| **画像アップロード処理** | ❌ 未実装（Xserver確認後） |
| **DBの作成** | ❌ 未実装（Xserver確認後） |
| **EXIF除去処理** | ❌ 未実装（Xserver確認後） |
| **バックアップスクリプト** | ❌ 未実装（Xserver確認後） |

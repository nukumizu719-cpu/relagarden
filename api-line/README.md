# 公式LINE 受信専用API

公式LINEに届いたお問い合わせを受け取り、iPhoneアプリへ渡すためだけのAPIです。

```text
お客様
  → 公式LINE
LINEプラットフォーム（Webhook）
  → https://relagarden.jp/api/line/webhook   ← このフォルダー
Xserver の private/line-storage（public_htmlの外）
  → https://relagarden.jp/api/line/inbox
iPhoneアプリ「リラガーデン」
```

## このAPIがしないこと

**施工事例のホームページ掲載には一切関わりません。**
掲載は今までどおり iPhoneアプリ → GitHub → GitHub Actions → Xserver の経路です。
このフォルダーには `/publish` `/status` `/unpublish` はありません
（無いことを `api-line/tests/run.php` で毎回確かめています）。

- **返信を送りません。** LINEの自動応答（応答メッセージ）はLINE側の設定のまま動きます。
  このAPIが呼ぶLINEの機能は「表示名の取得」1つだけで、メッセージ送信数は使いません。
- **お客様の本名・電話番号・住所を推測しません。** 空欄のまま渡し、谷口さんが後から入れます。
- AI・外部の有料サービス・ngrokは使いません。PHPだけで動きます。

## 入口

| メソッド | 入口 | 用途 | 合言葉 |
| --- | --- | --- | --- |
| POST | `/api/line/webhook` | LINEからの配信を受ける | 不要（署名で確認） |
| GET | `/api/line/inbox` | まだ取り込んでいない問い合わせを渡す | 必要 |
| POST | `/api/line/sync` | 取り込めたものへ受け取り済みの印を付ける | 必要 |

決めた入口以外、想定しないメソッドはすべて断ります。

## 動くために必要なもの

- PHP 8.1以上（`curl` `json` `mbstring`）
- Composerは不要、データベースも不要

## 設置のしかた（**まだ実施していません**）

⚠️ 本番へ置く操作は、谷口さんの承認を得てから行います。
この時点ではコードとテストだけが用意されている状態です。

### 1. ファイルを置く

```text
/home/xs674757/relagarden.jp/
├── public_html/
│   └── api/
│       └── line/          ← api-line/public/ の中身をここへ
│           ├── index.php
│           └── .htaccess
├── api-line-src/          ← api-line/src/ をここへ（public_html の外）
└── private/
    ├── line-config.php    ← 2で作る（public_html の外）
    └── line-storage/      ← 自動で作られる
```

⚠️ **Web更新のrsyncに注意。** `.github/workflows/deploy.yml` の
`--delete` は `public_html/` の中で、リポジトリに無いものを消します。
`public_html/api/` を除外していないと、次のホームページ更新で
この入口が消えます。設置の前に、除外の追加か、この2つのフォルダーを
リポジトリから配信する形にするかを決めてください。

### 2. 設定ファイルを作る

`api-line/private/line-config.example.php` をコピーして、
`/home/xs674757/relagarden.jp/private/line-config.php` として保存します。

| 項目 | 何を入れるか |
| --- | --- |
| `channel_secret` | LINE Developersの「チャネルシークレット」 |
| `channel_access_token` | 「チャネルアクセストークン（長期）」。空でも動く（表示名が空欄になる） |
| `inbox_token` | iPhoneアプリと共有する合言葉。`openssl rand -hex 24` などで作る |

⚠️ GitHubのPAT・Xserverの管理パスワードは使わないでください。
LINE用の値だけを入れます。

### 3. LINE側の設定（承認後に行う操作）

1. LINE Developers → Messaging APIチャネル → Webhook URL に
   `https://relagarden.jp/api/line/webhook` を入れる
2. 「検証」を押して成功することを確かめる
3. Webhookの利用をオンにする
4. **応答メッセージ（自動応答）はオンのままにする** — 併用できます

## 元に戻す方法

1. LINE Developersで「Webhookの利用」をオフにする（これだけで受信は止まります）
2. `public_html/api/line/` を削除する
3. `api-line-src/` と `private/line-config.php` を削除する

自動応答の設定には触れていないため、1〜3のどれを行っても
今までの自動返信はそのまま動きます。ホームページの掲載経路にも影響しません。

## 安全のためにしていること

| 対策 | 内容 |
| --- | --- |
| なりすましを弾く | `X-Line-Signature` をチャネルシークレットで検証。合わない配信は中身を読まずに捨てる |
| 時間差での推測を防ぐ | 署名も合言葉も `hash_equals` で比べる |
| 二重処理を防ぐ | `webhookEventId` と `messageId` の両方に印を残し、再送を弾く |
| 秘密を公開領域へ置かない | 設定も保存データも `public_html` の外 |
| 記録に個人情報を残さない | LINEのユーザーIDと本文は記録へ書かない。念のため伏せ字も掛ける |
| 総当たりを防ぐ | 受信箱の読み出しに回数制限。Webhookには掛けない（取りこぼしを防ぐため） |
| 取りこぼしを防ぐ | 消すのは「アプリが取り込み済み」のものだけ |
| 内部の事情を返さない | 外へは短い日本語だけ。詳細は記録の側 |

## テスト

```sh
php api-line/tests/run.php
```

本物のLINEへはつながりません。差し替え可能な `FakeLineProfile` と
自前で作った署名で、受信から取り込みまでの流れを確かめられます。

手元のPHPで実際のHTTPを通して確かめることもできます。

```sh
php -S 127.0.0.1:8765 -t api-line/public
```

（`RELAGARDEN_LINE_CONFIG` に確認用の設定ファイルを、
`RELAGARDEN_LINE_SOURCE` に `api-line/src` の場所を渡してください。）

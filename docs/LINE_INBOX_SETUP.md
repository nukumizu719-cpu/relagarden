# 公式LINEの自動登録・30分後受付：本番設定と引き継ぎ

このファイルは、**本番の設定をする人**（Codex・谷口さん）へ渡す手順書です。
コードとテストは終わっています。ここから先は、まだ誰も実施していません。

関わるもの

| どこ | 何 |
| --- | --- |
| このリポジトリ | `api-line/`（LINE受信・受付API）、`.github/workflows/deploy.yml` |
| iPhoneアプリ | `relagarden-iphone-app` の `feature/line-inbox` |
| Xserver | `public_html/api/line/`、`api-line-src/`、`private/line-config.php`、`private/line-storage/` |
| LINE Developers | Messaging APIチャネルの Webhook 設定 |

**掲載（施工事例）の経路には触れていません。** iPhone → GitHub → GitHub Actions →
Xserver のままです。`/publish` `/status` `/unpublish` `/pairing` はこのAPIにありません。

---

## いまの状態

| 段階 | 状態 |
| --- | --- |
| 1. 実装済み | 済み |
| 2. Fakeテスト済み | 済み（自動受付を含む。実LINE送信は未実施） |
| 3. PR監査済み | PR作成済み。レビュー待ち |
| 4. Xserver設置済み | **未実施** |
| 5. 本番Webhook受信済み | **未実施** |
| 6. iPhone実機で自動登録済み | **未実施** |

---

## 設置の手順（承認後に実施）

### ① ファイルを置く

```text
/home/<アカウント>/relagarden.jp/
├── public_html/
│   └── api/
│       └── line/          ← api-line/public/ の中身
│           ├── index.php
│           └── .htaccess
├── api-line-src/          ← api-line/src/ の中身（public_html の外）
├── api-line-bin/          ← api-line/bin/ の中身（public_html の外）
└── private/
    ├── line-config.php    ← ②で作る（public_html の外）
    └── line-storage/      ← 自動で作られる
```

`public_html/api/line/` は、2段階のrsyncから保護します。
ホームページ更新では `api/` 全体を除外し、通常API更新では `line/` だけを
除外します。通常APIは更新・掃除しつつ、LINE受信口だけを残します。
除外が効いているかは手元で確かめられます。

```sh
sh scripts/deploy-exclude-test.sh
```

### ② 設定ファイルを作る

`api-line/private/line-config.example.php` をコピーして
`/home/<アカウント>/relagarden.jp/private/line-config.php` へ。

| 項目 | 入れる値 |
| --- | --- |
| `channel_secret` | LINE Developers のチャネルシークレット |
| `channel_access_token` | チャネルアクセストークン（長期）。空でも動く（表示名が空になる） |
| `inbox_token` | **`openssl rand -hex 32`**（64文字）で作る。iPhoneアプリへ同じ値を入れる |
| `auto_reply_enabled` | 最初は `false`。Cronと実機確認後にだけ `true` |

`inbox_token` は **64文字以上が必須** です。これより短いと起動を断り、
すべての入口が `503`（ただいま準備中です）を返します。
`openssl rand -hex 32` の出力がちょうど64文字なので、そのまま貼ってください。

GitHubのPAT・Xserverの管理パスワードは使わないこと。

### ③ 動作確認（LINEにつなぐ前）

```sh
curl -s -o /dev/null -w '%{http_code}\n' https://relagarden.jp/api/line/inbox
# → 401（合言葉が無い）が返れば設置できています
```

`503` は設定ファイルが読めていない、`404` は置き場所か .htaccess の問題です。

`403` が返る場合は、暗号化されていない通信として扱われています。
このAPIは `require_https` が `true` のとき、HTTPS以外を受け付けません。
**本番の設定は `true` のままにしてください。** `false` にして回避しないこと
（Webhookの本文にはお客様の文章が入るため、平文で流してはいけません）。

`403` のときに確かめるところ:

- そのURLへHTTPSでアクセスしているか（`http://` になっていないか）
- XserverでSSLが有効になっているか
- 前段でSSLを終端している場合、PHPへ `HTTPS` か `X-Forwarded-Proto: https`
  のどちらかが渡っているか（このAPIはその両方を見ます）

### ④ LINE Developers の設定

1. Messaging APIチャネル → Webhook URL に
   `https://relagarden.jp/api/line/webhook`
2. 「検証」を押す → 成功すること
3. 「Webhookの利用」をオン
4. 一律に毎回返す応答メッセージは停止。日程確認などのキーワード応答は残す

### ⑤ Xserver Cron（有効化前に設定）

5分ごとに、公開領域外の実行ファイルを動かします。

```sh
/usr/bin/php /home/<アカウント>/relagarden.jp/api-line-bin/run-auto-reply.php >/dev/null 2>&1
```

GitHub Actionsを使う場合は、Cron確認後にリポジトリ変数
`RELAGARDEN_LINE_AUTOREPLY_ENABLED` を `true` にします。

### ⑥ iPhone側

1. アプリを `feature/line-inbox` の版へ更新
2. 設定 →「公式LINEの受信」→ `inbox_token` と同じ合言葉を入れて保存
3. ホーム →「LINE新着を確認」
4. 公式LINEで実際に返信した後、返信文画面の「送信したので、返信済みにする」を押す

---

## 元に戻す手順

1. `auto_reply_enabled` を `false` にする ← これだけで自動受付が止まる
2. LINE Developers で「Webhookの利用」をオフ ← 受信も止める場合
3. `public_html/api/line/` を削除
4. `api-line-src/` `api-line-bin/` と `private/line-config.php` を削除

掲載経路には影響しません。
アプリに取り込み済みのお客様と履歴は、iPhoneの中に残ります。

---

## Codexにお願いしたい実機確認

**サーバー**

- [ ] `GET /api/line/inbox`（合言葉なし）→ 401
- [ ] LINE Developers の「検証」→ 成功
- [ ] `private/line-config.php` と `private/line-storage/` がブラウザーから見えない
      （`https://relagarden.jp/private/line-config.php` が 403/404）
- [ ] ホームページを1回更新して、`public_html/api/line/` が消えないこと
- [ ] 施工事例11件・トップ・一覧が今までどおり表示されること

**LINE**

- [ ] 自分のスマホから公式LINEへ1通送る → 今までどおり自動返信が返る
- [ ] 同じ内容が二重に登録されない
- [ ] 写真だけ送っても、お客様が増えない（文字だけが対象）

**iPhoneアプリ**

- [ ] 「LINE新着を確認」で取り込める
- [ ] はじめての方が「自動登録・内容確認待ち」で入る
- [ ] 本名・電話番号・住所が空欄になっている
- [ ] アンケートに「自動入力・要確認」が出る
- [ ] 2通目が履歴に足され、お客様が増えない
- [ ] 名前を入れて保存すると「要確認」が外れる
- [ ] 機内モードで押しても、お客様と写真が減らない
- [ ] 更新前に入っていたお客様・施工事例・写真の件数が変わらない（更新前に控えを取る）

---

## 料金上の注意

- Xserver：いまの契約のまま。PHPのみ。データベースを使わない
- LINE：Webhook受信は無料ですが、初回案内と30分後の受付案内は
  Messaging APIの送信通数としてプラン上の通数へ数えられます
- 外部サービス：AI・OpenAI・ngrok・監視サービスのいずれも使わない

## 自動返信が重ならない理由

送信経路は個別の自動受付1か所だけです。同じ相談中の短い案内は1回まで、
既存キーワードと短い挨拶は除外し、通信結果が不明でも同じRetry Keyで再試行します。
谷口さんが「返信済みにする」を押した予約は送信しません。

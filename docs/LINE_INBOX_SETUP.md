# 公式LINEの自動登録：本番設定と引き継ぎ

このファイルは、**本番の設定をする人**（Codex・谷口さん）へ渡す手順書です。
コードとテストは終わっています。ここから先は、まだ誰も実施していません。

関わるもの

| どこ | 何 |
| --- | --- |
| このリポジトリ | `api-line/`（LINE受信専用API）、`.github/workflows/deploy.yml`（受信口を消さない除外） |
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
| 2. Fakeテスト済み | 済み（PHP 34件・Flutter 449件・実際のHTTPでの通し確認） |
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
| `inbox_token` | `openssl rand -hex 24` などで作る。iPhoneアプリへ同じ値を入れる |

GitHubのPAT・Xserverの管理パスワードは使わないこと。

### ③ 動作確認（LINEにつなぐ前）

```sh
curl -s -o /dev/null -w '%{http_code}\n' https://relagarden.jp/api/line/inbox
# → 401（合言葉が無い）が返れば設置できています
```

`503` は設定ファイルが読めていない、`404` は置き場所か .htaccess の問題です。

### ④ LINE Developers の設定

1. Messaging APIチャネル → Webhook URL に
   `https://relagarden.jp/api/line/webhook`
2. 「検証」を押す → 成功すること
3. 「Webhookの利用」をオン
4. **応答メッセージ（自動応答）はオンのまま**。触らない

### ⑤ iPhone側

1. アプリを `feature/line-inbox` の版へ更新
2. 設定 →「公式LINEの受信」→ `inbox_token` と同じ合言葉を入れて保存
3. ホーム →「LINE新着を確認」

---

## 元に戻す手順

1. LINE Developers で「Webhookの利用」をオフ ← これだけで受信が止まる
2. `public_html/api/line/` を削除
3. `api-line-src/` と `private/line-config.php` を削除
4. deploy.yml の `--exclude=api/line/` を戻す（任意。残しても無害）

自動応答の設定には一切触れていないので、1〜4のどれを行っても
今までの自動返信はそのまま動きます。掲載経路にも影響しません。
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

## 追加料金が発生しない理由

- Xserver：いまの契約のまま。PHPのみ。データベースを使わない
- LINE：Webhookの受信は無料。呼ぶのは「プロフィール取得」だけで、
  課金対象のメッセージ送信を一切行わない
- 外部サービス：AI・OpenAI・ngrok・監視サービスのいずれも使わない

## 自動返信と共存できる理由

このAPIは受け取って控えるだけで、LINEの送信APIを呼びません
（`api-line/tests/run.php` が、送信の呼び出しがコードに無いことを毎回確かめます）。
Webhookの有効化は「届いた内容の写しを受け取る」設定で、応答メッセージの設定とは別です。
④の手順でも応答メッセージには触れません。

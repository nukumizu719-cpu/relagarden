# 管理画面 API仕様（Before / After 画像管理）

この文書は、Xserver側に設置する管理画面が満たすべき**取り決め（インターフェース）**です。

ホームページ側（このリポジトリ）は、この仕様どおりのデータが返ってくることを前提に
`src/components/LatestBeforeAfter.astro` で表示します。

> **現状**: Xserverの PHP / MySQL / 非公開領域が未確認のため、
> このリポジトリには**サーバー側の実装は含まれていません**。
> 含まれているのは「表示側」と「この仕様書」と「UIの静的モック」だけです。

---

## 1. 全体の流れ

```text
谷口さんのiPhone / Windows
  → https://relagarden.jp/admin/images/  （管理画面・ログイン必須）
  → Before / After を選んで下書き保存
  → プレビューで確認
  → 「公開」を押す
  → サーバーが public-cases.json を作り直す
  → ホームページの「施工前・施工後」欄が、次に開いた人から表示する
```

**ホームページの再ビルドもGitへのcommitも発生しません。**

---

## 2. 公開データの取得API（ホームページが読む唯一のAPI）

### エンドポイント

```
GET https://relagarden.jp/admin/api/public-cases.json
```

- 認証**不要**（公開情報のみを返すため）
- 同一オリジンなのでCORS設定は不要
- `Content-Type: application/json; charset=utf-8`
- 静的JSONファイルとして書き出してよい（PHPで動的生成しなくてよい）

### レスポンス

```json
{
  "generatedAt": "2026-08-24T21:00:00+09:00",
  "items": [
    {
      "id": "9f2c1b7a",
      "title": "雑草だらけの庭を人工芝へ",
      "area": "岡崎市",
      "work": "人工芝施工",
      "sizeSqm": 30,
      "comment": "下地から転圧し、10年後も凹凸が出ない施工にしました。",
      "beforeUrl": "/uploads/9f2c1b7a-before.webp",
      "afterUrl": "/uploads/9f2c1b7a-after.webp",
      "publishedAt": "2026-08-24"
    }
  ]
}
```

### 項目

| 項目 | 必須 | 型 | 説明 |
| --- | --- | --- | --- |
| `id` | 任意 | string | 内部ID。推測されにくいランダム値にする |
| `title` | **必須** | string | 見出し。**空だとホームページ側が非表示にする** |
| `area` | 任意 | string | 市区町村まで。**番地・住所は入れない** |
| `work` | 任意 | string | 施工内容 |
| `sizeSqm` | 任意 | number \| string | 面積（㎡は付けない。表示側で付ける） |
| `comment` | 任意 | string | 一言コメント |
| `beforeUrl` | **必須** | string | 施工前画像。**`/` で始まる同一オリジンの相対パスのみ** |
| `afterUrl` | **必須** | string | 施工後画像。同上 |
| `publishedAt` | 任意 | string | 公開日（`YYYY-MM-DD`） |

### ホームページ側が自動的に行う防御

`src/components/LatestBeforeAfter.astro` は、次を**表示せずに捨てます**。

- `beforeUrl` / `afterUrl` が `/` で始まらないもの（`https://外部サイト/...` を含む）
- `//` で始まるもの（プロトコル相対URL）
- `title` が空のもの
- `items` が配列でない、JSONが壊れている、通信が6秒を超えた場合

**取得に失敗した場合は、この欄だけが表示されません。** ホームページの他の部分には影響しません。

### 公開データに入れてはいけないもの

- お客様の氏名、電話番号、番地までの住所、LINE表示名
- 原本画像のパス
- 下書き（未公開）のデータ
- 管理画面の内部ID以外の識別子

---

## 3. 管理画面側のAPI（サーバー内部。ホームページからは呼ばない）

すべて**ログイン必須**、**CSRFトークン必須**です。

| 用途 | メソッド | パス |
| --- | --- | --- |
| ログイン | POST | `/admin/login` |
| ログアウト | POST | `/admin/logout` |
| 下書き一覧 | GET | `/admin/api/drafts` |
| 下書き作成・更新 | POST | `/admin/api/drafts` |
| 画像アップロード | POST | `/admin/api/upload` |
| プレビュー | GET | `/admin/images/preview?id=...` |
| 公開 | POST | `/admin/api/publish` |
| 非公開に戻す | POST | `/admin/api/unpublish` |
| 削除 | POST | `/admin/api/delete` |

### 公開時にサーバーが行うこと

1. 掲載許可（`consent`）が `true` であることを確認する。**falseなら公開を拒否する**
2. Before / After・タイトルが揃っていることを確認する
3. 原本からEXIF（位置情報を含む）を除去した公開用画像を作る
4. 公開用画像だけを `public_html/uploads/` へ置く
5. `public-cases.json` を作り直す
6. 操作履歴（誰が・いつ・何を公開したか）を残す

---

## 4. アップロードの検証（サーバー側で必ず行う）

- 受け付ける形式: JPG / JPEG / PNG / HEIC
- 拡張子だけで判断せず、**ファイルの中身**を検証する（PHPなら `finfo` / `getimagesize`）
- サイズ上限を設ける（例: 1ファイル20MBまで）
- 保存名は**アップロード元のファイル名を使わず**ランダムにする
- 原本は `public_html` の**外**へ保存する
- 公開用画像だけを `public_html/uploads/` へ置く
- Before / After の対応関係をDBに保存する

---

## 5. 表示側の設定を変えたいとき

`src/config/beforeAfter.ts` を編集してください。

| 定数 | 既定値 | 意味 |
| --- | --- | --- |
| `BEFORE_AFTER_FEED_URL` | `/admin/api/public-cases.json` | 読み込み先 |
| `ADMIN_IMAGES_URL` | `/admin/images/` | 管理画面のURL（iPhoneアプリからも開く） |
| `BEFORE_AFTER_MAX_ITEMS` | `6` | トップページに出す最大件数 |
| `BEFORE_AFTER_TIMEOUT_MS` | `6000` | この時間を超えたら欄ごと非表示 |

変更後は `npm run build` が必要です（表示設定はビルド時に埋め込まれるため）。
**画像やデータの追加にはビルドは不要です。**

---

## 6. 既存の施工事例との違い

| | 既存の施工事例 | この管理画面 |
| --- | --- | --- |
| 置き場所 | Gitリポジトリ `src/content/cases/` | Xserver（DB＋画像） |
| 件数 | 11件 | 谷口さんが随時追加 |
| 追加方法 | `npm run case:add` → commit → 自動デプロイ | 管理画面から登録 → 公開ボタン |
| サイト再ビルド | 必要 | **不要** |
| 表示場所 | 「施工事例」欄・`/cases/` | 「施工前・施工後」欄 |

**既存11件は変更していません。** 両方が並んで表示されます。

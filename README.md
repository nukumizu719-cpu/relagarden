サイト構成
_
_includes/
  ├── header.html
  ├── footer.html
  ├── hero.html
  ├── top-problems.html     ← 困りごと
  ├── top-usp.html          ← 訴求ポイント（選ばれる理由）
  ├── top-lineup.html       ← 商品ラインナップ
  ├── top-price.html        ← 料金表
  └── top-flow.html         ← 施工の流れ


#index.mdに入れる
{% include problems.html %}
{% include usp.html %}
{% include lineup.html %}
{% include price.html %}
{% include flow.html %}

🌱 トップページ（index.md）に入れる順番（最適構成）
あなたのサービスに最適な順番はこれです：

hero（メインビジュアル）

困りごと（top-problems）

解決できる理由（top-usp）

商品ラインナップ（top-lineup）

料金表（top-price）

施工の流れ（top-flow）

施工事例への導線

お問い合わせへの導線

この順番は、ユーザー心理に沿った「売れる構成」です。

サイト構造　2026/2/1
relagarden-site/
│
├── index.md                ← トップページ（営業ブロック集約）
│
├── contact.md              ← お問い合わせページ（新規作成）
│
├── _config.yml             ← サイト設定（URL, title, plugins など）
├── favicon.png
├── README.md
├── 404.html
├── about.markdown
├── Gemfile
├── Gemfile.lock
│
├── assets/
│   ├── css/
│   │   └── relagarden.css  ← 全体のデザイン（後で整える）
│   └── images/
│       ├── case-a-800.jpg
│       ├── works/
│       │   ├── default-thumb.jpg
│       │   └── house-a-thumb.jpg
│       └── cases/
│           └── 001.jpg
│
├── _includes/
│   ├── header.html         ← 全ページ共通ヘッダー
│   ├── footer.html         ← 全ページ共通フッター
│   ├── hero.html           ← トップ専用ヒーロー（default.html から条件表示）
│   ├── top-problems.html   ← トップページ営業ブロック①
│   ├── top-usp.html        ← 営業ブロック②
│   ├── top-lineup.html     ← 営業ブロック③
│   ├── top-price.html      ← 営業ブロック④
│   └── top-flow.html       ← 営業ブロック⑤
│
├── _layouts/
│   ├── default.html        ← 全ページ共通レイアウト
│   ├── case.html           ← 施工事例ページ用レイアウト
│   └── work.html           ← 施工実績一覧などで使用
│
├── _cases/
│   ├── 001.md              ← 施工事例（個別ページ）
│   └── index.html          ← 施工事例一覧ページ
│
└── _posts/
    └── 2026-01-30-welcome-to-jekyll.markdown

サイトとしての動き
/
├── index.md
│   ├── hero（default.html が自動表示）
│   ├── top-problems
│   ├── top-usp
│   ├── top-lineup
│   ├── top-price
│   ├── top-flow
│   ├── 施工事例一覧（トップ用）
│   └── お問い合わせ導線
│
├── /contact/
│   └── contact.md（フォーム）
│
└── /cases/
    ├── index.html（施工事例一覧）
    └── 001.html（個別事例）
#サーバー起動
bundle exec jekyll serve --livereload
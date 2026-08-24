/**
 * 管理画面から公開された Before / After データの取得設定。
 *
 * ホームページ本体は静的ビルド（Astro）だが、この欄だけは
 * ブラウザーが表示時に読み込む。そのため、施工写真を追加するたびに
 * GitHubへcommitしたりサイトを再ビルドしたりする必要がない。
 *
 * 実データはXserver側の管理画面が生成する。GitHubには置かない。
 */

/** 公開済みBefore / After一覧のURL（同一オリジン。CORS設定不要） */
export const BEFORE_AFTER_FEED_URL = '/admin/api/public-cases.json';

/** 谷口さんが画像を登録する管理画面のURL（iPhoneアプリからも開く） */
export const ADMIN_IMAGES_URL = '/admin/images/';

/** トップページに表示する最大件数 */
export const BEFORE_AFTER_MAX_ITEMS = 6;

/** 読み込みのタイムアウト（ミリ秒）。超えた場合は欄ごと非表示にする */
export const BEFORE_AFTER_TIMEOUT_MS = 6000;

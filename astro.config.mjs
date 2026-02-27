// @ts-check
import { defineConfig } from 'astro/config';
import tailwind from '@astrojs/tailwind';
import sitemap from '@astrojs/sitemap';

/**
 * リラガーデン SEO & パフォーマンス最適化設定
 */
export default defineConfig({
  // 1. サイトの正典(Canonical)URL設定
  // 構造化データの生成やsitemap生成に必須の設定です
  site: 'https://relagarden.jp',
  
  // 2. URLの正規化
  // 末尾スラッシュありに統一することで重複コンテンツを防止します
  trailingSlash: 'always',

  // 3. パフォーマンス最適化
  compressHTML: true,
  prefetch: {
    prefetchAll: true,
    defaultStrategy: 'hover'
  },

  // 4. 外部機能の統合
  integrations: [
    tailwind(), 
    sitemap()
  ],

  // 5. ビルド設定
  build: {
    // ディレクトリ形式（example.com/page/index.html）で出力
    format: 'directory',
    inlineStylesheets: 'auto'
  }
});
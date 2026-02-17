// @ts-check
import { defineConfig } from 'astro/config';
import tailwindcss from '@tailwindcss/vite';
import sitemap from '@astrojs/sitemap';

// https://astro.build/config
export default defineConfig({
  // ↓ ここを追加！サイトマップ生成に必須です
  site: 'https://relagarden.jp',

  vite: {
    plugins: [tailwindcss()]
  },

  integrations: [sitemap()]
});
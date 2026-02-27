/** @type {import('tailwindcss').Config} */
export default {
  content: ['./src/**/*.{astro,html,js,jsx,md,mdx,svelte,ts,tsx,vue}'],
  theme: {
    extend: {
      fontFamily: {
        // Zen Maru Gothicを'zen'という名前でTailwindに登録
        zen: ['"Zen Maru Gothic"', 'sans-serif'],
      },
      colors: {
        // ブランドカラーを定義しておくと管理が楽です
        green: {
          950: '#052e16',
        },
      },
    },
  },
  plugins: [],
}
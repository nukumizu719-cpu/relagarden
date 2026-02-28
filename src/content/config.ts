import { defineCollection, z } from 'astro:content';

// 施工事例（Cases）の定義
const cases = defineCollection({
  type: 'content',
  // imageヘルパーを使用して画像最適化を有効化
  schema: ({ image }) => z.object({
    title: z.string(),
    description: z.string(),
    pubDate: z.coerce.date(), // 文字列で入力されても日付オブジェクトに変換
    
    // 【重要】z.string() から image() に変更
    // これにより src/assets 内の画像を自動リサイズ・AVIF変換できるようになります
    image: image(), 
    
    // Before画像も最適化対象にする（任意設定）
    beforeImage: image().optional(),
    
    area: z.string(),
    // 費用目安（例: "約15万円"）
    cost: z.string().optional(),
    // 工期（例: "2日間"）
    period: z.string().optional(),
    // タグ（例: ["雑草対策", "ドッグラン"]）
    tags: z.array(z.string()).default([]),
    
    // 施工前の悩み（青系ポストイット用）
    beforeConcerns: z.string().optional(),
    // 施工完了：お客様の声（黄色系ポストイット用）
    customerVoice: z.string().optional(),
    // 施工のポイント（カード形式で表示するリスト）
    constructionPoints: z.array(z.string()).default([]),
  }),
});

// ブログ記事（Blog）の定義
const blog = defineCollection({
  type: 'content',
  schema: ({ image }) => z.object({
    title: z.string(),
    description: z.string(),
    pubDate: z.coerce.date(),
    updatedDate: z.coerce.date().optional(),
    
    // ブログのアイキャッチも最適化対象にする
    heroImage: image().optional(),
    
    category: z.string().default('お知らせ'),
    tags: z.array(z.string()).default([]),
  }),
});

export const collections = {
  'cases': cases,
  'blog': blog,
};
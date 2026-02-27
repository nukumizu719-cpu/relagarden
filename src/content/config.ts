import { defineCollection, z } from 'astro:content';

// 施工事例（Cases）の定義
const cases = defineCollection({
  type: 'content',
  schema: z.object({
    title: z.string(),
    description: z.string(),
    pubDate: z.date(),
    // メイン画像（After画像として使用）
    image: z.string(),
    // Before画像（任意設定）
    beforeImage: z.string().optional(),
    area: z.string(),
    // 費用目安（例: "約15万円"）
    cost: z.string().optional(),
    // 工期（例: "2日間"）
    period: z.string().optional(),
    // タグ（例: ["雑草対策", "ドッグラン"]）
    tags: z.array(z.string()).default([]),
    
    // 【追加】施工前の悩み（青系ポストイット用）
    beforeConcerns: z.string().optional(),
    // 【追加】施工完了：お客様の声（黄色系ポストイット用）
    customerVoice: z.string().optional(),
    // 【追加】施工のポイント（カード形式で表示するリスト）
    constructionPoints: z.array(z.string()).default([]),
  }),
});

// ブログ記事（Blog）の定義
const blog = defineCollection({
  type: 'content',
  schema: z.object({
    title: z.string(),
    description: z.string(),
    pubDate: z.date(),
    updatedDate: z.date().optional(),
    heroImage: z.string().optional(),
    category: z.string().default('お知らせ'),
    tags: z.array(z.string()).default([]),
  }),
});

export const collections = {
  'cases': cases,
  'blog': blog,
};
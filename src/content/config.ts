import { defineCollection, z } from 'astro:content';

/**
 * 施工事例（Cases）：現場実績を管理
 */
const cases = defineCollection({
  type: 'content',
  schema: ({ image }) => z.object({
    title: z.string(),
    description: z.string(),
    pubDate: z.coerce.date(),
    image: image(), // src/assets内の画像を最適化
    beforeImage: image().optional(),
    area: z.string(),
    cost: z.string().optional(),
    period: z.string().optional(),
    tags: z.array(z.string()).default([]),
    beforeConcerns: z.string().optional(),
    customerVoice: z.string().optional(),
    constructionPoints: z.array(z.string()).default([]),
  }),
});

/**
 * ニュース（News）：お知らせ、ブログ、イベントを統合
 */
const news = defineCollection({
  type: 'content',
  schema: ({ image }) => z.object({
    title: z.string(),
    description: z.string(),
    pubDate: z.coerce.date(),
    updatedDate: z.coerce.date().optional(),
    heroImage: image().optional(),
    category: z.enum(['お知らせ', 'イベント', 'ブログ']).default('お知らせ'),
    tags: z.array(z.string()).default([]),
  }),
});

export const collections = {
  'cases': cases,
  'news': news,
};
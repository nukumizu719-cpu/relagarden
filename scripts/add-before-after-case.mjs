import { copyFile, mkdir, stat, writeFile } from 'node:fs/promises';
import { extname, resolve } from 'node:path';

const pairs = process.argv.slice(2);
const options = new Map();
for (let index = 0; index < pairs.length; index += 2) {
  const key = pairs[index];
  const value = pairs[index + 1];
  if (!key?.startsWith('--') || value == null) {
    fail('引数は --項目 "値" の組で指定してください。');
  }
  options.set(key.slice(2), value);
}

const required = ['slug', 'title', 'description', 'date', 'area', 'before', 'after'];
for (const key of required) {
  if (!options.get(key)?.trim()) fail(`--${key} は必須です。`);
}

const slug = options.get('slug').trim();
if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) {
  fail('--slug は半角小文字・数字・ハイフンだけで指定してください。');
}
const date = options.get('date').trim();
if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || Number.isNaN(Date.parse(`${date}T00:00:00Z`))) {
  fail('--date は YYYY-MM-DD 形式で指定してください。');
}

const root = resolve(import.meta.dirname, '..');
const casesDir = resolve(root, 'src/content/cases');
const worksDir = resolve(root, 'src/assets/works');
const casePath = resolve(casesDir, `${slug}.md`);
const beforeSource = resolve(options.get('before'));
const afterSource = resolve(options.get('after'));
const beforeExt = checkedExtension(beforeSource);
const afterExt = checkedExtension(afterSource);
const beforeName = `${slug}-before${beforeExt}`;
const afterName = `${slug}-after${afterExt}`;
const beforeTarget = resolve(worksDir, beforeName);
const afterTarget = resolve(worksDir, afterName);

await requireRegularFile(beforeSource, 'Before画像');
await requireRegularFile(afterSource, 'After画像');
for (const target of [casePath, beforeTarget, afterTarget]) {
  if (await exists(target)) fail(`既存ファイルを上書きしないため中止しました: ${target}`);
}

const tags = (options.get('tags') ?? '')
  .split(',')
  .map((tag) => tag.trim())
  .filter(Boolean);
const points = (options.get('points') ?? '')
  .split('|')
  .map((point) => point.trim())
  .filter(Boolean);
const q = (value = '') => JSON.stringify(value.trim());
const frontmatter = [
  '---',
  `title: ${q(options.get('title'))}`,
  `description: ${q(options.get('description'))}`,
  `pubDate: ${date}`,
  `image: ${q(`../../assets/works/${afterName}`)}`,
  `beforeImage: ${q(`../../assets/works/${beforeName}`)}`,
  `area: ${q(options.get('area'))}`,
  ...(options.get('cost') ? [`cost: ${q(options.get('cost'))}`] : []),
  ...(options.get('period') ? [`period: ${q(options.get('period'))}`] : []),
  `tags: [${tags.map(q).join(', ')}]`,
  ...(options.get('concerns') ? [`beforeConcerns: ${q(options.get('concerns'))}`] : []),
  ...(options.get('voice') ? [`customerVoice: ${q(options.get('voice'))}`] : []),
  `constructionPoints: [${points.map(q).join(', ')}]`,
  '---',
  '',
  '## 施工内容',
  '',
  options.get('body')?.trim() || '施工内容を確認して追記してください。',
  '',
];

await mkdir(worksDir, { recursive: true });
await copyFile(beforeSource, beforeTarget);
await copyFile(afterSource, afterTarget);
try {
  await writeFile(casePath, frontmatter.join('\n'), { flag: 'wx' });
} catch (error) {
  // The targets were checked before copying. A concurrent write is extremely
  // unlikely; surface it and leave the two uniquely named images for recovery.
  fail(`記事ファイルを作成できませんでした: ${error.message}`);
}

console.log('Before / After施工事例をローカルに追加しました。');
console.log(`記事: ${casePath}`);
console.log(`Before: ${beforeTarget}`);
console.log(`After: ${afterTarget}`);
console.log('公開前に npm run build と画面確認を行ってください。');

function checkedExtension(path) {
  const extension = extname(path).toLowerCase();
  if (!['.jpg', '.jpeg', '.png', '.webp'].includes(extension)) {
    fail(`画像形式は jpg / jpeg / png / webp のいずれかにしてください: ${path}`);
  }
  return extension === '.jpeg' ? '.jpg' : extension;
}

async function requireRegularFile(path, label) {
  try {
    const info = await stat(path);
    if (!info.isFile()) fail(`${label}がファイルではありません: ${path}`);
  } catch {
    fail(`${label}が見つかりません: ${path}`);
  }
}

async function exists(path) {
  try {
    await stat(path);
    return true;
  } catch {
    return false;
  }
}

function fail(message) {
  console.error(`エラー: ${message}`);
  process.exit(1);
}

import { readdir, readFile } from 'node:fs/promises';
import { resolve, relative, sep } from 'node:path';
import { parse } from 'yaml';

const root = resolve('content');
const publicAssets = resolve('public/content-assets');
const languages = ['en', 'fr'];
const allowedStatus = new Set(['draft', 'published']);

async function markdownFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const nested = await Promise.all(entries.map(entry => {
    const path = resolve(directory, entry.name);
    return entry.isDirectory() ? markdownFiles(path) : entry.name.endsWith('.md') ? [path] : [];
  }));
  return nested.flat();
}

function documentParts(source, file) {
  const match = source.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n([\s\S]*)$/);
  if (!match) throw new Error(`${file}: missing YAML front matter`);
  const metadata = parse(match[1]);
  if (!metadata || typeof metadata !== 'object') throw new Error(`${file}: invalid front matter`);
  return { metadata, body: match[2].trim() };
}

const filesByLanguage = new Map();
const statusesByLanguage = new Map();
for (const language of languages) {
  const directory = resolve(root, language);
  const files = await markdownFiles(directory);
  const names = new Set(files.map(file => relative(directory, file).split(sep).join('/')));
  filesByLanguage.set(language, names);
  const statuses = new Map();
  statusesByLanguage.set(language, statuses);

  for (const file of files) {
    const name = relative(directory, file).split(sep).join('/');
    const source = await readFile(file, 'utf8');
    const { metadata, body } = documentParts(source, `${language}/${name}`);
    for (const field of ['title', 'description', 'status']) {
      if (typeof metadata[field] !== 'string' || !metadata[field].trim()) throw new Error(`${language}/${name}: ${field} is required`);
    }
    if (!allowedStatus.has(metadata.status)) throw new Error(`${language}/${name}: status must be draft or published`);
    statuses.set(name, metadata.status);
    if (!body) throw new Error(`${language}/${name}: content is empty`);
    if (/<\/?[A-Za-z][^>]*>/.test(body)) throw new Error(`${language}/${name}: raw HTML is not allowed`);
    if (/\]\(\s*(?:javascript|data|vbscript):/i.test(body)) throw new Error(`${language}/${name}: unsafe link protocol`);
    for (const image of body.matchAll(/!\[([^\]]*)\]\(([^\s)]+)(?:\s+"[^"]*")?\)/g)) {
      if (!image[1].trim()) throw new Error(`${language}/${name}: images require alternative text`);
      if (!image[2].startsWith('/content-assets/')) throw new Error(`${language}/${name}: images must use /content-assets/`);
      const asset = resolve(publicAssets, image[2].slice('/content-assets/'.length).split(/[?#]/)[0]);
      if (!asset.startsWith(`${publicAssets}${sep}`)) throw new Error(`${language}/${name}: invalid image path`);
      try { await readFile(asset); } catch { throw new Error(`${language}/${name}: image does not exist: ${image[2]}`); }
    }
    const topHeadings = body.match(/^#\s+/gm)?.length ?? 0;
    if (name.startsWith('pages/') && topHeadings !== 1) throw new Error(`${language}/${name}: pages require exactly one level-one heading`);
    if (name.startsWith('sections/') && topHeadings !== 0) throw new Error(`${language}/${name}: sections cannot contain a level-one heading`);
  }
}

const [english, french] = languages.map(language => filesByLanguage.get(language));
for (const name of english) if (!french.has(name)) throw new Error(`fr/${name}: translated counterpart is missing`);
for (const name of french) if (!english.has(name)) throw new Error(`en/${name}: translated counterpart is missing`);
for (const name of english) {
  if (statusesByLanguage.get('en').get(name) !== statusesByLanguage.get('fr').get(name)) throw new Error(`${name}: English and French publication status must match`);
}
console.log(`Validated ${english.size} bilingual content entries.`);

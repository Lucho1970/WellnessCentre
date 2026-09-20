import { parse } from 'yaml';

export type ContentMetadata = {
  title: string;
  description: string;
  status: 'draft' | 'published';
};

export type ContentDocument = {
  metadata: ContentMetadata;
  body: string;
};

const sources = import.meta.glob('../../content/{en,fr}/**/*.md', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>;

const documents = new Map<string, ContentDocument>();
for (const [filename, source] of Object.entries(sources)) {
  const key = filename.match(/\/content\/(en|fr)\/(.+)\.md$/);
  const parts = source.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n([\s\S]*)$/);
  if (!key || !parts) continue;
  const metadata = parse(parts[1]) as ContentMetadata;
  documents.set(`${key[1]}/${key[2]}`, { metadata, body: parts[2].trim() });
}

export function contentLanguage(language?: string) {
  return language?.toLowerCase().startsWith('fr') ? 'fr' : 'en';
}

export function localizedContent(language: string | undefined, key: string) {
  const document = documents.get(`${contentLanguage(language)}/${key}`);
  return document?.metadata.status === 'published' ? document : null;
}

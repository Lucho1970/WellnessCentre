import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import i18next from 'i18next';
import ts from 'typescript';

async function loadResources(language) {
  const source = await readFile(resolve(`src/i18n/${language}.ts`), 'utf8');
  const { outputText } = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 },
    fileName: `${language}.ts`,
  });
  const moduleUrl = `data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`;
  return (await import(moduleUrl)).default;
}

const resources = Object.fromEntries(await Promise.all(['en', 'fr'].map(async language =>
  [language, { translation: await loadResources(language) }])));
const englishKeys = Object.keys(resources.en.translation).sort();
const frenchKeys = Object.keys(resources.fr.translation).sort();
const errors = [];
for (const key of englishKeys) if (!Object.hasOwn(resources.fr.translation, key)) errors.push(`fr: missing ${JSON.stringify(key)}`);
for (const key of frenchKeys) if (!Object.hasOwn(resources.en.translation, key)) errors.push(`en: missing ${JSON.stringify(key)}`);

for (const language of ['en', 'fr']) {
  const instance = i18next.createInstance();
  await instance.init({ resources, lng: language, fallbackLng: false, nsSeparator: false,
    keySeparator: false, interpolation: { escapeValue: false }, returnNull: false });
  for (const [key, expected] of Object.entries(resources[language].translation)) {
    if (typeof expected !== 'string' || !expected.trim()) {
      errors.push(`${language}: ${JSON.stringify(key)} has no translation`);
      continue;
    }
    const actual = instance.t(key);
    if (actual !== expected) errors.push(`${language}: ${JSON.stringify(key)} resolves to ${JSON.stringify(actual)}, expected ${JSON.stringify(expected)}`);
  }
}

if (errors.length) throw new Error(`Translation validation failed:\n${errors.join('\n')}`);
console.log(`Validated ${englishKeys.length} English and French UI translations.`);

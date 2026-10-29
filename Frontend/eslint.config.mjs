import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import reactHooks from 'eslint-plugin-react-hooks';

export default tseslint.config(
  {
    ignores: ['dist/**', 'node_modules/**', 'node_modules.pnpm-interrupted/**', 'test-results/**', 'playwright-report/**', 'vite.config.js', 'vite.config.d.ts', 'vite.portal.config.js', 'vite.portal.config.d.ts'],
  },
  { linterOptions: { noInlineConfig: true, reportUnusedDisableDirectives: 'error' } },
  {
    files: ['**/*.{js,mjs,ts,tsx}'],
    extends: [js.configs.recommended, ...tseslint.configs.recommended],
  },
  { files: ['src/**/*.{ts,tsx}'], languageOptions: { globals: globals.browser } },
  { files: ['scripts/**/*.mjs', '*.mjs', '*.config.ts'], languageOptions: { globals: globals.node } },
  { files: ['tests/**/*.{ts,mjs}'], languageOptions: { globals: { ...globals.browser, ...globals.node } } },
  {
    files: ['src/**/*.{ts,tsx}'],
    plugins: { 'react-hooks': reactHooks },
    rules: { 'react-hooks/rules-of-hooks': 'error' },
  },
);

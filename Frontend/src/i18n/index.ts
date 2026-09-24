import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import en from './en';
import fr from './fr';

export const supportedLanguages = ['en', 'fr'] as const;
export type SupportedLanguage = typeof supportedLanguages[number];
const storageKey = 'wellness.language';

function initialLanguage(): SupportedLanguage {
  const requested = new URLSearchParams(window.location.search).get('lang')?.toLowerCase().split('-')[0];
  if (supportedLanguages.includes(requested as SupportedLanguage)) {
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete('lang');
    window.history.replaceState(window.history.state, '', `${cleanUrl.pathname}${cleanUrl.search}${cleanUrl.hash}`);
    return requested as SupportedLanguage;
  }
  try {
    const saved = localStorage.getItem(storageKey)?.toLowerCase().split('-')[0];
    if (supportedLanguages.includes(saved as SupportedLanguage)) return saved as SupportedLanguage;
  } catch { /* Browser storage can be unavailable. */ }
  return navigator.language.toLowerCase().startsWith('fr') ? 'fr' : 'en';
}

void i18n.use(initReactI18next).init({
  resources: { en: { translation: en }, fr: { translation: fr } },
  lng: initialLanguage(),
  fallbackLng: 'en',
  supportedLngs: supportedLanguages,
  // Resource files use flat, literal English phrases as keys. In particular,
  // `Email:` must not be parsed as a namespace and sentence dots are literal.
  nsSeparator: false,
  keySeparator: false,
  interpolation: { escapeValue: false },
  returnNull: false,
});

function applyLanguage(language: string) {
  const normalized: SupportedLanguage = language.toLowerCase().startsWith('fr') ? 'fr' : 'en';
  document.documentElement.lang = normalized;
  try { localStorage.setItem(storageKey, normalized); } catch { /* Preference remains active for this page. */ }
}
applyLanguage(i18n.resolvedLanguage ?? i18n.language);
i18n.on('languageChanged', applyLanguage);

export default i18n;

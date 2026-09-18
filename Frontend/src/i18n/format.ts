export function appLocale(language?: string): 'en-CA' | 'fr-CA' {
  return language?.toLowerCase().startsWith('fr') ? 'fr-CA' : 'en-CA';
}

export function formatCad(cents: number, language?: string): string {
  return new Intl.NumberFormat(appLocale(language), { style: 'currency', currency: 'CAD' }).format(cents / 100);
}

export function formatDateTime(value: string | Date, language?: string, options: Intl.DateTimeFormatOptions = {}): string {
  return new Intl.DateTimeFormat(appLocale(language), options).format(typeof value === 'string' ? new Date(value) : value);
}

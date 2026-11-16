export function clientAccountDisplayName(name: string | undefined, fallback: string): string {
  const candidate = name?.trim();
  return candidate && !/^(unknown|undefined|null)$/i.test(candidate) ? candidate : fallback;
}

export function clientAccountInitials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => Array.from(part)[0]).join('').toUpperCase();
}

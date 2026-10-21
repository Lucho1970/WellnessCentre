export type PublicService = {
  slug: string; name: string; public_summary: string | null; category: string | null;
  description: string | null; preparation_instructions: string | null; offers_clinic: boolean; offers_mobile: boolean;
  durations: { minutes: number; price_cents: number }[];
}
export type PublicPractitioner = {
  slug: string; public_name: string; public_title: string | null; summary: string | null;
  discipline: string | null; credentials: string | null; booking_practitioner_id: number | null;
  has_image: boolean; image_version: string | null;
  public_website_url: string | null; public_contact_email: string | null; public_contact_phone: string | null; public_contact_sms: boolean; social_links: {label:string;url:string}[];
  services: { slug: string; name: string }[];
}
type Row = Record<string, unknown>
function row(value: unknown): Row {
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('Invalid catalogue response')
  return value as Row
}
function slug(value: unknown): string {
  if (typeof value !== 'string' || !/^[a-z0-9][a-z0-9-]{0,119}$/.test(value)) throw new Error('Invalid catalogue identifier')
  return value
}
function text(value: unknown, required = false): string | null {
  if (value === null || value === undefined) { if (!required) return null }
  if (typeof value !== 'string' || (required && !value.trim())) throw new Error('Invalid catalogue text')
  return value as string
}
function number(value: unknown, minimum: number): number {
  if (!['number', 'string'].includes(typeof value) || value === '') throw new Error('Invalid catalogue number')
  const result = Number(value)
  if (!Number.isSafeInteger(result) || result < minimum) throw new Error('Invalid catalogue number')
  return result
}
export async function fetchCatalogue(resource: 'services', signal: AbortSignal): Promise<PublicService[]>
export async function fetchCatalogue(resource: 'practitioners', signal: AbortSignal): Promise<PublicPractitioner[]>
export async function fetchCatalogue(resource: 'services' | 'practitioners', signal: AbortSignal) {
  const controller = new AbortController()
  const abort = () => controller.abort()
  if (signal.aborted) abort()
  signal.addEventListener('abort', abort, { once: true })
  const timeout = setTimeout(abort, 12000)
  try {
  const response = await fetch(`/api/public/${resource}`, { signal: controller.signal, credentials: 'omit', cache: 'no-store' })
  if (!response.ok) throw new Error('Catalogue unavailable')
  const body: unknown = await response.json()
  const data = row(body).data
  if (!Array.isArray(data) || data.length > 500) throw new Error('Invalid catalogue list')
  return data.map(value => {
    const item = row(value)
    if (resource === 'services') {
      if (!Array.isArray(item.durations)) throw new Error('Invalid durations')
      return { slug: slug(item.slug), name: text(item.name, true)!, public_summary: text(item.public_summary), description: text(item.description), preparation_instructions: text(item.preparation_instructions), offers_clinic: Boolean(Number(item.offers_clinic)), offers_mobile: Boolean(Number(item.offers_mobile)), category: text(item.category), durations: item.durations.map(value => { const duration = row(value); return { minutes: number(duration.minutes, 1), price_cents: number(duration.price_cents, 0) } }) }
    }
    if (!Array.isArray(item.services)) throw new Error('Invalid practitioner services')
    const hasImage = item.has_image ?? false
    if (![true, false, 0, 1, '0', '1'].includes(hasImage as boolean | number | string)) throw new Error('Invalid practitioner photo flag')
    const socials = item.social_links ?? []
    if (!Array.isArray(socials) || socials.length > 20) throw new Error('Invalid social links')
    const social_links = socials.map(value => { const link=row(value); return {label:text(link.label,true)!,url:text(link.url,true)!} })
    return { public_website_url:text(item.public_website_url), public_contact_email:text(item.public_contact_email), public_contact_phone:text(item.public_contact_phone), public_contact_sms:Boolean(Number(item.public_contact_sms)), social_links, slug: slug(item.slug), public_name: text(item.public_name, true)!, public_title: text(item.public_title), summary: text(item.summary), discipline: text(item.discipline), credentials: text(item.credentials), has_image: hasImage === true || hasImage === 1 || hasImage === '1', image_version: text(item.image_version), booking_practitioner_id: item.booking_practitioner_id == null ? null : number(item.booking_practitioner_id, 1), services: item.services.map(value => { const service = row(value); return { slug: slug(service.slug), name: text(service.name, true)! } }) }
  })
  } finally { clearTimeout(timeout); signal.removeEventListener('abort', abort) }
}

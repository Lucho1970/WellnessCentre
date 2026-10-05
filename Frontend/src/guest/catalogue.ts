export type PublicService = {
  slug: string; name: string; name_fr: string | null;
  public_summary: string | null; public_summary_fr: string | null;
  description: string | null; description_fr: string | null;
  category_id: number | null; category: string | null; category_fr: string | null;
  category_description: string | null; category_description_fr: string | null;
  durations: { minutes: number; price_cents: number }[];
  offers_clinic: boolean; offers_mobile: boolean;
};

export type PublicPractitioner = {
  slug: string; public_name: string; booking_name: string | null;
  public_title: string | null; public_title_fr: string | null;
  summary: string | null; summary_fr: string | null;
  discipline: string | null; credentials: string | null;
  has_image: boolean; image_version: string | null;
  public_website_url?: string | null; public_contact_email?: string | null; public_contact_phone?: string | null; public_contact_sms?: boolean;
  booking_practitioner_id: number | null;
  services: ({slug: string} & Partial<PublicService>)[];
};

export const localized = (english: string | null | undefined, french: string | null | undefined, language: string) => language.toLowerCase().startsWith('fr') ? french || english || '' : english || french || '';
export const initials = (name: string) => (name ?? '').split(/\s+/).filter(Boolean).slice(0,2).map(part => part[0]?.toUpperCase()).join('');

export function serviceBookingPath(slug: string, minutes?: number, practitionerId?: number | null) {
  const query = new URLSearchParams();
  if (minutes) query.set('duration', String(minutes));
  if (practitionerId) query.set('practitioner_id', String(practitionerId));
  return `/services/${encodeURIComponent(slug)}/book${query.size ? `?${query}` : ''}`;
}

export type CustomerBookingIntent = {
  delivery_mode: 'clinic' | 'mobile';
  location_id: string;
  service_id: string;
  practitioner_id: string;
  duration_option_id: string;
  starts_at: string;
};

const key = 'wellness.customer.booking-intent.v1';

export function captureCustomerBookingIntent(url = new URL(window.location.href)): void {
  if (!url.pathname.endsWith('/client/book')) return;
  const value = Object.fromEntries(['delivery_mode','location_id','service_id','practitioner_id','duration_option_id','starts_at'].map(name => [name, url.searchParams.get(name) ?? ''])) as CustomerBookingIntent;
  const numeric = ['location_id','service_id','practitioner_id','duration_option_id'] as const;
  if (!['clinic','mobile'].includes(value.delivery_mode) || numeric.some(name => !/^\d+$/.test(value[name])) || Number.isNaN(Date.parse(value.starts_at))) return;
  sessionStorage.setItem(key, JSON.stringify(value));
}

export function customerBookingIntent(): CustomerBookingIntent | null {
  try {
    const value = JSON.parse(sessionStorage.getItem(key) ?? 'null');
    return value && ['clinic','mobile'].includes(value.delivery_mode) && /^\d+$/.test(value.location_id) && /^\d+$/.test(value.service_id) && /^\d+$/.test(value.practitioner_id) && /^\d+$/.test(value.duration_option_id) && !Number.isNaN(Date.parse(value.starts_at)) ? value : null;
  } catch { return null; }
}

export function clearCustomerBookingIntent(): void { sessionStorage.removeItem(key); }

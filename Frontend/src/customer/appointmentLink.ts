import { bookingSignInKey, clearCustomerBookingIntent } from './bookingIntent';

const key = 'wellness.customer.appointment-link.v1';
const maximumAge = 24 * 60 * 60 * 1000;

export function captureAppointmentLink(home: string): void {
  if (!window.location.pathname.endsWith('/client/appointment')) return;
  const token = new URLSearchParams(window.location.hash.slice(1)).get('token') ?? '';
  try {
    clearCustomerBookingIntent();
    sessionStorage.removeItem(bookingSignInKey);
    sessionStorage.removeItem('wellness.customer.return-to-browse.v1');
    sessionStorage.setItem(key, JSON.stringify({ token: /^[a-f0-9]{64}$/.test(token) ? token : 'invalid', capturedAt: Date.now() }));
  }
  catch { /* Client sessions also require storage; leave the normal sign-in page available. */ }
  finally { window.history.replaceState(null, '', home); }
}

export function pendingAppointmentLink(): string | null {
  try {
    const value = JSON.parse(sessionStorage.getItem(key) ?? 'null');
    if (!value) return null;
    if (!Number.isFinite(value.capturedAt) || value.capturedAt > Date.now() || Date.now() - value.capturedAt > maximumAge) { clearAppointmentLink(); return null; }
    return typeof value.token === 'string' && /^[a-f0-9]{64}$/.test(value.token) ? value.token : 'invalid';
  } catch { clearAppointmentLink(); return null; }
}

export function clearAppointmentLink(): void {
  try { sessionStorage.removeItem(key); } catch { /* Storage may be unavailable. */ }
}

import { useCallback, useEffect, useState } from 'react';
import { Alert, Box, Button, Card, CardContent, Chip, CircularProgress, Stack, Typography } from '@mui/material';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { formatDateTime } from '../i18n/format';
import { apiBaseUrl, apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { pagePath } from '../portal/access';

type Milestone = 'en_route' | 'arrived' | 'session_started' | 'session_finished' | 'left_residence';
type Event = { code: Milestone; occurred_at: string };
type Visit = { id: number; client_id: number; service_id: number; client_name: string; client_email: string; client_phone: string | null; service_name: string; location_name: string; timezone: string; starts_at: string; ends_at: string; status: string; version: number; delivery_mode: 'clinic' | 'mobile'; events: Partial<Record<Milestone, Event>> };
type Today = { date: string; timezone: string; appointments: Visit[] };
const order: Milestone[] = ['en_route', 'arrived', 'session_started', 'session_finished', 'left_residence'];
const onsiteOnly = new Set<Milestone>(['en_route', 'arrived', 'left_residence']);
const utc = (value: string) => `${value.replace(' ', 'T')}Z`;
const callHref = (phone: string | null) => {
  const normalized = (phone ?? '').replace(/[^\d+]/g, '');
  return /^\+?\d{7,15}$/.test(normalized) ? `tel:${normalized}` : null;
};

export function PractitionerToday() {
  const { t, i18n } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [today, setToday] = useState<Today | null>(null);
  const [loading, setLoading] = useState(true);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState('');
  const request = useCallback(async (path: string, init: RequestInit = {}) => {
    const token = await getAccessToken();
    const response = await fetch(`${apiBaseUrl}${path}`, { ...init, headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', ...init.headers } });
    const body = await response.json().catch(() => null);
    if (!response.ok) throw new Error(apiErrorMessage(body, response.status));
    if (!body || !('data' in body)) throw new Error(t('Unable to load today’s visits.'));
    return normalizeNumericIds(body.data);
  }, [getAccessToken, t]);
  const load = useCallback(async () => {
    setLoading(true); setError('');
    try {
      const result = await request('/practitioner/today') as Today;
      if (!result || !Array.isArray(result.appointments)) throw new Error(t('Unable to load today’s visits.'));
      setToday(result);
    }
    catch (cause) { setToday(null); setError(cause instanceof Error ? cause.message : t('Unable to load today’s visits.')); }
    finally { setLoading(false); }
  }, [request, t]);
  useEffect(() => { void load(); }, [load]);
  const milestone = async (visit: Visit, code: Milestone, action: 'record' | 'undo' = 'record') => {
    setBusyId(visit.id); setError('');
    try { await request(`/practitioner/today/${visit.id}/milestone`, { method: 'POST', body: JSON.stringify({ code, action }) }); await load(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to save the visit step.')); }
    finally { setBusyId(null); }
  };
  const outcome = async (visit: Visit, status: 'completed' | 'no_show' | 'reopen') => {
    if (!window.confirm(t(status === 'completed' ? 'Mark this appointment completed?' : status === 'no_show' ? 'Mark this appointment as a no-show?' : 'Undo this appointment outcome?'))) return;
    setBusyId(visit.id); setError('');
    try { await request(`/practitioner/today/${visit.id}/outcome`, { method: 'POST', body: JSON.stringify({ outcome: status, version: visit.version }) }); await load(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to update the appointment.')); }
    finally { setBusyId(null); }
  };
  const label = (code: Milestone, service: string) => code === 'session_started' ? t('Started {{service}}', { service }) : code === 'session_finished' ? t('Finished {{service}}', { service }) : t(({ en_route: 'En route', arrived: 'Arrived', left_residence: 'Left residence' })[code as 'en_route' | 'arrived' | 'left_residence']);
  return <Stack spacing={2}>
    <Stack direction="row" justifyContent="space-between" alignItems="center" gap={1}><Box><Typography variant="h5">{t('Today’s visits')}</Typography><Typography color="text.secondary">{t('Optional visit steps are timestamped for your records. They do not track your location or notify the client.')}</Typography></Box><Button onClick={() => void load()} disabled={loading || busyId !== null}>{t('Refresh')}</Button></Stack>
    {error && <Alert severity="error">{error}</Alert>}
    {loading ? <CircularProgress size={24} aria-label={t('Loading today’s visits')} /> : today?.appointments.length === 0 ? <Alert severity="info">{t('No appointments scheduled today.')}</Alert> : today?.appointments.map(visit => {
      const phone = callHref(visit.client_phone);
      const active = visit.status === 'confirmed' || visit.status === 'rescheduled';
      const postVisitTravel = visit.delivery_mode === 'mobile' && ['completed', 'invoiced', 'paid'].includes(visit.status);
      const started = new Date(utc(visit.starts_at)).getTime() <= Date.now();
      const inWindow = Date.now() >= new Date(utc(visit.starts_at)).getTime() - 4 * 3600000 && Date.now() <= new Date(utc(visit.ends_at)).getTime() + 2 * 86400000;
      const codes = order.filter(code => visit.delivery_mode === 'mobile' || !onsiteOnly.has(code));
      const latest = [...codes].reverse().find(code => visit.events?.[code]);
      return <Card key={visit.id} variant="outlined"><CardContent><Stack spacing={1.5}>
        <Stack direction="row" justifyContent="space-between" flexWrap="wrap" gap={1}><Box><Typography variant="h6">{visit.client_name} · {visit.service_name}</Typography><Typography>{formatDateTime(utc(visit.starts_at), i18n.resolvedLanguage, { timeZone: visit.timezone, timeStyle: 'short' })} – {formatDateTime(utc(visit.ends_at), i18n.resolvedLanguage, { timeZone: visit.timezone, timeStyle: 'short' })} · {visit.location_name}</Typography></Box><Chip size="small" label={t(visit.status.replaceAll('_', ' '))} color={visit.status === 'no_show' ? 'warning' : visit.status === 'completed' ? 'success' : 'default'} /></Stack>
        <Stack direction="row" flexWrap="wrap" gap={1}>
          {phone && <Button variant="contained" href={phone}>{t('Call client')}</Button>}
          <Button component={Link} to={`${pagePath('practitioner', 'appointments')}?appointment_id=${visit.id}`}>{t('View appointment')}</Button>
          <Button component={Link} to={pagePath('practitioner', 'appointments')} state={{ startBooking: true, bookingClient: { id: visit.client_id, display_name: visit.client_name, email: visit.client_email, phone: visit.client_phone }, bookingServiceId: visit.service_id }}>{t('Book next visit')}</Button>
        </Stack>
        {active || postVisitTravel ? <>
          <Typography variant="subtitle2">{t('Visit steps (optional)')}</Typography>
          <Stack direction="row" flexWrap="wrap" gap={1}>{codes.map(code => <Button key={code} size="small" variant={visit.events?.[code] ? 'contained' : 'outlined'} disabled={busyId !== null || Boolean(visit.events?.[code]) || !inWindow || (!active && code !== 'left_residence')} onClick={() => void milestone(visit, code)}>{label(code, visit.service_name)}</Button>)}</Stack>
          {latest && <Stack direction="row" gap={1} alignItems="center" flexWrap="wrap"><Typography variant="body2" color="text.secondary">{t('Last step: {{step}} at {{time}}', { step: label(latest, visit.service_name), time: formatDateTime(utc(visit.events![latest]!.occurred_at), i18n.resolvedLanguage, { timeZone: visit.timezone, timeStyle: 'short' }) })}</Typography><Button size="small" disabled={busyId !== null || (!active && latest !== 'left_residence')} onClick={() => void milestone(visit, latest, 'undo')}>{t('Undo last step')}</Button></Stack>}
          {!inWindow && active && <Typography variant="caption" color="text.secondary">{t('Visit steps become available within four hours of the appointment.')}</Typography>}
        </> : null}
        {active && <Stack direction="row" gap={1} flexWrap="wrap"><Button variant="outlined" disabled={busyId !== null || !started} onClick={() => void outcome(visit, 'completed')}>{t('Mark completed')}</Button><Button color="warning" disabled={busyId !== null || !started || Boolean(visit.events?.session_started || visit.events?.session_finished)} onClick={() => void outcome(visit, 'no_show')}>{t('Mark no-show')}</Button></Stack>}
        {(visit.status === 'completed' || visit.status === 'no_show') && <Button sx={{ alignSelf: 'flex-start' }} size="small" disabled={busyId !== null} onClick={() => void outcome(visit, 'reopen')}>{t('Undo outcome')}</Button>}
        {visit.status === 'requested' && <Typography variant="body2" color="text.secondary">{t('This appointment must be confirmed before visit steps can be recorded.')}</Typography>}
      </Stack></CardContent></Card>;
    })}
  </Stack>;
}

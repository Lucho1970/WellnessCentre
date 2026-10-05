import { useCallback, useEffect, useState } from 'react';
import { Alert, Box, Button, CircularProgress, Link as MuiLink, Paper, Stack, Typography } from '@mui/material';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { formatDateTime } from '../i18n/format';
import { apiBaseUrl, apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { pagePath } from '../portal/access';

type Visit = { id: number; starts_at: string; service_name: string; client_name: string; timezone: string; clinic_origin_available: boolean };
type Estimate = { appointment_id: number; starts_at: string; source: 'current' | 'clinic'; duration_minutes: number; distance_km: number; checked_at_utc: string; destination_address: string; traffic_aware: boolean };
const utc = (value: string) => `${value.replace(' ', 'T')}Z`;

function currentLocation(): Promise<{ latitude: number; longitude: number }> {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) { reject(new Error('Location is not available on this device.')); return; }
    navigator.geolocation.getCurrentPosition(
      position => resolve({ latitude: position.coords.latitude, longitude: position.coords.longitude }),
      () => reject(new Error('Location permission was denied or the device could not determine your position. Try the clinic starting point.')),
      { enableHighAccuracy: false, timeout: 10000, maximumAge: 0 },
    );
  });
}

export function PractitionerTravelCard() {
  const { t, i18n } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [visit, setVisit] = useState<Visit | null>(null);
  const [estimate, setEstimate] = useState<Estimate | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const load = useCallback(async () => {
    setLoading(true); setError(''); setEstimate(null);
    try {
      const token = await getAccessToken();
      const response = await fetch(`${apiBaseUrl}/practitioner/next-onsite`, { headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json().catch(() => null);
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status));
      if (!body || !('data' in body)) throw new Error(t('Unable to load the next On-Site visit.'));
      const next = normalizeNumericIds(body.data) as Visit | null;
      setVisit(next?.id ? next : null);
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to load the next On-Site visit.')); }
    finally { setLoading(false); }
  }, [getAccessToken, t]);
  useEffect(() => { void load(); }, [load]);
  const check = async (source: 'current' | 'clinic') => {
    if (!visit || busy) return;
    setBusy(true); setError(''); setEstimate(null);
    try {
      const coordinates = source === 'current' ? await currentLocation() : null;
      const token = await getAccessToken();
      const response = await fetch(`${apiBaseUrl}/practitioner/next-onsite/${visit.id}/travel-estimate`, {
        method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ source, ...coordinates }),
      });
      const body = await response.json().catch(() => null);
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status));
      if (!body || !('data' in body)) throw new Error(t('Unable to check travel time.'));
      setEstimate(normalizeNumericIds(body.data) as Estimate);
    } catch (cause) { setError(t(cause instanceof Error ? cause.message : 'Unable to check travel time.')); }
    finally { setBusy(false); }
  };
  const departure = estimate && visit ? new Date(Date.parse(utc(visit.starts_at)) - (estimate.duration_minutes + 10) * 60000) : null;
  const directions = estimate ? `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(estimate.destination_address)}&travelmode=driving` : '';
  return <Paper variant="outlined" sx={{ p: 2.5 }}><Stack spacing={1.5}>
    <Typography variant="h6">{t('Travel to your next On-Site visit')}</Typography>
    {loading ? <CircularProgress size={22} aria-label={t('Loading next On-Site visit')} /> : !visit ? <Typography color="text.secondary">{t('No On-Site visit starts in the next 24 hours.')}</Typography> : <>
      <Typography>{visit.client_name} · {visit.service_name}</Typography>
      <Typography fontWeight={700}>{formatDateTime(utc(visit.starts_at), i18n.resolvedLanguage, { timeZone: visit.timezone, dateStyle: 'medium', timeStyle: 'short' })}</Typography>
      <Stack direction="row" gap={1} flexWrap="wrap">
        <Button variant="contained" disabled={busy} onClick={() => void check('current')}>{t('Check from my location')}</Button>
        {visit.clinic_origin_available && <Button variant="outlined" disabled={busy} onClick={() => void check('clinic')}>{t('Check from clinic')}</Button>}
        <Button component={Link} to={`${pagePath('practitioner', 'appointments')}?appointment_id=${visit.id}`}>{t('View appointment')}</Button>
      </Stack>
      <Typography variant="caption" color="text.secondary">{t('Your location is requested only when you choose to check from it, then sent to Google for this estimate. It is not saved or tracked by the clinic.')}</Typography>
    </>}
    {busy && <CircularProgress size={20} aria-label={t('Checking travel time')} />}
    {error && <Alert severity="warning">{error}</Alert>}
    {estimate && departure && <Box>
      <Typography fontWeight={700}>{t('With current traffic: about {{minutes}} min · {{distance}} km', { minutes: estimate.duration_minutes, distance: estimate.distance_km })}</Typography>
      <Typography>{departure.getTime() <= Date.now() ? t('Leave now to aim for 10 minutes early.') : t('Suggested leave time (10 min early): {{time}}', { time: formatDateTime(departure.toISOString(), i18n.resolvedLanguage, { timeZone: visit?.timezone, timeStyle: 'short' }) })}</Typography>
      <Typography variant="body2" color="text.secondary">{t('Checked at {{time}}. Traffic may change; check again before leaving.', { time: formatDateTime(estimate.checked_at_utc, i18n.resolvedLanguage, { timeStyle: 'short' }) })}</Typography>
      <MuiLink href={directions} target="_blank" rel="noopener noreferrer">{t('Open Google Maps directions')}</MuiLink>
      <Typography variant="caption" display="block" color="text.secondary">{t('Route and traffic estimate by')} <Box component="span" translate="no" sx={{ whiteSpace: 'nowrap', color: '#5e5e5e', fontWeight: 400, fontSize: 12 }}>Google Maps</Box></Typography>
    </Box>}
  </Stack></Paper>;
}

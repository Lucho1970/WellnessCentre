import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Alert, Button, Paper, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { ApiError } from '../shared/api';
import { TextField, useFormValidation, withFormValidation } from '../shared/FormValidation';
import { DiscoveryPreferences } from '../discovery/DiscoverySettings';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type Assignment = {
  service_id: number; name: string; name_fr: string | null; revision: string;
  mobile_radius_km: number | null; mobile_free_radius_km: number | null;
  travel_buffer_minutes: number; mobile_fee_cents: number;
};
type Request = (path: string, body?: unknown) => Promise<any>;

const TravelForm = withFormValidation(function TravelForm({ item, request, saved }: { item: Assignment; request: Request; saved: (item: Assignment) => void }) {
  const { t, i18n } = useTranslation();
  const validation = useFormValidation();
  const [radius, setRadius] = useState(String(item.mobile_radius_km ?? ''));
  const [free, setFree] = useState(String(item.mobile_free_radius_km ?? ''));
  const [buffer, setBuffer] = useState(String(item.travel_buffer_minutes));
  const [fee, setFee] = useState((item.mobile_fee_cents / 100).toFixed(2));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const submit = async (event: FormEvent) => {
    event.preventDefault(); validation.clear(); setError(''); setBusy(true);
    try {
      const updated = await request(`/profile/travel-settings/${item.service_id}`, {
        revision: item.revision, mobile_radius_km: Number(radius),
        mobile_free_radius_km: free === '' ? null : Number(free),
        travel_buffer_minutes: Number(buffer), mobile_fee_cents: Math.round(Number(fee) * 100),
      });
      saved(updated);
    } catch (cause) { validation.capture(cause); setError(cause instanceof Error ? cause.message : t('Unable to load or save travel settings.')); }
    finally { setBusy(false); }
  };
  return <Paper component="form" onSubmit={event => void submit(event)} variant="outlined" sx={{ p: 2 }} aria-label={item.name}>
    <Stack spacing={2}>
      <Typography variant="h6">{i18n.language.startsWith('fr') && item.name_fr ? item.name_fr : item.name}</Typography>
      {error && <Alert severity="error">{error}</Alert>}
      <TextField name="mobile_radius_km" required type="number" label={t('Maximum driving distance (km)')} value={radius} onChange={event => setRadius(event.target.value)} inputProps={{ min: 1, max: 500, step: 1 }} disabled={busy}/>
      <TextField name="mobile_free_radius_km" type="number" label={t('Free travel distance (km)')} value={free} onChange={event => setFree(event.target.value)} helperText={t('Blank keeps the existing flat fee at all distances.')} inputProps={{ min: 0, max: Number(radius) || 500, step: 1 }} disabled={busy}/>
      <TextField name="travel_buffer_minutes" required type="number" label={t('Travel minutes each way')} value={buffer} onChange={event => setBuffer(event.target.value)} inputProps={{ min: 0, max: 240, step: 15 }} disabled={busy}/>
      <TextField name="mobile_fee_cents" required type="number" label={t('Flat extra travel fee CAD')} value={fee} onChange={event => setFee(event.target.value)} inputProps={{ min: 0, max: 100000, step: 0.01 }} disabled={busy}/>
      <Button type="submit" variant="contained" disabled={busy}>{t('Save travel settings')}</Button>
    </Stack>
  </Paper>;
});

export function PractitionerTravelSettings() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [items, setItems] = useState<Assignment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [reload, setReload] = useState(0);
  const request = useCallback<Request>(async (path, body) => {
    const token = await getAccessToken();
    const response = await fetch(`${api}${path}`, {
      method: body === undefined ? 'GET' : 'PUT',
      headers: { Authorization: `Bearer ${token}`, ...(body === undefined ? {} : { 'Content-Type': 'application/json' }) },
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    const result = await response.json();
    if (!response.ok) throw new ApiError(result, response.status);
    return result.data;
  }, [getAccessToken, t]);
  useEffect(() => {
    let active = true; setLoading(true); setError(''); setMessage('');
    void request('/profile/travel-settings').then(data => { if (active) setItems(data.items); })
      .catch(cause => { if (active) setError(cause instanceof Error ? cause.message : t('Unable to load or save travel settings.')); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [request, reload, t]);
  return <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 } }}>
    <Stack spacing={2}>
      <Typography variant="h5">{t('My travel settings')}</Typography>
      <DiscoveryPreferences request={request}/>
      <Typography>{t('Set travel distances, fees and buffers for your assigned On-Site services in this clinic. Other clinics and existing appointments keep their settings.')}</Typography>
      <Typography variant="body2">{t('The free distance is included. Beyond it, the flat fee applies up to your maximum distance. Travel time is reserved before and after each new visit.')}</Typography>
      {message && <Alert severity="success">{message}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}
      <Button onClick={() => setReload(value => value + 1)} disabled={loading}>{t('Reload travel settings')}</Button>
      {loading ? <Typography>{t('Loading…')}</Typography> : !error && items.length === 0 ? <Alert severity="info">{t('No active On-Site services are assigned to you in this clinic. Ask an administrator to configure your service assignments.')}</Alert> : !error && items.map(item =>
        <TravelForm key={`${item.service_id}:${item.revision}:${reload}`} item={item} request={request} saved={updated => {
          setItems(current => current.map(row => row.service_id === updated.service_id ? updated : row));
          setMessage(t('Travel settings saved for this clinic.'));
        }}/>) }
    </Stack>
  </Paper>;
}

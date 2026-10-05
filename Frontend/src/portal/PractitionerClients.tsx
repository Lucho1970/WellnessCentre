import { useEffect, useState, type FormEvent } from 'react';
import { Alert, Box, Button, CircularProgress, Divider, Drawer, Link as MuiLink, List, ListItemButton, ListItemText, Paper, Stack, TextField, Typography } from '@mui/material';
import { RefreshCw } from 'lucide-react';
import { Link as RouterLink } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiBaseUrl, apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { pagePath } from './access';

type Client = { id: number; display_name: string; email: string; phone: string | null; preferred_contact: string | null; status: string; appointment_count: number; recent_appointment_id: number | null };
type Result = { items: Client[]; page: number; has_more: boolean };

function emailHref(value: string) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) ? `mailto:${encodeURIComponent(value)}` : null;
}
function phoneHrefs(value: string | null) {
  const phone = (value ?? '').replace(/[^\d+]/g, '');
  return /^\+?\d{7,15}$/.test(phone) ? { call: `tel:${phone}`, text: `sms:${phone}` } : null;
}

export function PractitionerClients() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [draft, setDraft] = useState('');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [refresh, setRefresh] = useState(0);
  const [data, setData] = useState<Result | null>(null);
  const [selected, setSelected] = useState<Client | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true); setError('');
    void (async () => {
      try {
        const token = await getAccessToken();
        const params = new URLSearchParams({ page: String(page) });
        if (query) params.set('q', query);
        const response = await fetch(`${apiBaseUrl}/practitioner/clients?${params}`, { headers: { Authorization: `Bearer ${token}` }, signal: controller.signal });
        const body = await response.json();
        if (!response.ok) throw new Error(apiErrorMessage(body, response.status));
        if (!controller.signal.aborted) setData(normalizeNumericIds(body.data));
      } catch (cause) {
        if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : t('Unable to load your clients.'));
      } finally { if (!controller.signal.aborted) setLoading(false); }
    })();
    return () => controller.abort();
  }, [getAccessToken, page, query, refresh, t]);
  const search = (event: FormEvent) => { event.preventDefault(); setPage(1); setQuery(draft.trim()); setSelected(null); };
  const email = selected && emailHref(selected.email);
  const phone = phoneHrefs(selected?.phone ?? null);
  return <Stack spacing={2}>
    <Alert severity="info">{t('This list includes clients you have booked or added yourself. It is not the clinic-wide client directory.')}</Alert>
    <Paper variant="outlined" component="form" onSubmit={search} sx={{ p: 1.5 }}><Stack direction="row" flexWrap="wrap" useFlexGap gap={1} alignItems="center">
      <TextField size="small" label={t('Search my clients')} value={draft} onChange={event => setDraft(event.target.value)} inputProps={{ maxLength: 190 }} sx={{ flex: '1 1 220px' }} />
      <Button type="submit" variant="contained">{t('Search')}</Button>
      <Button startIcon={<RefreshCw size={17} />} disabled={loading} onClick={() => setRefresh(value => value + 1)}>{t('Refresh')}</Button>
    </Stack></Paper>
    {error && <Alert severity="error" action={<Button onClick={() => setRefresh(value => value + 1)}>{t('Try again')}</Button>}>{error}</Alert>}
    <Paper variant="outlined">
      {loading && !data ? <Box p={3}><CircularProgress size={24} aria-label={t('Loading clients')} /></Box> : !loading && !error && !data?.items.length ? <Typography p={3}>{t('No clients match this search.')}</Typography> : <List disablePadding aria-label={t('My clients')}>
        {data?.items.map(client => <ListItemButton key={client.id} divider onClick={() => setSelected(client)}>
          <ListItemText primary={<Typography fontWeight={700}>{client.display_name}</Typography>} secondary={t('{{count}} appointments', { count: Number(client.appointment_count) })} />
        </ListItemButton>)}
      </List>}
    </Paper>
    <Stack direction="row" gap={1} justifyContent="flex-end" alignItems="center"><Button disabled={page <= 1 || loading} onClick={() => setPage(value => value - 1)}>{t('Previous')}</Button><Typography>{t('Page {{page}}', { page })}</Typography><Button disabled={!data?.has_more || loading} onClick={() => setPage(value => value + 1)}>{t('Next')}</Button></Stack>
    <Drawer anchor="right" open={selected !== null} onClose={() => setSelected(null)} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 440 }, maxWidth: '100%' } } }}><Stack spacing={2} p={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center"><Typography variant="h5">{selected?.display_name}</Typography><Button onClick={() => setSelected(null)}>{t('Close')}</Button></Stack><Divider />
      {selected && <>
        <Box><Typography variant="caption" color="text.secondary">{t('Email')}</Typography><Typography>{email ? <MuiLink href={email}>{selected.email}</MuiLink> : selected.email}</Typography></Box>
        <Box><Typography variant="caption" color="text.secondary">{t('Phone')}</Typography><Stack direction="row" gap={2}><Typography>{phone ? <MuiLink href={phone.call} aria-label={t('Call client')}>{selected.phone}</MuiLink> : selected.phone || t('Not set')}</Typography>{phone && <MuiLink href={phone.text}>{t('Text client')}</MuiLink>}</Stack></Box>
        <Box><Typography variant="caption" color="text.secondary">{t('Preferred contact')}</Typography><Typography>{selected.preferred_contact ? t(selected.preferred_contact) : t('Not set')}</Typography></Box>
        <Typography color="text.secondary">{t('{{count}} appointments', { count: Number(selected.appointment_count) })}</Typography>
        {selected.status === 'active' && <Button component={RouterLink} to={pagePath('practitioner', 'appointments')} state={{ startBooking: true, bookingClient: { id: selected.id, display_name: selected.display_name, email: selected.email, phone: selected.phone } }} variant="contained">{t('Book appointment')}</Button>}
        {selected.recent_appointment_id && <Button component={RouterLink} to={`${pagePath('practitioner', 'appointments')}?appointment_id=${selected.recent_appointment_id}`} variant="outlined">{t('View latest appointment')}</Button>}
      </>}
    </Stack></Drawer>
  </Stack>;
}

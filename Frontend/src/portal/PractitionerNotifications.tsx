import { ApiError } from '../shared/api';
import { Alert, TextField, withFormValidation, useFormValidation } from '../shared/FormValidation';
import { useCallback, useEffect, useState } from 'react';
import { Box, Button, Chip, Divider, Drawer, List, ListItemButton, ListItemText, MenuItem, Paper, Stack, Typography } from '@mui/material';
import { RefreshCw } from 'lucide-react';
import { Link, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiBaseUrl, normalizeNumericIds } from '../shared/api';
import { pagePath } from './access';

type Status = 'queued' | 'sending' | 'sent' | 'delivered' | 'failed' | 'canceled' | 'needs_review' | 'resolved';
type Notice = { id: number; appointment_id: number | null; recipient_address: string; event_code: string; channel: 'email' | 'sms'; status: Status; scheduled_at: string; sent_at: string | null };
type Result = { items: Notice[]; total: number; page: number; page_size: number };
const statuses: Status[] = ['needs_review', 'failed', 'queued', 'sending', 'sent', 'delivered', 'canceled', 'resolved'];
const statusLabel: Record<Status, string> = { queued: 'Queued', sending: 'Sending', sent: 'Accepted by provider', delivered: 'Delivered', failed: 'Failed', canceled: 'Canceled', needs_review: 'Needs review', resolved: 'Closed after review' };
const eventLabel: Record<string, string> = { staff_booking_confirmation: 'Staff booking notice', staff_booking_change: 'Staff change notice', staff_booking_cancellation: 'Staff cancellation notice', staff_booking_reassigned_away: 'Appointment moved off schedule' };
const time = (value: string | null) => value ? new Date(`${value.replace(' ', 'T')}Z`).toLocaleString() : '—';

function PractitionerNotificationsForm() {
  const formValidation = useFormValidation();
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const status = searchParams.get('status') ?? '';
  const channel = searchParams.get('channel') ?? '';
  const period = searchParams.get('period') ?? '';
  const page = Number(searchParams.get('page') ?? 1) || 1;
  const [data, setData] = useState<Result | null>(null);
  const [selected, setSelected] = useState<Notice | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const setFilter = (name: 'status' | 'channel' | 'period', value: string) => {
    const next = new URLSearchParams(searchParams);
    if (value) next.set(name, value); else next.delete(name);
    next.delete('page'); setSearchParams(next);
  };
  const setPage = (value: number) => {
    const next = new URLSearchParams(searchParams);
    if (value > 1) next.set('page', String(value)); else next.delete('page');
    setSearchParams(next);
  };
  const load = useCallback(async () => {
    setLoading(true); (formValidation.clear(), setError(''));
    try {
      const token = await getAccessToken();
      const params = new URLSearchParams({ page: String(page) });
      if (status) params.set('status', status);
      if (channel) params.set('channel', channel);
      if (period) params.set('period', period);
      const response = await fetch(`${apiBaseUrl}/practitioner/notifications?${params}`, { headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json();
      if (!response.ok) throw new ApiError(body, response.status);
      setData(normalizeNumericIds(body.data));
    } catch (cause) { formValidation.capture(cause); setError(cause instanceof Error ? cause.message : t('Unable to load your notifications.')); }
    finally { setLoading(false); }
  }, [getAccessToken, page, status, channel, period, t]);
  useEffect(() => { void load(); }, [load]);
  const totalPages = Math.max(1, Math.ceil((data?.total ?? 0) / (data?.page_size ?? 25)));
  return <Stack spacing={2}>
    <Alert severity="info">{t('This is a read-only history of booking notices addressed to you. Provider acceptance does not prove delivery. Clinic administrators handle items that need review.')}</Alert>
    {error && <Alert severity="error" action={<Button onClick={() => void load()}>{t('Try again')}</Button>}>{error}</Alert>}
    <Paper variant="outlined"><Stack direction={{ xs: 'column', sm: 'row' }} gap={1.5} alignItems={{ sm: 'center' }} p={1.5}>
      <TextField name="status" select size="small" label={t('Status')} value={status} onChange={event => setFilter('status', event.target.value)} sx={{ minWidth: 205 }}>
        <MenuItem value="">{t('All statuses')}</MenuItem>{statuses.map(item => <MenuItem value={item} key={item}>{t(statusLabel[item])}</MenuItem>)}
      </TextField>
      <TextField name="channel" select size="small" label={t('Channel')} value={channel} onChange={event => setFilter('channel', event.target.value)} sx={{ minWidth: 150 }}>
        <MenuItem value="">{t('All channels')}</MenuItem><MenuItem value="email">{t('Email')}</MenuItem><MenuItem value="sms">SMS</MenuItem>
      </TextField>
      <TextField name="period" select size="small" label={t('Activity date')} value={period} onChange={event => setFilter('period', event.target.value)} sx={{ minWidth: 170 }}>
        <MenuItem value="">{t('All dates')}</MenuItem><MenuItem value="today">{t('Today')}</MenuItem><MenuItem value="last7">{t('Last 7 days')}</MenuItem>
      </TextField>
      <Button startIcon={<RefreshCw size={17} />} onClick={() => void load()} disabled={loading}>{t('Refresh')}</Button>
      <Typography color="text.secondary" sx={{ ml: { sm: 'auto' } }}>{t('{{count}} notifications', { count: data?.total ?? 0 })}</Typography>
    </Stack></Paper>
    <Paper variant="outlined">
      {loading && !data ? <Typography p={3} role="status">{t('Loading notification status…')}</Typography> : !data?.items.length ? <Typography p={3}>{t('No notifications match these filters.')}</Typography> : <List disablePadding aria-label={t('My notifications')}>
        {data.items.map(item => <ListItemButton divider key={item.id} onClick={() => setSelected(item)} sx={{ alignItems: 'flex-start', gap: 2 }}>
          <ListItemText primary={<Stack direction="row" gap={1} alignItems="center" flexWrap="wrap"><Typography fontWeight={700}>#{item.id} · {t(eventLabel[item.event_code] ?? item.event_code)}</Typography><Chip size="small" label={item.channel === 'sms' ? 'SMS' : t('Email')} /><Chip size="small" color={item.status === 'needs_review' ? 'warning' : item.status === 'failed' ? 'error' : item.status === 'sent' || item.status === 'delivered' ? 'success' : 'default'} label={t(statusLabel[item.status])} /></Stack>} secondary={`${item.recipient_address} · ${time(item.scheduled_at)}`} />
        </ListItemButton>)}
      </List>}
    </Paper>
    <Stack direction="row" justifyContent="flex-end" alignItems="center" gap={1}><Button disabled={page <= 1 || loading} onClick={() => setPage(page - 1)}>{t('Previous')}</Button><Typography>{t('Page {{page}} of {{total}}', { page, total: totalPages })}</Typography><Button disabled={page >= totalPages || loading} onClick={() => setPage(page + 1)}>{t('Next')}</Button></Stack>
    <Drawer anchor="right" open={selected !== null} onClose={() => setSelected(null)}><Box sx={{ width: { xs: '100vw', sm: 440 }, p: 3 }}><Stack spacing={2}>
      <Stack direction="row" justifyContent="space-between" alignItems="center"><Typography variant="h5">{t('Notification #{{id}}', { id: selected?.id })}</Typography><Button onClick={() => setSelected(null)}>{t('Close')}</Button></Stack><Divider />
      {selected && <><Chip sx={{ alignSelf: 'flex-start' }} label={t(statusLabel[selected.status])} color={selected.status === 'needs_review' ? 'warning' : selected.status === 'failed' ? 'error' : 'default'} />
        {selected.status === 'needs_review' && <Alert severity="warning">{t('A clinic administrator will review this notice. It will not be resent automatically.')}</Alert>}
        <Typography><strong>{t('Event')}:</strong> {t(eventLabel[selected.event_code] ?? selected.event_code)}</Typography>
        <Typography><strong>{t('Channel')}:</strong> {selected.channel === 'sms' ? 'SMS' : t('Email')}</Typography>
        <Typography><strong>{t('Recipient')}:</strong> {selected.recipient_address}</Typography>
        <Typography><strong>{t('Scheduled')}:</strong> {time(selected.scheduled_at)}</Typography>
        <Typography><strong>{t('Provider accepted')}:</strong> {time(selected.sent_at)}</Typography>
        {selected.appointment_id && selected.event_code !== 'staff_booking_reassigned_away' && <Button component={Link} to={`${pagePath('practitioner', 'appointments')}?appointment_id=${selected.appointment_id}`} onClick={() => setSelected(null)} variant="outlined">{t('View appointment')}</Button>}
      </>}
    </Stack></Box></Drawer>
  </Stack>;
}
export const PractitionerNotifications = withFormValidation(PractitionerNotificationsForm);

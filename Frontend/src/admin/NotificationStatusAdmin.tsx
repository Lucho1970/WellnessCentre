import { useCallback, useEffect, useState } from 'react';
import { Alert, Box, Button, Chip, Divider, Drawer, List, ListItemButton, ListItemText, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { RefreshCw } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiBaseUrl, apiErrorMessage, normalizeNumericIds } from '../shared/api';

type Status = 'queued' | 'sending' | 'sent' | 'delivered' | 'failed' | 'canceled' | 'needs_review';
type Event = { id: number; appointment_id: number | null; recipient_address: string; event_code: string; channel: string; status: Status; scheduled_at: string; next_attempt_at: string | null; sent_at: string | null; attempt_count: number; last_error: string | null };
type Result = { items: Event[]; counts: Record<Status, number>; total: number; page: number; page_size: number };
const statuses: Status[] = ['needs_review', 'failed', 'queued', 'sending', 'sent', 'delivered', 'canceled'];
const statusLabel: Record<Status, string> = { needs_review: 'Needs review', failed: 'Failed', queued: 'Queued', sending: 'Sending', sent: 'Accepted by provider', delivered: 'Delivered', canceled: 'Canceled' };
const eventLabel: Record<string, string> = { booking_confirmation: 'Booking confirmation', booking_change: 'Booking change', booking_cancellation: 'Booking cancellation' };
const time = (value: string | null) => value ? new Date(`${value.replace(' ', 'T')}Z`).toLocaleString() : '—';

export function NotificationStatusAdmin() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [data, setData] = useState<Result | null>(null);
  const [selected, setSelected] = useState<Event | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const load = useCallback(async () => {
    setLoading(true); setError('');
    try {
      const token = await getAccessToken();
      const params = new URLSearchParams({ page: String(page) });
      if (status) params.set('status', status);
      const response = await fetch(`${apiBaseUrl}/admin/notifications?${params}`, { headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status));
      setData(normalizeNumericIds(body.data));
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to load email status.')); }
    finally { setLoading(false); }
  }, [getAccessToken, page, status, t]);
  useEffect(() => { void load(); }, [load]);
  const totalPages = Math.max(1, Math.ceil((data?.total ?? 0) / (data?.page_size ?? 25)));
  return <Stack spacing={2}>
    <Alert severity="info">{t('Email status reflects the queue and provider acceptance, not proof of inbox delivery. Items needing review are never resent automatically.')}</Alert>
    {error && <Alert severity="error">{error}</Alert>}
    <Paper variant="outlined"><Stack direction={{ xs: 'column', sm: 'row' }} gap={1.5} alignItems={{ sm: 'center' }} p={1.5}>
      <TextField select size="small" label={t('Status')} value={status} onChange={event => { setStatus(event.target.value); setPage(1); }} sx={{ minWidth: 220 }}>
        <MenuItem value="">{t('All statuses')}</MenuItem>{statuses.map(item => <MenuItem value={item} key={item}>{t(statusLabel[item])} ({data?.counts[item] ?? 0})</MenuItem>)}
      </TextField>
      <Button startIcon={<RefreshCw size={17} />} onClick={() => void load()} disabled={loading}>{t('Refresh')}</Button>
      <Typography color="text.secondary" sx={{ ml: { sm: 'auto' } }}>{t('{{count}} notifications', { count: data?.total ?? 0 })}</Typography>
    </Stack></Paper>
    <Paper variant="outlined">
      {loading && !data ? <Typography p={3} role="status">{t('Loading email status…')}</Typography> : !data?.items.length ? <Typography p={3}>{t('No notifications match this status.')}</Typography> : <List disablePadding aria-label={t('Email notifications')}>
        {data.items.map(item => <ListItemButton divider key={item.id} onClick={() => setSelected(item)} sx={{ alignItems: 'flex-start', gap: 2 }}>
          <ListItemText primary={<Stack direction="row" gap={1} alignItems="center" flexWrap="wrap"><Typography fontWeight={700}>#{item.id} · {t(eventLabel[item.event_code] ?? item.event_code)}</Typography><Chip size="small" color={item.status === 'needs_review' ? 'warning' : item.status === 'failed' ? 'error' : item.status === 'sent' || item.status === 'delivered' ? 'success' : 'default'} label={t(statusLabel[item.status])} /></Stack>} secondary={`${item.recipient_address} · ${time(item.scheduled_at)}`} />
        </ListItemButton>)}
      </List>}
    </Paper>
    <Stack direction="row" justifyContent="flex-end" alignItems="center" gap={1}><Button disabled={page <= 1 || loading} onClick={() => setPage(page - 1)}>{t('Previous')}</Button><Typography>{t('Page {{page}} of {{total}}', { page, total: totalPages })}</Typography><Button disabled={page >= totalPages || loading} onClick={() => setPage(page + 1)}>{t('Next')}</Button></Stack>
    <Drawer anchor="right" open={selected !== null} onClose={() => setSelected(null)}><Box sx={{ width: { xs: '100vw', sm: 440 }, p: 3 }}><Stack spacing={2}>
      <Stack direction="row" justifyContent="space-between" alignItems="center"><Typography variant="h5">{t('Notification #{{id}}', { id: selected?.id })}</Typography><Button onClick={() => setSelected(null)}>{t('Close')}</Button></Stack><Divider />
      {selected && <><Chip sx={{ alignSelf: 'flex-start' }} label={t(statusLabel[selected.status])} color={selected.status === 'needs_review' ? 'warning' : selected.status === 'failed' ? 'error' : 'default'} />
        {selected.status === 'needs_review' && <Alert severity="warning">{t('Delivery outcome may be unknown. Check the provider and recipient before deciding whether to send manually.')}</Alert>}
        <Typography><strong>{t('Appointment ID')}:</strong> {selected.appointment_id ?? '—'}</Typography>
        <Typography><strong>{t('Event')}:</strong> {t(eventLabel[selected.event_code] ?? selected.event_code)}</Typography>
        <Typography><strong>{t('Recipient')}:</strong> {selected.recipient_address}</Typography>
        <Typography><strong>{t('Scheduled')}:</strong> {time(selected.scheduled_at)}</Typography>
        <Typography><strong>{t('Next attempt')}:</strong> {time(selected.next_attempt_at)}</Typography>
        <Typography><strong>{t('Provider accepted')}:</strong> {time(selected.sent_at)}</Typography>
        <Typography><strong>{t('Attempts')}:</strong> {selected.attempt_count}</Typography>
        {selected.last_error && <Alert severity="error">{selected.last_error}</Alert>}
      </>}
    </Stack></Box></Drawer>
  </Stack>;
}

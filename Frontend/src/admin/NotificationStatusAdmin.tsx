import { useCallback, useEffect, useState } from 'react';
import { Alert, Box, Button, Checkbox, Chip, Divider, Drawer, FormControlLabel, List, ListItemButton, ListItemText, MenuItem, Paper, Stack, Switch, TextField, Typography } from '@mui/material';
import { RefreshCw } from 'lucide-react';
import { useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiBaseUrl, apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { NotificationHealthBanner, type NotificationHealth } from '../shared/NotificationHealthBanner';

type Status = 'queued' | 'sending' | 'sent' | 'delivered' | 'failed' | 'canceled' | 'needs_review' | 'resolved';
type ReviewOutcome = 'provider_accepted' | 'handled_manually' | 'no_longer_needed';
type Event = { id: number; appointment_id: number | null; recipient_address: string; event_code: string; channel: string; status: Status; scheduled_at: string; next_attempt_at: string | null; sent_at: string | null; attempt_count: number; last_error: string | null; review_outcome: string | null };
type Result = { items: Event[]; counts: Record<Status, number>; total: number; page: number; page_size: number; health?: NotificationHealth };
type ReminderSchedule = { id: number; minutes_before: number; active: number | boolean };
const reminderTimes = [60, 180, 1440, 2880, 4320] as const;
const reminderLabel: Record<number, string> = { 60: '1 hour before', 180: '3 hours before', 1440: '1 day before', 2880: '2 days before', 4320: '3 days before' };
const reminderActive = (row: ReminderSchedule) => row.active === true || Number(row.active) === 1;
const statuses: Status[] = ['needs_review', 'failed', 'queued', 'sending', 'sent', 'delivered', 'canceled', 'resolved'];
const statusLabel: Record<Status, string> = { needs_review: 'Needs review', failed: 'Failed', queued: 'Queued', sending: 'Sending', sent: 'Accepted by provider', delivered: 'Delivered', canceled: 'Canceled', resolved: 'Closed after review' };
const outcomeLabel: Record<string, string> = { provider_accepted: 'Provider history confirms acceptance', handled_manually: 'Recipient contacted manually', no_longer_needed: 'Notice no longer needed', provider_not_sent: 'Provider and recipient confirm not sent' };
const eventLabel: Record<string, string> = { booking_confirmation: 'Booking confirmation', booking_change: 'Booking change', booking_cancellation: 'Booking cancellation', appointment_reminder: 'Appointment reminder', staff_booking_confirmation: 'Staff booking notice', staff_booking_change: 'Staff change notice', staff_booking_cancellation: 'Staff cancellation notice', staff_booking_reassigned_away: 'Appointment moved off schedule' };
const time = (value: string | null) => value ? new Date(`${value.replace(' ', 'T')}Z`).toLocaleString() : '—';

export function NotificationStatusAdmin() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const status = searchParams.get('status') ?? '';
  const channel = searchParams.get('channel') ?? '';
  const period = searchParams.get('period') ?? '';
  const page = Number(searchParams.get('page') ?? 1) || 1;
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
  const [data, setData] = useState<Result | null>(null);
  const [selected, setSelected] = useState<Event | null>(null);
  const [reviewOutcome, setReviewOutcome] = useState<ReviewOutcome>('no_longer_needed');
  const [checkedProvider, setCheckedProvider] = useState(false);
  const [checkedRecipient, setCheckedRecipient] = useState(false);
  const [reviewBusy, setReviewBusy] = useState(false);
  const [reviewError, setReviewError] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [reminderOpen, setReminderOpen] = useState(false);
  const [reminders, setReminders] = useState<ReminderSchedule[]>([]);
  const [newReminder, setNewReminder] = useState(1440);
  const [reminderBusy, setReminderBusy] = useState(false);
  const [reminderError, setReminderError] = useState('');
  const reminderRequest = useCallback(async (method = 'GET', path = '', payload?: object): Promise<ReminderSchedule[]> => {
    const token = await getAccessToken();
    const response = await fetch(`${apiBaseUrl}/admin/reminder-schedules${path}`, {
      method, headers: { Authorization: `Bearer ${token}`, ...(payload ? { 'Content-Type': 'application/json' } : {}) },
      ...(payload ? { body: JSON.stringify(payload) } : {}),
    });
    const body = await response.json();
    if (!response.ok) throw new Error(apiErrorMessage(body, response.status));
    return (normalizeNumericIds(body.data) as ReminderSchedule[]).map(row => ({ ...row, minutes_before: Number(row.minutes_before) }));
  }, [getAccessToken]);
  const openReminders = async () => {
    setReminderOpen(true); setReminderBusy(true); setReminderError('');
    try { setReminders(await reminderRequest()); }
    catch (cause) { setReminderError(cause instanceof Error ? cause.message : t('Unable to load reminder settings.')); }
    finally { setReminderBusy(false); }
  };
  const changeReminders = async (method: string, path: string, payload: object) => {
    setReminderBusy(true); setReminderError('');
    try { setReminders(await reminderRequest(method, path, payload)); void load(); }
    catch (cause) { setReminderError(cause instanceof Error ? cause.message : t('Unable to save reminder settings.')); }
    finally { setReminderBusy(false); }
  };
  const load = useCallback(async () => {
    setLoading(true); setError('');
    try {
      const token = await getAccessToken();
      const params = new URLSearchParams({ page: String(page) });
      if (status) params.set('status', status);
      if (channel) params.set('channel', channel);
      if (period) params.set('period', period);
      const response = await fetch(`${apiBaseUrl}/admin/notifications?${params}`, { headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status));
      setData(normalizeNumericIds(body.data));
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to load notification status.')); }
    finally { setLoading(false); }
  }, [getAccessToken, page, status, channel, period, t]);
  useEffect(() => { void load(); }, [load]);
  const totalPages = Math.max(1, Math.ceil((data?.total ?? 0) / (data?.page_size ?? 25)));
  const openEvent = (item: Event) => { setSelected(item); setReviewOutcome('no_longer_needed'); setCheckedProvider(false); setCheckedRecipient(false); setReviewError(''); };
  const review = async (decision: 'resolve' | 'retry') => {
    if (!selected) return;
    setReviewBusy(true); setReviewError('');
    try {
      const token = await getAccessToken();
      const response = await fetch(`${apiBaseUrl}/admin/notifications/${selected.id}/review`, {
        method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ decision, outcome: decision === 'retry' ? 'provider_not_sent' : reviewOutcome, checked_provider_history: checkedProvider, checked_recipient: checkedRecipient }),
      });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status));
      setSelected(null); void load();
    } catch (cause) { setReviewError(cause instanceof Error ? cause.message : t('Unable to save notification review.')); }
    finally { setReviewBusy(false); }
  };
  const retryAge = selected ? Date.now() - Date.parse(`${selected.scheduled_at.replace(' ', 'T')}Z`) : Number.POSITIVE_INFINITY;
  const retrySmsAvailable = selected?.status === 'needs_review' && selected.channel === 'sms' && selected.event_code.startsWith('staff_')
    && selected.attempt_count < 5 && retryAge >= 0 && retryAge < 3_600_000;
  const availableReminderTimes = reminderTimes.filter(minutes => !reminders.some(row => row.minutes_before === minutes));
  const selectedReminderTime = availableReminderTimes.includes(newReminder as typeof reminderTimes[number]) ? newReminder : availableReminderTimes[0] ?? 0;
  return <Stack spacing={2}>
    <Alert severity="info">{t('Notification status reflects the queue and provider acceptance, not proof of delivery. Items needing review are never resent automatically.')}</Alert>
    <NotificationHealthBanner health={data?.health} />
    {error && <Alert severity="error">{error}</Alert>}
    <Paper variant="outlined"><Stack direction={{ xs: 'column', sm: 'row' }} gap={1.5} alignItems={{ sm: 'center' }} p={1.5}>
      <TextField select size="small" label={t('Status')} value={status} onChange={event => setFilter('status', event.target.value)} sx={{ minWidth: 220 }}>
        <MenuItem value="">{t('All statuses')}</MenuItem>{statuses.map(item => <MenuItem value={item} key={item}>{t(statusLabel[item])} ({data?.counts[item] ?? 0})</MenuItem>)}
      </TextField>
      <TextField select size="small" label={t('Channel')} value={channel} onChange={event => setFilter('channel', event.target.value)} sx={{ minWidth: 150 }}>
        <MenuItem value="">{t('All channels')}</MenuItem><MenuItem value="email">{t('Email')}</MenuItem><MenuItem value="sms">SMS</MenuItem>
      </TextField>
      <TextField select size="small" label={t('Scheduled date')} value={period} onChange={event => setFilter('period', event.target.value)} sx={{ minWidth: 170 }}>
        <MenuItem value="">{t('All dates')}</MenuItem><MenuItem value="today">{t('Today')}</MenuItem><MenuItem value="last7">{t('Last 7 days')}</MenuItem><MenuItem value="week">{t('This calendar week')}</MenuItem>
      </TextField>
      <Button startIcon={<RefreshCw size={17} />} onClick={() => void load()} disabled={loading}>{t('Refresh')}</Button>
      <Button variant="outlined" onClick={() => void openReminders()}>{t('Reminder settings')}</Button>
      <Typography color="text.secondary" sx={{ ml: { sm: 'auto' } }}>{t('{{count}} notifications', { count: data?.total ?? 0 })}</Typography>
    </Stack></Paper>
    <Paper variant="outlined">
      {loading && !data ? <Typography p={3} role="status">{t('Loading notification status…')}</Typography> : !data?.items.length ? <Typography p={3}>{t('No notifications match this status.')}</Typography> : <List disablePadding aria-label={t('Notifications')}>
        {data.items.map(item => <ListItemButton divider key={item.id} onClick={() => openEvent(item)} sx={{ alignItems: 'flex-start', gap: 2 }}>
          <ListItemText primary={<Stack direction="row" gap={1} alignItems="center" flexWrap="wrap"><Typography fontWeight={700}>#{item.id} · {t(eventLabel[item.event_code] ?? item.event_code)}</Typography><Chip size="small" label={item.channel === 'sms' ? 'SMS' : t('Email')} /><Chip size="small" color={item.status === 'needs_review' ? 'warning' : item.status === 'failed' ? 'error' : item.status === 'sent' || item.status === 'delivered' ? 'success' : 'default'} label={t(statusLabel[item.status])} /></Stack>} secondary={`${item.recipient_address} · ${time(item.scheduled_at)}`} />
        </ListItemButton>)}
      </List>}
    </Paper>
    <Stack direction="row" justifyContent="flex-end" alignItems="center" gap={1}><Button disabled={page <= 1 || loading} onClick={() => setPage(page - 1)}>{t('Previous')}</Button><Typography>{t('Page {{page}} of {{total}}', { page, total: totalPages })}</Typography><Button disabled={page >= totalPages || loading} onClick={() => setPage(page + 1)}>{t('Next')}</Button></Stack>
    <Drawer anchor="right" open={selected !== null} onClose={() => setSelected(null)}><Box sx={{ width: { xs: '100vw', sm: 440 }, p: 3 }}><Stack spacing={2}>
      <Stack direction="row" justifyContent="space-between" alignItems="center"><Typography variant="h5">{t('Notification #{{id}}', { id: selected?.id })}</Typography><Button onClick={() => setSelected(null)}>{t('Close')}</Button></Stack><Divider />
      {selected && <><Chip sx={{ alignSelf: 'flex-start' }} label={t(statusLabel[selected.status])} color={selected.status === 'needs_review' ? 'warning' : selected.status === 'failed' ? 'error' : 'default'} />
        {selected.status === 'needs_review' && <Alert severity="warning">{t('Delivery outcome may be unknown. Check provider history and the recipient before choosing a recovery action.')}</Alert>}
        <Typography><strong>{t('Appointment ID')}:</strong> {selected.appointment_id ?? '—'}</Typography>
        <Typography><strong>{t('Event')}:</strong> {t(eventLabel[selected.event_code] ?? selected.event_code)}</Typography>
        <Typography><strong>{t('Channel')}:</strong> {selected.channel === 'sms' ? 'SMS' : t('Email')}</Typography>
        <Typography><strong>{t('Recipient')}:</strong> {selected.recipient_address}</Typography>
        <Typography><strong>{t('Scheduled')}:</strong> {time(selected.scheduled_at)}</Typography>
        <Typography><strong>{t('Next attempt')}:</strong> {time(selected.next_attempt_at)}</Typography>
        <Typography><strong>{t('Provider accepted')}:</strong> {time(selected.sent_at)}</Typography>
        <Typography><strong>{t('Attempts')}:</strong> {selected.attempt_count}</Typography>
        {selected.last_error && <Alert severity="error">{selected.last_error}</Alert>}
        {selected.review_outcome && <Typography><strong>{t('Review outcome')}:</strong> {t(outcomeLabel[selected.review_outcome] ?? selected.review_outcome)}</Typography>}
        {selected.status === 'needs_review' && <Stack spacing={1.5}>
          <Divider />
          <Typography variant="h6">{t('Review this notification')}</Typography>
          <FormControlLabel control={<Checkbox checked={checkedProvider} onChange={event => setCheckedProvider(event.target.checked)} />} label={t('I checked provider message history.')} />
          <FormControlLabel control={<Checkbox checked={checkedRecipient} onChange={event => setCheckedRecipient(event.target.checked)} />} label={t('I checked whether the recipient received the notice.')} />
          <TextField select size="small" label={t('Review outcome')} value={reviewOutcome} onChange={event => setReviewOutcome(event.target.value as ReviewOutcome)}>
            {(['provider_accepted','handled_manually','no_longer_needed'] as const).map(value => <MenuItem key={value} value={value}>{t(outcomeLabel[value])}</MenuItem>)}
          </TextField>
          {reviewError && <Alert severity="error">{reviewError}</Alert>}
          <Button variant="outlined" disabled={reviewBusy || !checkedProvider || !checkedRecipient} onClick={() => void review('resolve')}>{t('Close after review')}</Button>
          {retrySmsAvailable ? <Button color="warning" variant="contained" disabled={reviewBusy || !checkedProvider || !checkedRecipient} onClick={() => void review('retry')}>{t('Retry recent SMS — confirmed not sent')}</Button>
            : <Typography variant="body2" color="text.secondary">{t('Only recent staff SMS can be retried here. Handle older notices and email manually.')}</Typography>}
        </Stack>}
      </>}
    </Stack></Box></Drawer>
    <Drawer anchor="right" open={reminderOpen} onClose={() => setReminderOpen(false)}><Box sx={{ width: { xs: '100vw', sm: 440 }, p: 3 }}><Stack spacing={2}>
      <Stack direction="row" justifyContent="space-between" alignItems="center"><Typography variant="h5">{t('Email reminder settings')}</Typography><Button onClick={() => setReminderOpen(false)}>{t('Close')}</Button></Stack>
      <Divider />
      <Alert severity="info">{t('New reminder times apply to future bookings. Disabling a time cancels its pending emails; enabling it again does not recreate them.')}</Alert>
      {reminderError && <Alert severity="error">{reminderError}</Alert>}
      {reminders.map(row => <FormControlLabel key={row.id} control={<Switch checked={reminderActive(row)} disabled={reminderBusy} onChange={event => void changeReminders('PATCH', `/${row.id}`, { active: event.target.checked })} />} label={t(reminderLabel[row.minutes_before] ?? `${row.minutes_before} minutes before`)} />)}
      {!reminders.length && !reminderBusy && <Typography color="text.secondary">{t('No email reminders configured.')}</Typography>}
      <Divider />
      <Typography fontWeight={700}>{t('Add reminder time')}</Typography>
      <TextField select label={t('Send before appointment')} size="small" value={selectedReminderTime} disabled={!availableReminderTimes.length} onChange={event => setNewReminder(Number(event.target.value))}>
        {availableReminderTimes.map(minutes => <MenuItem key={minutes} value={minutes}>{t(reminderLabel[minutes])}</MenuItem>)}
      </TextField>
      <Button variant="contained" disabled={reminderBusy || !selectedReminderTime || reminders.filter(reminderActive).length >= 3} onClick={() => void changeReminders('POST', '', { minutes_before: selectedReminderTime })}>{t('Add email reminder')}</Button>
      <Typography variant="body2" color="text.secondary">{t('Up to three reminder times can be active. These are operational emails, not SMS.')}</Typography>
    </Stack></Box></Drawer>
  </Stack>;
}

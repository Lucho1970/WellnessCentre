import { useEffect, useRef, useState } from 'react';
import { Alert, Button, CircularProgress, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { formatCad, formatDateTime } from '../i18n/format';
import { useUnsavedChanges } from '../shared/UnsavedChanges';
import { readSeriesResult, SeriesReview, type SeriesRequest, type SeriesResult } from './RecurringBooking';

type Occurrence = { duration_option_id: number; appointment_id: number; version: number; starts_at: string; room_id: number | null; cancellation_fee_cents: number };
type Series = { series_id: number; timezone: string; items: Occurrence[] };
type Slot = { duration_option_id: number; starts_at: string; available_room_ids: number[] };

export function ManageRecurringSeries({ id, request, close, complete, client = false, onLockedChange }: { onLockedChange?: (locked: boolean) => void; id: number; request: SeriesRequest; close: () => void; complete: (message: string) => void; client?: boolean }) {
  const { t, i18n } = useTranslation();
  const [series, setSeries] = useState<Series | null>(null), [action, setAction] = useState('cancel'), [reason, setReason] = useState('');
  const [starts, setStarts] = useState<Record<number, string>>({}), [result, setResult] = useState<SeriesResult | null>(null), [error, setError] = useState(''), [busy, setBusy] = useState(false), [uncertain, setUncertain] = useState(false);
  const [reload, setReload] = useState(0);
  const key = useRef(crypto.randomUUID()), sending = useRef(false);
  useUnsavedChanges(Boolean(result || reason || Object.keys(starts).length));
  useEffect(() => { let active = true; void request(`/recurring-series/${id}`).then(data => { if (active) setSeries(data); }).catch(cause => { if (active) setError(cause.message); }); return () => { active = false; }; }, [id, request, reload]);
  useEffect(() => { setResult(null); key.current = crypto.randomUUID(); }, [action, reason, starts]);
  const send = async (apply: boolean) => {
    if (!series || sending.current) return;
    sending.current = true; setBusy(true); setError('');
    try {
      const response = readSeriesResult(await request(`/recurring-series/${id}${apply ? '' : '/preview'}`, { method: 'POST', body: JSON.stringify({ action, reason, idempotency_key: key.current, items: series.items.map(item => ({ appointment_id: item.appointment_id, version: item.version, ...(action === 'cancel' ? { expected_cancellation_fee_cents: item.cancellation_fee_cents } : { starts_at: starts[item.appointment_id] }) })), ...(apply ? { preview_token: result?.preview_token } : {}) }) }));
      setResult(response); setUncertain(false);
    } catch (cause) {
      const status = (cause as { status?: number }).status;
      setUncertain(apply && (!status || status >= 500));
      if (status && status >= 400 && status < 500) { setResult(null); setSeries(null); }
      setError(cause instanceof Error ? cause.message : t('Unable to save the series.'));
    } finally { sending.current = false; setBusy(false); }
  };
  const locked = busy || uncertain || Boolean(result?.applied);
  useEffect(() => { onLockedChange?.(locked); }, [locked, onLockedChange]);
  useEffect(() => () => onLockedChange?.(false), [onLockedChange]);
  return <Paper variant="outlined" sx={{ p: 3 }}><Stack spacing={2}>
    <Typography variant="h5">{t('Manage future series')} #{id}</Typography>
    <Alert severity="info">{t('Only the future active appointments listed here will change. Past and canceled appointments stay in history. All listed changes must pass validation before any are saved.')}</Alert>
    {error && <Alert severity="error">{error}</Alert>}
    {!series && error && <Button onClick={() => { setError(''); setResult(null); setStarts({}); setReload(value => value + 1); }}>{t('Reload series')}</Button>}
    {!series && !error && <CircularProgress />}
    {series && <>
      <Typography>{t('Location timezone')}: {series.timezone}</Typography>
      <TextField select label={t('Series action')} value={action} disabled={locked} onChange={event => setAction(event.target.value)}><MenuItem value="cancel">{t('Cancel all listed appointments')}</MenuItem><MenuItem value="reschedule">{t('Reschedule all listed appointments')}</MenuItem></TextField>
      {action === 'cancel' && <Alert severity="warning">{t(client ? 'Review the cancellation fee for each appointment before confirming.' : 'Canceling this series is clinic-initiated. No client cancellation fees will be charged.')}</Alert>}
      {series.items.map(item => <Paper key={item.appointment_id} variant="outlined" sx={{ p: 2 }}><Stack spacing={1}>
        <Typography>#{item.appointment_id} · {formatDateTime(`${item.starts_at.replace(' ', 'T')}Z`, i18n.resolvedLanguage, { timeZone: series.timezone, dateStyle: 'medium', timeStyle: 'short' })}</Typography>
        {action === 'cancel' ? <Typography>{t('Cancellation fee')}: {formatCad(client ? item.cancellation_fee_cents : 0, i18n.resolvedLanguage)}</Typography> : <OccurrenceTime item={item} request={request} timezone={series.timezone} disabled={locked} chosen={starts[item.appointment_id] ?? ''} choose={value => setStarts(current => ({ ...current, [item.appointment_id]: value }))} />}
      </Stack></Paper>)}
      <TextField label={t('Reason or note (optional)')} value={reason} disabled={locked} inputProps={{ maxLength: 1000 }} onChange={event => setReason(event.target.value)} />
      {uncertain && <Alert severity="warning">{t('The response could not be verified. Retry this same confirmation before starting another series.')}</Alert>}
      {result && <SeriesReview result={result} />}
      {result?.applied ? <Button variant="contained" onClick={() => complete(t('All {{count}} future appointments in series #{{id}} were updated.', { count: result.items.length, id }))}>{t('Done')}</Button> : <>
        <Button disabled={locked || (action === 'reschedule' && series.items.some(item => !starts[item.appointment_id]))} onClick={() => void send(false)}>{t('Preview series changes')}</Button>
        {result?.ready && <Button variant="contained" disabled={busy} onClick={() => void send(true)}>{t(uncertain ? 'Retry series confirmation' : 'Confirm entire series')}</Button>}
      </>}
    </>}
    <Button disabled={locked} onClick={close}>{t('Close')}</Button>
  </Stack></Paper>;
}

function OccurrenceTime({ item, request, timezone, disabled, chosen, choose }: { item: Occurrence; request: SeriesRequest; timezone: string; disabled: boolean; chosen: string; choose: (value: string) => void }) {
  const { t, i18n } = useTranslation();
  const [date, setDate] = useState(''), [slots, setSlots] = useState<Slot[]>([]), [error, setError] = useState(''), [busy, setBusy] = useState(false);
  const search = async () => {
    setBusy(true); setError('');
    try {
      const data = await request(`/appointments/${item.appointment_id}/availability?date_from=${date}&date_to=${date}`);
      // Match the existing duration and room; series changes preserve their booking terms.
      const appointment = item.duration_option_id;
      setSlots(data.availability.filter((slot: Slot) => Number(slot.duration_option_id) === Number(appointment) && (item.room_id === null || slot.available_room_ids.map(Number).includes(item.room_id))));
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to load times.')); }
    finally { setBusy(false); }
  };
  return <Stack spacing={1}>
    <TextField type="date" label={t('Appointment date')} InputLabelProps={{ shrink: true }} value={date} disabled={disabled || busy} onChange={event => { setDate(event.target.value); setSlots([]); choose(''); }} />
    <Button disabled={disabled || busy || !date} onClick={() => void search()}>{t('Find times')}</Button>
    {error && <Alert severity="error">{error}</Alert>}
    <TextField select label={t('New appointment time')} disabled={disabled || busy} value={chosen} onChange={event => choose(event.target.value)}><MenuItem value="">{t('Choose a time')}</MenuItem>{slots.map(slot => <MenuItem key={slot.starts_at} value={slot.starts_at}>{formatDateTime(slot.starts_at, i18n.resolvedLanguage, { timeZone: timezone, dateStyle: 'medium', timeStyle: 'short' })}</MenuItem>)}</TextField>
  </Stack>;
}

import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Alert, Button, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { formatCad, formatDateTime } from '../i18n/format';
import i18n from '../i18n';

export type SeriesRequest = (path: string, init?: RequestInit) => Promise<any>;
type Item = { appointment_id?: number; starts_at: string | null; local_time?: string; ok: boolean; message?: string; subtotal_cents?: number; fee_cents?: number };
export type SeriesResult = { series_id: number | null; timezone: string; ready: boolean; applied: boolean; preview_token: string; items: Item[] };

export function readSeriesResult(value: unknown): SeriesResult {
  const result = value as SeriesResult | null;
  const valid = result && typeof result.ready === 'boolean' && typeof result.applied === 'boolean' && typeof result.timezone === 'string' && result.timezone.length > 0 && typeof result.preview_token === 'string' && result.preview_token.length > 0 && Array.isArray(result.items) && result.items.length > 0 && result.items.length <= 26 && result.items.every(item => item && typeof item.ok === 'boolean' && (typeof item.starts_at === 'string' && Number.isFinite(Date.parse(item.starts_at.includes('T') ? item.starts_at : `${item.starts_at.replace(' ', 'T')}Z`)) || item.starts_at === null && typeof item.local_time === 'string') && (item.ok || typeof item.message === 'string') && (!result.applied || Number.isSafeInteger(item.appointment_id) && Number(item.appointment_id) > 0)) && result.ready === result.items.every(item => item.ok) && (!result.applied || result.ready && Number.isSafeInteger(result.series_id) && Number(result.series_id) > 0);
  if (!valid) throw new Error(i18n.t('The server returned an incomplete series response. Retry the same request.'));
  return result;
}

export function SeriesReview({ result }: { result: SeriesResult }) {
  const { t, i18n } = useTranslation();
  return <Stack spacing={1}>
    <Alert severity={result.applied ? 'success' : result.ready ? 'info' : 'warning'}>{t(result.applied ? 'All appointments in this review were saved.' : result.ready ? 'All dates passed validation. Confirm to save the entire series.' : 'Nothing was saved. Resolve the conflicts or choose a different pattern.')}</Alert>
    <Typography>{t('Location timezone')}: {result.timezone}</Typography>
    {result.items.map((item, index) => <Paper variant="outlined" sx={{ p: 1.5 }} key={index}>
      <Typography>{item.starts_at ? formatDateTime(item.starts_at.includes('T') ? item.starts_at : `${item.starts_at.replace(' ', 'T')}Z`, i18n.resolvedLanguage, { timeZone: result.timezone, dateStyle: 'medium', timeStyle: 'short' }) : item.local_time}{result.applied && item.appointment_id ? ` · #${item.appointment_id}` : ''}</Typography>
      {!item.ok && <Alert severity="error">{item.message}</Alert>}
      {item.subtotal_cents !== undefined && <Typography>{t('Subtotal before tax')}: {formatCad(item.subtotal_cents, i18n.resolvedLanguage)}</Typography>}
      {item.fee_cents !== undefined && <Typography>{t('Cancellation fee')}: {formatCad(item.fee_cents, i18n.resolvedLanguage)}</Typography>}
    </Paper>)}
  </Stack>;
}

export function RecurringBooking({ payload, request, single, complete, setBusy }: { payload: Record<string, unknown>; request: SeriesRequest; single: ReactNode; complete: (id: number, message?: string) => void; setBusy: (busy: boolean) => void }) {
  const { t } = useTranslation();
  const [frequency, setFrequency] = useState('single'), [count, setCount] = useState('6'), [endMode, setEndMode] = useState('count'), [until, setUntil] = useState('');
  const [result, setResult] = useState<SeriesResult | null>(null), [error, setError] = useState(''), [busy, localBusy] = useState(false), [uncertain, setUncertain] = useState(false);
  const key = useRef(crypto.randomUUID()), sending = useRef(false);
  const serialized = JSON.stringify(payload);
  useEffect(() => { setResult(null); setError(''); key.current = crypto.randomUUID(); }, [serialized, frequency, count, endMode, until]);
  const send = async (apply: boolean) => {
    let hold = false;
    if (sending.current) return;
    sending.current = true; localBusy(true); setBusy(true); setError('');
    try {
      const response = readSeriesResult(await request(`/recurring-series${apply ? '' : '/preview'}`, { method: 'POST', body: JSON.stringify({ ...payload, idempotency_key: key.current, recurrence: { frequency, ...(endMode === 'count' ? { count: Number(count) } : { until }) }, ...(apply ? { preview_token: result?.preview_token } : {}) }) }));
      setResult(response); setUncertain(false); hold = response.applied;
    } catch (cause) {
      // Keep the same request and key for an ambiguous response; edits would risk duplicates.
      const status = (cause as { status?: number }).status;
      hold = apply && (!status || status >= 500); setUncertain(hold);
      if (status && status >= 400 && status < 500) setResult(null);
      setError(cause instanceof Error ? cause.message : t('Unable to save the series.'));
    } finally { sending.current = false; localBusy(false); setBusy(hold); }
  };
  const locked = busy || uncertain || Boolean(result?.applied);
  return <Stack spacing={2}>
    <TextField select label={t('Repeat appointment')} value={frequency} disabled={locked} onChange={event => setFrequency(event.target.value)}>{['single', 'weekly', 'biweekly', 'monthly'].map(value => <MenuItem key={value} value={value}>{t(({ single: 'One appointment', weekly: 'Weekly', biweekly: 'Every two weeks', monthly: 'Monthly' } as Record<string, string>)[value])}</MenuItem>)}</TextField>
    {frequency === 'single' ? single : <>
      <Alert severity="info">{t('The series keeps the same local time. Monthly dates use the last day when that day is missing. Every date must be available; nothing is saved if any date conflicts.')}</Alert>
      <TextField select label={t('Series ends')} disabled={locked} value={endMode} onChange={event => setEndMode(event.target.value)}><MenuItem value="count">{t('After a number of appointments')}</MenuItem><MenuItem value="date">{t('On an end date')}</MenuItem></TextField>
      {endMode === 'count' ? <TextField type="number" label={t('Number of appointments (including the first)')} inputProps={{ min: 2, max: 26 }} value={count} disabled={locked} onChange={event => setCount(event.target.value)} /> : <TextField type="date" label={t('Series end date')} InputLabelProps={{ shrink: true }} value={until} disabled={locked} onChange={event => setUntil(event.target.value)} />}
      {error && <Alert severity="error">{error}</Alert>}
      {uncertain && <Alert severity="warning">{t('The response could not be verified. Retry this same confirmation before starting another series.')}</Alert>}
      {result && <SeriesReview result={result} />}
      {result?.applied ? <Button variant="contained" onClick={() => complete(result.items[0].appointment_id!, t('Recurring series #{{id}} confirmed with {{count}} appointments.', { id: result.series_id, count: result.items.length }))}>{t('Done')}</Button> : <>
        <Button disabled={busy || uncertain} onClick={() => void send(false)}>{t('Preview all dates')}</Button>
        {result?.ready && <Button variant="contained" disabled={busy} onClick={() => void send(true)}>{t(uncertain ? 'Retry series confirmation' : 'Confirm entire series')}</Button>}
      </>}
    </>}
  </Stack>;
}

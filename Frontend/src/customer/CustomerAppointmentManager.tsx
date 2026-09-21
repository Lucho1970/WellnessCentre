import { useState, type FormEvent } from 'react';
import { Alert, Box, Button, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { formatDateTime } from '../i18n/format';
import { useUnsavedChanges } from '../shared/UnsavedChanges';
import { customerFetch } from './session';

export type CustomerAppointment = {
  id: number; starts_at: string; ends_at: string; status: string; version: number;
  room_id: number | null; room_name: string | null; duration_option_id: number;
  service: string; practitioner: string; location: string; timezone: string; delivery_mode: string;
};
type Slot = { duration_option_id: number; starts_at: string; ends_at: string; available_room_ids: number[] };
type Props = { appointment: CustomerAppointment; close: () => void; complete: (message: string) => void };

const databaseInstant = (value: string) => new Date(`${value.replace(' ', 'T')}Z`);
const localDate = (value: string, timezone: string) => new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(databaseInstant(value));
const today = (timezone: string) => new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());

export function CustomerAppointmentManager({ appointment, close, complete }: Props) {
  const { t, i18n } = useTranslation();
  const originalDate = localDate(appointment.starts_at, appointment.timezone);
  const [action, setAction] = useState<'details'|'reschedule'|'cancel'>('details');
  const [date, setDate] = useState(originalDate);
  const [slots, setSlots] = useState<Slot[]>([]);
  const [slot, setSlot] = useState<Slot | null>(null);
  const [room, setRoom] = useState(appointment.room_id ? String(appointment.room_id) : '');
  const [reason, setReason] = useState('');
  const [searched, setSearched] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const needsRoom = appointment.room_id !== null;
  const dirty = action !== 'details' && (date !== originalDate || slot !== null || reason.trim() !== '');
  useUnsavedChanges(dirty);
  const closeSafely = () => { if (!dirty || window.confirm(t('Discard your unsaved changes?'))) close(); };

  const display = (value: string, database = false) => formatDateTime(database ? `${value.replace(' ', 'T')}Z` : value, i18n.resolvedLanguage, { timeZone: appointment.timezone, dateStyle: 'medium', timeStyle: 'short' });
  const loadSlots = async (event: FormEvent) => {
    event.preventDefault(); setBusy(true); setError(''); setSlots([]); setSlot(null); setSearched(false);
    try {
      const data = await customerFetch(`/appointments/${appointment.id}/availability?date_from=${date}&date_to=${date}`);
      const currentStart = databaseInstant(appointment.starts_at).getTime();
      setSlots(data.availability.filter((item: Slot) => Number(item.duration_option_id) === Number(appointment.duration_option_id) && new Date(item.starts_at).getTime() !== currentStart));
      setSearched(true);
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to load times.')); }
    finally { setBusy(false); }
  };
  const submit = async () => {
    if (action === 'reschedule' && (!slot || (needsRoom && !room))) return;
    setBusy(true); setError('');
    try {
      const body = action === 'cancel'
        ? { action, version: appointment.version, reason }
        : { action, version: appointment.version, starts_at: slot!.starts_at, ...(needsRoom ? { room_id: Number(room) } : {}), reason };
      await customerFetch(`/appointments/${appointment.id}`, { method: 'PATCH', body: JSON.stringify(body) });
      complete(t(action === 'cancel' ? 'Appointment #{{id}} was canceled.' : 'Appointment #{{id}} was rescheduled.', { id: appointment.id }));
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to change the appointment.')); }
    finally { setBusy(false); }
  };

  return <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 } }}>
    <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" gap={2} mb={2}>
      <Box><Typography variant="h5">{t('Appointment #{{id}}', { id: appointment.id })}</Typography><Typography color="text.secondary">{appointment.service} · {appointment.practitioner}</Typography></Box>
      <Button disabled={busy} onClick={closeSafely}>{t('Close')}</Button>
    </Stack>
    {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
    {action === 'details' && <Stack spacing={2}>
      <Typography>{display(appointment.starts_at, true)} – {display(appointment.ends_at, true)}</Typography>
      <Typography>{appointment.delivery_mode === 'mobile' ? t('On-Site (client location)') : `${t('In clinic')} · ${appointment.location}`}{appointment.room_name ? ` · ${appointment.room_name}` : ''}</Typography>
      <Alert severity="info">{t('Cancellation charges are not calculated online yet. The clinic will contact you if its current cancellation policy applies.')}</Alert>
      <Stack direction={{ xs: 'column', sm: 'row' }} gap={2}><Button variant="contained" onClick={() => setAction('reschedule')}>{t('Reschedule')}</Button><Button color="error" variant="outlined" onClick={() => setAction('cancel')}>{t('Cancel appointment')}</Button></Stack>
    </Stack>}
    {action === 'reschedule' && <Stack spacing={2}>
      <Typography>{t('Choose a new available time. The service, practitioner, location, delivery mode, duration, and price remain unchanged.')}</Typography>
      <Stack component="form" direction={{ xs: 'column', sm: 'row' }} gap={2} onSubmit={loadSlots}><TextField required type="date" label={t('Appointment date')} value={date} disabled={busy} InputLabelProps={{ shrink: true }} inputProps={{ min: today(appointment.timezone) }} onChange={event => { setDate(event.target.value); setSlots([]); setSlot(null); setSearched(false); }} /><Button type="submit" variant="outlined" disabled={busy || !date}>{t(busy ? 'Searching…' : 'Find times')}</Button></Stack>
      {searched && slots.length === 0 && <Alert severity="info">{t('No bookable times on this day. Try another day.')}</Alert>}
      <Stack direction="row" flexWrap="wrap" gap={1}>{slots.map(item => <Button key={item.starts_at} variant={slot?.starts_at === item.starts_at ? 'contained' : 'outlined'} onClick={() => { setSlot(item); setRoom(item.available_room_ids.length === 1 ? String(item.available_room_ids[0]) : ''); }}>{display(item.starts_at)}</Button>)}</Stack>
      {slot && needsRoom && <TextField select required label={t('Available room')} value={room} onChange={event => setRoom(event.target.value)}>{slot.available_room_ids.map(id => <MenuItem key={id} value={String(id)}>{id === appointment.room_id && appointment.room_name ? appointment.room_name : t('Room {{number}}', { number: id })}</MenuItem>)}</TextField>}
      <TextField label={t('Reason or note (optional)')} value={reason} multiline minRows={2} inputProps={{ maxLength: 1000 }} onChange={event => setReason(event.target.value)} />
      <Stack direction="row" gap={2}><Button disabled={busy} onClick={() => setAction('details')}>{t('Back')}</Button><Button variant="contained" disabled={busy || !slot || (needsRoom && !room)} onClick={() => void submit()}>{t(busy ? 'Saving…' : 'Confirm reschedule')}</Button></Stack>
    </Stack>}
    {action === 'cancel' && <Stack spacing={2}>
      <Alert severity="warning">{t('Canceling releases the appointment time. The canceled appointment remains in your history.')}</Alert>
      <Alert severity="info">{t('Cancellation charges are not calculated online yet. The clinic will contact you if its current cancellation policy applies.')}</Alert>
      <TextField label={t('Cancellation reason (optional)')} value={reason} multiline minRows={2} inputProps={{ maxLength: 1000 }} onChange={event => setReason(event.target.value)} />
      <Stack direction="row" gap={2}><Button disabled={busy} onClick={() => setAction('details')}>{t('Back')}</Button><Button color="error" variant="contained" disabled={busy} onClick={() => void submit()}>{t(busy ? 'Saving…' : 'Confirm cancellation')}</Button></Stack>
    </Stack>}
  </Paper>;
}

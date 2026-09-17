import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Alert, Button, FormControlLabel, Grid, IconButton, Paper, Stack, Switch, TextField, Typography } from '@mui/material';
import { Pencil, Plus, Save, Trash2, X } from 'lucide-react';
import { useStaffAuth } from '../auth/AuthProvider';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type DurationOption = { minutes: number; price_cents: number };
type DurationForm = { key: string; minutes: string; price: string };
type Service = {
  id: number; name: string; description: string | null; preparation_instructions: string | null;
  price_cents: number; durations: number[]; duration_options?: DurationOption[];
  lead_time_minutes: number; booking_horizon_days: number; buffer_before_minutes: number;
  buffer_after_minutes: number; requires_room: number | boolean; recurrence_allowed: number | boolean;
  active: number | boolean;
};
type Form = {
  name: string; description: string; preparation_instructions: string; duration_options: DurationForm[];
  lead_time_minutes: string; booking_horizon_days: string; buffer_before_minutes: string;
  buffer_after_minutes: string; requires_room: boolean; recurrence_allowed: boolean; active: boolean;
};
const duration = (minutes = '60', price = ''): DurationForm => ({ key: crypto.randomUUID(), minutes, price });
const blank = (): Form => ({ name: '', description: '', preparation_instructions: '', duration_options: [duration()], lead_time_minutes: '0', booking_horizon_days: '365', buffer_before_minutes: '0', buffer_after_minutes: '0', requires_room: true, recurrence_allowed: false, active: true });
const money = (cents: number) => new Intl.NumberFormat('en-CA', { style: 'currency', currency: 'CAD' }).format(cents / 100);

export function ServiceAdmin({ onSaved }: { onSaved?: () => void }) {
  const { getAccessToken } = useStaffAuth();
  const [items, setItems] = useState<Service[]>([]), [form, setForm] = useState<Form>(() => blank());
  const [editing, setEditing] = useState<number | null>(null), [busy, setBusy] = useState(false);
  const [error, setError] = useState(''), [saved, setSaved] = useState('');
  const load = useCallback(async () => {
    setBusy(true);
    try {
      const token = await getAccessToken();
      const response = await fetch(`${api}/admin/services`, { headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json();
      if (!response.ok) throw new Error(body?.error?.message ?? 'Unable to load services.');
      setItems(body.data);
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Unable to load services.'); }
    finally { setBusy(false); }
  }, [getAccessToken]);
  useEffect(() => { void load(); }, [load]);
  const field = <K extends keyof Form>(key: K, value: Form[K]) => setForm(current => ({ ...current, [key]: value }));
  const reset = () => { setEditing(null); setForm(blank()); };
  const options = (service: Service): DurationOption[] => service.duration_options?.length ? service.duration_options : service.durations.map(minutes => ({ minutes, price_cents: Number(service.price_cents) }));
  const edit = (service: Service) => {
    setEditing(service.id);
    setForm({
      name: service.name, description: service.description ?? '', preparation_instructions: service.preparation_instructions ?? '',
      duration_options: options(service).map(option => duration(String(option.minutes), (Number(option.price_cents) / 100).toFixed(2))),
      lead_time_minutes: String(service.lead_time_minutes), booking_horizon_days: String(service.booking_horizon_days),
      buffer_before_minutes: String(service.buffer_before_minutes), buffer_after_minutes: String(service.buffer_after_minutes),
      requires_room: Boolean(Number(service.requires_room)), recurrence_allowed: Boolean(Number(service.recurrence_allowed)), active: Boolean(Number(service.active)),
    });
    setError(''); setSaved('');
  };
  const changeDuration = (key: string, property: 'minutes' | 'price', value: string) => field('duration_options', form.duration_options.map(option => option.key === key ? { ...option, [property]: value } : option));
  const removeDuration = (key: string) => field('duration_options', form.duration_options.filter(option => option.key !== key));
  const submit = async (event: FormEvent) => {
    event.preventDefault(); setBusy(true); setError(''); setSaved('');
    try {
      const durationOptions = form.duration_options.map(option => ({ minutes: Number(option.minutes), price_cents: Math.round(Number(option.price) * 100) }));
      const payload = { ...form, duration_options: durationOptions, price_cents: Math.min(...durationOptions.map(option => option.price_cents)), durations: durationOptions.map(option => option.minutes), lead_time_minutes: Number(form.lead_time_minutes), booking_horizon_days: Number(form.booking_horizon_days), buffer_before_minutes: Number(form.buffer_before_minutes), buffer_after_minutes: Number(form.buffer_after_minutes) };
      const token = await getAccessToken();
      const response = await fetch(editing ? `${api}/admin/services/${editing}` : `${api}/admin/services`, { method: editing ? 'PATCH' : 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
      const body = await response.json();
      if (!response.ok) throw new Error(body?.error?.message ?? 'Unable to save service.');
      const message = `${form.name} was ${editing ? 'updated' : 'created'}.`;
      reset(); onSaved?.(); await load(); setSaved(message);
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Unable to save service.'); }
    finally { setBusy(false); }
  };
  return <Stack spacing={3}>
    <Paper component="form" onSubmit={submit} variant="outlined" sx={{ p: 3 }}>
      <Stack direction="row" justifyContent="space-between">
        <span><Typography variant="h5">{editing ? 'Edit service' : 'Add a service'}</Typography><Typography color="text.secondary" mb={2}>Define one service with one or more duration-and-price choices. Travel fees and buffers are configured separately.</Typography></span>
        {editing && <Button startIcon={<X size={16} />} onClick={reset}>Cancel</Button>}
      </Stack>
      <Grid container spacing={2}>
        <Grid size={12}><TextField required fullWidth label="Service name" value={form.name} onChange={event => field('name', event.target.value)} inputProps={{ maxLength: 150 }} /></Grid>
        <Grid size={12}><TextField fullWidth multiline label="Description" value={form.description} onChange={event => field('description', event.target.value)} /></Grid>
        <Grid size={12}><Typography variant="subtitle1" fontWeight={700}>Duration and price options</Typography><Typography variant="body2" color="text.secondary">Prices are explicit for each duration. Appointments keep a snapshot of the selected price.</Typography></Grid>
        {form.duration_options.map((option, index) => <Grid size={12} key={option.key}><Stack direction="row" spacing={2} alignItems="center">
          <TextField required fullWidth type="number" label={`Duration ${index + 1} (minutes)`} value={option.minutes} onChange={event => changeDuration(option.key, 'minutes', event.target.value)} inputProps={{ min: 15, max: 480, step: 15 }} />
          <TextField required fullWidth type="number" label={`Price ${index + 1} (CAD)`} value={option.price} onChange={event => changeDuration(option.key, 'price', event.target.value)} inputProps={{ min: 0, step: .01 }} />
          <IconButton aria-label={`Remove duration ${index + 1}`} disabled={form.duration_options.length === 1} onClick={() => removeDuration(option.key)}><Trash2 size={18} /></IconButton>
        </Stack></Grid>)}
        <Grid size={12}><Button startIcon={<Plus size={16} />} onClick={() => field('duration_options', [...form.duration_options, duration()])}>Add duration and price</Button></Grid>
        {(['lead_time_minutes', 'booking_horizon_days', 'buffer_before_minutes', 'buffer_after_minutes'] as const).map(key => <Grid size={{ xs: 6, md: 3 }} key={key}><TextField required fullWidth type="number" label={key.replaceAll('_', ' ')} value={form[key]} onChange={event => field(key, event.target.value)} /></Grid>)}
        <Grid size={12}><TextField fullWidth multiline label="Preparation instructions" value={form.preparation_instructions} onChange={event => field('preparation_instructions', event.target.value)} /></Grid>
        <Grid size={12}><Stack direction={{ xs: 'column', sm: 'row' }}><FormControlLabel control={<Switch checked={form.requires_room} onChange={event => field('requires_room', event.target.checked)} />} label="Requires a room" /><FormControlLabel control={<Switch checked={form.recurrence_allowed} onChange={event => field('recurrence_allowed', event.target.checked)} />} label="Recurring bookings" />{editing && <FormControlLabel control={<Switch checked={form.active} onChange={event => field('active', event.target.checked)} />} label="Active" />}</Stack></Grid>
      </Grid>
      {error && <Alert severity="error" sx={{ mt: 2 }}>{error}</Alert>}{saved && <Alert severity="success" sx={{ mt: 2 }}>{saved}</Alert>}
      <Button type="submit" variant="contained" disabled={busy} startIcon={editing ? <Save size={17} /> : <Plus size={17} />} sx={{ mt: 2 }}>{busy ? 'Saving…' : editing ? 'Save changes' : 'Add service'}</Button>
    </Paper>
    <Paper variant="outlined" sx={{ p: 3 }}><Typography variant="h5" mb={2}>Services</Typography><Stack spacing={1}>{items.map(service => <Stack key={service.id} direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'center' }} sx={{ p: 2, border: '1px solid', borderColor: 'divider', borderRadius: 2 }}>
      <span><Typography fontWeight={700}>{service.name} · from {money(Math.min(...options(service).map(option => option.price_cents)))}</Typography><Typography variant="body2" color="text.secondary">{options(service).map(option => `${option.minutes} min — ${money(option.price_cents)}`).join(' · ')} · {Boolean(Number(service.active)) ? 'Active' : 'Inactive'} · {Boolean(Number(service.requires_room)) ? 'Room required' : 'No room required'}</Typography></span>
      <Button startIcon={<Pencil size={16} />} onClick={() => edit(service)}>Edit</Button>
    </Stack>)}</Stack></Paper>
  </Stack>;
}

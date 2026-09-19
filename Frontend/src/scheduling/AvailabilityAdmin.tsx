import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Alert, Button, Grid, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { useUnsavedForm } from '../shared/UnsavedChanges';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
type Named = { id: number; practitioner_id?: number; name?: string; display_name?: string };
type Rule = { id: number; practitioner_name: string; location_name: string; weekday: number; start_time: string; end_time: string; valid_from: string; valid_until: string | null; active?: number };

export function AvailabilityAdmin() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [people, setPeople] = useState<Named[]>([]);
  const [locations, setLocations] = useState<Named[]>([]);
  const [rules, setRules] = useState<Rule[]>([]);
  const [form, setForm] = useState({ practitioner_id: '', location_id: '', weekday: '1', start_time: '09:00', end_time: '17:00', valid_from: new Date().toISOString().slice(0, 10), valid_until: '' });
  const [error, setError] = useState('');
  const { markDirty, markClean } = useUnsavedForm();

  const load = useCallback(async () => {
    try {
      const token = await getAccessToken();
      const headers = { Authorization: `Bearer ${token}` };
      const responses = await Promise.all([
        fetch(`${api}/admin/practitioners`, { headers }),
        fetch(`${api}/admin/locations`, { headers }),
        fetch(`${api}/admin/availability-rules`, { headers }),
      ]);
      const bodies = await Promise.all(responses.map((response) => response.json()));
      if (responses.some((response) => !response.ok)) throw new Error(t('Unable to load availability.'));
      const loadedPeople = normalizeNumericIds<Named[]>(bodies[0].data);
      const loadedLocations = normalizeNumericIds<Named[]>(bodies[1].data);
      setPeople(loadedPeople);
      setLocations(loadedLocations);
      setRules(normalizeNumericIds(bodies[2].data));
      setForm((current) => ({ ...current, practitioner_id: current.practitioner_id || String(loadedPeople[0]?.practitioner_id ?? ''), location_id: current.location_id || String(loadedLocations[0]?.id ?? '') }));
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : t('Unable to load availability.'));
    }
  }, [getAccessToken, t]);

  useEffect(() => { void load(); }, [load]);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    try {
      const token = await getAccessToken();
      const response = await fetch(`${api}/admin/availability-rules`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...form, practitioner_id: Number(form.practitioner_id), location_id: Number(form.location_id), weekday: Number(form.weekday), valid_until: form.valid_until || null }),
      });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to add hours.')));
      markClean();
      await load();
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : t('Unable to add hours.'));
    }
  };

  const remove = async (id: number) => {
    const token = await getAccessToken();
    await fetch(`${api}/admin/availability-rules/${id}`, { method: 'DELETE', headers: { Authorization: `Bearer ${token}` } });
    await load();
  };

  return <Stack spacing={3}>
    <Paper component="form" onSubmit={submit} onChange={markDirty} variant="outlined" sx={{ p: 3 }}>
      <Typography variant="h5">{t('Add recurring working hours')}</Typography>
      <Grid container spacing={2} mt={1}>
        {([['practitioner_id', 'Practitioner', people], ['location_id', 'Location', locations]] as const).map(([key, label, items]) => <Grid size={{ xs: 12, md: 6 }} key={key}><TextField required select fullWidth label={t(label)} value={form[key]} onChange={(event) => setForm((current) => ({ ...current, [key]: event.target.value }))}>{items.map((item) => <MenuItem key={item.practitioner_id ?? item.id} value={String(item.practitioner_id ?? item.id)}>{item.display_name ?? item.name}</MenuItem>)}</TextField></Grid>)}
        <Grid size={{ xs: 12, md: 4 }}><TextField select fullWidth label={t('Day')} value={form.weekday} onChange={(event) => setForm((current) => ({ ...current, weekday: event.target.value }))}>{days.map((day, index) => <MenuItem key={day} value={String(index + 1)}>{t(day)}</MenuItem>)}</TextField></Grid>
        <Grid size={{ xs: 6, md: 4 }}><TextField type="time" fullWidth label={t('Starts')} value={form.start_time} onChange={(event) => setForm((current) => ({ ...current, start_time: event.target.value }))} InputLabelProps={{ shrink: true }} /></Grid>
        <Grid size={{ xs: 6, md: 4 }}><TextField type="time" fullWidth label={t('Ends')} value={form.end_time} onChange={(event) => setForm((current) => ({ ...current, end_time: event.target.value }))} InputLabelProps={{ shrink: true }} /></Grid>
        <Grid size={{ xs: 6 }}><TextField type="date" fullWidth label={t('Valid from')} value={form.valid_from} onChange={(event) => setForm((current) => ({ ...current, valid_from: event.target.value }))} InputLabelProps={{ shrink: true }} /></Grid>
        <Grid size={{ xs: 6 }}><TextField type="date" fullWidth label={t('Valid until (optional)')} value={form.valid_until} onChange={(event) => setForm((current) => ({ ...current, valid_until: event.target.value }))} InputLabelProps={{ shrink: true }} /></Grid>
      </Grid>
      {error && <Alert severity="error" sx={{ mt: 2 }}>{error}</Alert>}
      <Button type="submit" variant="contained" startIcon={<Plus size={17} />} sx={{ mt: 2 }}>{t('Add hours')}</Button>
    </Paper>
    <Paper variant="outlined" sx={{ p: 3 }}>
      <Typography variant="h5" mb={2}>{t('Working-hour rules')}</Typography>
      <Stack spacing={1}>{rules.filter((rule) => rule.active !== 0).map((rule) => <Stack key={rule.id} direction="row" justifyContent="space-between" alignItems="center" sx={{ p: 1.5, border: '1px solid', borderColor: 'divider', borderRadius: 2 }}><Typography><b>{rule.practitioner_name}</b> · {t(days[rule.weekday - 1])} {rule.start_time.slice(0, 5)}–{rule.end_time.slice(0, 5)} · {rule.location_name}</Typography><Button color="error" startIcon={<Trash2 size={16} />} onClick={() => remove(rule.id)}>{t('Archive')}</Button></Stack>)}</Stack>
    </Paper>
  </Stack>;
}

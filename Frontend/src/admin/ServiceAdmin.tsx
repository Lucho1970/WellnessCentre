import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import {
  Alert, Box, Button, Chip, Dialog, DialogActions, DialogContent, DialogTitle, Divider, Drawer,
  FormControlLabel, Grid, IconButton, InputAdornment, List, ListItemButton, ListItemText,
  Paper, Stack, Switch, TextField, Typography,
} from '@mui/material';
import { Eye, Pencil, Plus, Save, Search, Settings2, Trash2, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiErrorMessage } from '../shared/api';
import { formatCad } from '../i18n/format';
import { useUnsavedForm } from '../shared/UnsavedChanges';
import { ServiceAssignments } from './ServiceAssignments';

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
type PanelMode = 'details' | 'new' | 'edit' | null;

const duration = (minutes = '60', price = ''): DurationForm => ({ key: crypto.randomUUID(), minutes, price });
const blank = (): Form => ({ name: '', description: '', preparation_instructions: '', duration_options: [duration()], lead_time_minutes: '0', booking_horizon_days: '365', buffer_before_minutes: '0', buffer_after_minutes: '0', requires_room: true, recurrence_allowed: false, active: true });
const fieldLabels: Record<'lead_time_minutes' | 'booking_horizon_days' | 'buffer_before_minutes' | 'buffer_after_minutes', string> = {
  lead_time_minutes: 'Lead time minutes', booking_horizon_days: 'Booking horizon days', buffer_before_minutes: 'Buffer before minutes', buffer_after_minutes: 'Buffer after minutes',
};

export function ServiceAdmin() {
  const { t, i18n } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [items, setItems] = useState<Service[]>([]);
  const [form, setForm] = useState<Form>(() => blank());
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [panelMode, setPanelMode] = useState<PanelMode>(null);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [assignmentServiceId, setAssignmentServiceId] = useState<number | null>(null);
  const [assignmentsDirty, setAssignmentsDirty] = useState(false);
  const [query, setQuery] = useState('');
  const [busy, setBusy] = useState(false);
  const [loadError, setLoadError] = useState('');
  const [panelError, setPanelError] = useState('');
  const [saved, setSaved] = useState('');
  const formGuard = useUnsavedForm();

  const options = (service: Service): DurationOption[] => service.duration_options?.length
    ? service.duration_options
    : service.durations.map(minutes => ({ minutes, price_cents: Number(service.price_cents) }));
  const selected = items.find(item => item.id === selectedId) ?? null;
  const filteredItems = useMemo(() => {
    const term = query.trim().toLocaleLowerCase();
    return term ? items.filter(item => `${item.name} ${item.description ?? ''}`.toLocaleLowerCase().includes(term)) : items;
  }, [items, query]);
  const money = (cents: number) => formatCad(cents, i18n.resolvedLanguage);

  const load = useCallback(async (preferredId?: number | null) => {
    setBusy(true); setLoadError('');
    try {
      const token = await getAccessToken();
      const response = await fetch(`${api}/admin/services`, { headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to load services.')));
      setItems(body.data);
      setSelectedId(current => {
        const requested = preferredId ?? current;
        return body.data.some((item: Service) => item.id === requested) ? requested : null;
      });
    } catch (cause) { setLoadError(cause instanceof Error ? cause.message : t('Unable to load services.')); }
    finally { setBusy(false); }
  }, [getAccessToken, t]);
  useEffect(() => { void load(); }, [load]);

  const field = <K extends keyof Form>(key: K, value: Form[K]) => setForm(current => ({ ...current, [key]: value }));
  const serviceForm = (service: Service): Form => ({
    name: service.name, description: service.description ?? '', preparation_instructions: service.preparation_instructions ?? '',
    duration_options: options(service).map(option => duration(String(option.minutes), (Number(option.price_cents) / 100).toFixed(2))),
    lead_time_minutes: String(service.lead_time_minutes), booking_horizon_days: String(service.booking_horizon_days),
    buffer_before_minutes: String(service.buffer_before_minutes), buffer_after_minutes: String(service.buffer_after_minutes),
    requires_room: Boolean(Number(service.requires_room)), recurrence_allowed: Boolean(Number(service.recurrence_allowed)), active: Boolean(Number(service.active)),
  });
  const startNew = () => { formGuard.markClean(); setEditingId(null); setForm(blank()); setPanelError(''); setPanelMode('new'); };
  const showDetails = () => { if (!selected) return; formGuard.markClean(); setPanelMode('details'); setPanelError(''); };
  const startEdit = (service = selected) => {
    if (!service) return;
    formGuard.markClean(); setSelectedId(service.id); setEditingId(service.id); setForm(serviceForm(service)); setPanelError(''); setPanelMode('edit');
  };
  const closePanel = () => {
    if (formGuard.dirty && !window.confirm(t('Discard your unsaved changes?'))) return;
    formGuard.markClean(); setPanelMode(null); setEditingId(null); setPanelError('');
  };
  const openAssignments = (service = selected) => { if (service) setAssignmentServiceId(service.id); };
  const closeAssignments = () => {
    if (assignmentsDirty && !window.confirm(t('Discard your unsaved changes?'))) return;
    setAssignmentsDirty(false); setAssignmentServiceId(null);
  };
  const changeDuration = (key: string, property: 'minutes' | 'price', value: string) => field('duration_options', form.duration_options.map(option => option.key === key ? { ...option, [property]: value } : option));
  const removeDuration = (key: string) => field('duration_options', form.duration_options.filter(option => option.key !== key));

  const submit = async (event: FormEvent) => {
    event.preventDefault(); setBusy(true); setPanelError(''); setSaved('');
    try {
      const durationOptions = form.duration_options.map(option => ({ minutes: Number(option.minutes), price_cents: Math.round(Number(option.price) * 100) }));
      const payload = { ...form, duration_options: durationOptions, price_cents: Math.min(...durationOptions.map(option => option.price_cents)), durations: durationOptions.map(option => option.minutes), lead_time_minutes: Number(form.lead_time_minutes), booking_horizon_days: Number(form.booking_horizon_days), buffer_before_minutes: Number(form.buffer_before_minutes), buffer_after_minutes: Number(form.buffer_after_minutes) };
      const token = await getAccessToken();
      const response = await fetch(editingId ? `${api}/admin/services/${editingId}` : `${api}/admin/services`, { method: editingId ? 'PATCH' : 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to save service.')));
      const savedId = Number(body.data?.id ?? editingId ?? 0) || null;
      const message = t('{{name}} was {{action}}.', { name: form.name, action: t(editingId ? 'updated' : 'created') });
      formGuard.markClean(); setPanelMode(null); setEditingId(null); await load(savedId); setSaved(message);
    } catch (cause) { setPanelError(cause instanceof Error ? cause.message : t('Unable to save service.')); }
    finally { setBusy(false); }
  };

  return <Stack spacing={2}>
    <Paper variant="outlined" sx={{ p: 1.5 }}>
      <Stack component="nav" aria-label={t('Service actions')} direction={{ xs: 'column', md: 'row' }} gap={1} alignItems={{ md: 'center' }}>
        <Button variant="contained" startIcon={<Plus size={17}/>} onClick={startNew}>{t('New service')}</Button>
        <Divider orientation="vertical" flexItem sx={{ display: { xs: 'none', md: 'block' }, mx: 0.5 }}/>
        <Button startIcon={<Eye size={17}/>} disabled={!selected} onClick={showDetails}>{t('Details')}</Button>
        <Button startIcon={<Pencil size={17}/>} disabled={!selected} onClick={() => startEdit()}>{t('Edit')}</Button>
        <Button startIcon={<Settings2 size={17}/>} disabled={!selected} onClick={() => openAssignments()}>{t('Practitioners & locations')}</Button>
        <TextField size="small" label={t('Filter services')} value={query} onChange={event => setQuery(event.target.value)} sx={{ ml: { md: 'auto' }, minWidth: { md: 250 } }} slotProps={{ input: { startAdornment: <InputAdornment position="start"><Search size={16}/></InputAdornment> } }}/>
      </Stack>
    </Paper>

    {saved && <Alert severity="success" onClose={() => setSaved('')}>{saved}</Alert>}
    {loadError && <Alert severity="error" action={<Button color="inherit" onClick={() => void load()}>{t('Retry')}</Button>}>{loadError}</Alert>}
    <Paper variant="outlined" sx={{ overflow: 'hidden' }}>
      <Box sx={{ px: 2.5, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}>
        <Typography variant="h5">{t('Services')}</Typography>
        <Typography color="text.secondary">{t('Select a service to view its details or enable actions.')}</Typography>
      </Box>
      <List disablePadding aria-label={t('Services')}>
        {filteredItems.map(service => {
          const serviceOptions = options(service);
          return <ListItemButton key={service.id} selected={selectedId === service.id} onClick={() => setSelectedId(service.id)} divider sx={{ py: 1.75, px: 2.5 }}>
            <ListItemText primary={<Stack direction="row" gap={1} alignItems="center" flexWrap="wrap"><Typography fontWeight={750}>{service.name}</Typography><Chip size="small" color={Boolean(Number(service.active)) ? 'success' : 'default'} label={t(Boolean(Number(service.active)) ? 'Active' : 'Inactive')}/></Stack>} secondary={<>{serviceOptions.map(option => t('{{minutes}} min — {{price}}', { minutes: option.minutes, price: money(option.price_cents) })).join(' · ')} · {t(Boolean(Number(service.requires_room)) ? 'Room required' : 'No room required')}</>} />
          </ListItemButton>;
        })}
        {!busy && filteredItems.length === 0 && <Box sx={{ p: 5, textAlign: 'center' }}><Typography variant="h6">{t(query ? 'No matching services' : 'No services yet')}</Typography><Typography color="text.secondary" mb={2}>{t(query ? 'Try a different service name.' : 'Create the first service offered by the clinic.')}</Typography>{!query && <Button variant="contained" startIcon={<Plus size={17}/>} onClick={startNew}>{t('New service')}</Button>}</Box>}
      </List>
    </Paper>

    <Drawer anchor="right" open={panelMode !== null} onClose={closePanel} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 620 }, maxWidth: '100%' } } }}>
      <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ px: 3, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}>
        <Box><Typography variant="overline" color="primary">{t(panelMode === 'details' ? 'Service details' : panelMode === 'edit' ? 'Edit service' : 'New service')}</Typography><Typography variant="h5">{panelMode === 'new' ? t('Create a service') : selected?.name}</Typography></Box>
        <IconButton aria-label={t('Close panel')} onClick={closePanel}><X/></IconButton>
      </Stack>
      {panelMode === 'details' && selected && <ServiceDetails service={selected} options={options(selected)} money={money} edit={() => startEdit(selected)} assignments={() => openAssignments(selected)}/>}
      {(panelMode === 'new' || panelMode === 'edit') && <Box component="form" onSubmit={submit} onChange={formGuard.markDirty} sx={{ display: 'flex', flexDirection: 'column', minHeight: 0, flex: 1 }}>
        <Box sx={{ p: 3, overflowY: 'auto', flex: 1 }}><ServiceFields form={form} field={field} changeDuration={changeDuration} removeDuration={removeDuration}/>{panelError && <Alert severity="error" sx={{ mt: 2 }}>{panelError}</Alert>}</Box>
        <Stack direction="row" justifyContent="flex-end" gap={1} sx={{ p: 2, borderTop: '1px solid', borderColor: 'divider', bgcolor: 'background.paper' }}><Button onClick={closePanel} disabled={busy}>{t('Cancel')}</Button><Button type="submit" variant="contained" disabled={busy} startIcon={<Save size={17}/>}>{t(busy ? 'Saving…' : panelMode === 'edit' ? 'Save changes' : 'Add service')}</Button></Stack>
      </Box>}
    </Drawer>

    <Dialog open={assignmentServiceId !== null} onClose={closeAssignments} fullWidth maxWidth="lg">
      <DialogTitle>{t('Practitioners & locations')}</DialogTitle>
      <DialogContent dividers>{assignmentServiceId !== null && <ServiceAssignments initialServiceId={assignmentServiceId} lockService onDirtyChange={setAssignmentsDirty}/>}</DialogContent>
      <DialogActions><Button onClick={closeAssignments}>{t('Close')}</Button></DialogActions>
    </Dialog>
  </Stack>;
}

function ServiceDetails({ service, options, money, edit, assignments }: { service: Service; options: DurationOption[]; money: (cents: number) => string; edit: () => void; assignments: () => void }) {
  const { t } = useTranslation();
  const rows = [
    [t('Status'), t(Boolean(Number(service.active)) ? 'Active' : 'Inactive')],
    [t('Duration and price options'), options.map(option => t('{{minutes}} min — {{price}}', { minutes: option.minutes, price: money(option.price_cents) })).join(' · ')],
    [t('Room requirement'), t(Boolean(Number(service.requires_room)) ? 'Room required' : 'No room required')],
    [t('Lead time'), t('{{minutes}} minutes', { minutes: service.lead_time_minutes })],
    [t('Booking horizon'), t('{{days}} days', { days: service.booking_horizon_days })],
    [t('Buffers'), t('{{before}} min before · {{after}} min after', { before: service.buffer_before_minutes, after: service.buffer_after_minutes })],
    [t('Recurring bookings'), t(Boolean(Number(service.recurrence_allowed)) ? 'Allowed' : 'Not allowed')],
  ];
  return <Stack spacing={3} sx={{ p: 3, overflowY: 'auto' }}>
    {service.description && <Box><Typography variant="overline" color="text.secondary">{t('Description')}</Typography><Typography>{service.description}</Typography></Box>}
    <Stack divider={<Divider flexItem/>}>{rows.map(([label, value]) => <Box key={label} sx={{ py: 1.5 }}><Typography variant="caption" color="text.secondary">{label}</Typography><Typography fontWeight={600}>{value}</Typography></Box>)}</Stack>
    {service.preparation_instructions && <Box><Typography variant="overline" color="text.secondary">{t('Preparation instructions')}</Typography><Typography>{service.preparation_instructions}</Typography></Box>}
    <Stack direction={{ xs: 'column', sm: 'row' }} gap={1}><Button variant="contained" startIcon={<Pencil size={17}/>} onClick={edit}>{t('Edit')}</Button><Button variant="outlined" startIcon={<Settings2 size={17}/>} onClick={assignments}>{t('Practitioners & locations')}</Button></Stack>
  </Stack>;
}

function ServiceFields({ form, field, changeDuration, removeDuration }: { form: Form; field: <K extends keyof Form>(key: K, value: Form[K]) => void; changeDuration: (key: string, property: 'minutes' | 'price', value: string) => void; removeDuration: (key: string) => void }) {
  const { t } = useTranslation();
  return <Grid container spacing={2}>
    <Grid size={12}><TextField required fullWidth label={t('Service name')} value={form.name} onChange={event => field('name', event.target.value)} inputProps={{ maxLength: 150 }}/></Grid>
    <Grid size={12}><TextField fullWidth multiline minRows={2} label={t('Description')} value={form.description} onChange={event => field('description', event.target.value)}/></Grid>
    <Grid size={12}><Typography variant="subtitle1" fontWeight={700}>{t('Duration and price options')}</Typography><Typography variant="body2" color="text.secondary">{t('Prices are explicit for each duration. Appointments keep a snapshot of the selected price.')}</Typography></Grid>
    {form.duration_options.map((option, index) => <Grid size={12} key={option.key}><Stack direction="row" spacing={1} alignItems="center"><TextField required fullWidth type="number" label={t('Duration {{number}} (minutes)', { number: index + 1 })} value={option.minutes} onChange={event => changeDuration(option.key, 'minutes', event.target.value)} inputProps={{ min: 15, max: 480, step: 15 }}/><TextField required fullWidth type="number" label={t('Price {{number}} (CAD)', { number: index + 1 })} value={option.price} onChange={event => changeDuration(option.key, 'price', event.target.value)} inputProps={{ min: 0, step: .01 }}/><IconButton aria-label={t('Remove duration {{number}}', { number: index + 1 })} disabled={form.duration_options.length === 1} onClick={() => removeDuration(option.key)}><Trash2 size={18}/></IconButton></Stack></Grid>)}
    <Grid size={12}><Button startIcon={<Plus size={16}/>} onClick={() => field('duration_options', [...form.duration_options, duration()])}>{t('Add duration and price')}</Button></Grid>
    {(Object.keys(fieldLabels) as (keyof typeof fieldLabels)[]).map(key => <Grid size={{ xs: 6 }} key={key}><TextField required fullWidth type="number" label={t(fieldLabels[key])} value={form[key]} onChange={event => field(key, event.target.value)}/></Grid>)}
    <Grid size={12}><TextField fullWidth multiline minRows={2} label={t('Preparation instructions')} value={form.preparation_instructions} onChange={event => field('preparation_instructions', event.target.value)}/></Grid>
    <Grid size={12}><Stack><FormControlLabel control={<Switch checked={form.requires_room} onChange={event => field('requires_room', event.target.checked)}/>} label={t('Requires a room')}/><FormControlLabel control={<Switch checked={form.recurrence_allowed} onChange={event => field('recurrence_allowed', event.target.checked)}/>} label={t('Recurring bookings')}/><FormControlLabel control={<Switch checked={form.active} onChange={event => field('active', event.target.checked)}/>} label={t('Active')}/></Stack></Grid>
  </Grid>;
}

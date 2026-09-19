import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { Alert, Box, Button, Chip, Divider, Drawer, FormControlLabel, Grid, IconButton, InputAdornment, List, ListItemButton, ListItemText, Paper, Stack, Switch, TextField, Typography } from '@mui/material';
import { Eye, Pencil, Plus, Save, Search, X } from 'lucide-react';
import { useStaffAuth } from '../auth/AuthProvider';
import { useTranslation } from 'react-i18next';
import { apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { AddressEntry } from '../shared/AddressEntry';
import { useUnsavedForm } from '../shared/UnsavedChanges';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type Location = { id: number; name: string; timezone: string; address_line1: string | null; address_line2: string | null; city: string | null; province: string | null; postal_code: string | null; phone: string | null; is_bookable: number | boolean };
type Form = { name: string; timezone: string; address_line1: string; address_line2: string; city: string; province: string; postal_code: string; phone: string; is_bookable: boolean };
type PanelMode = 'details' | 'new' | 'edit' | null;
const blank = (): Form => ({ name: '', timezone: 'America/Toronto', address_line1: '', address_line2: '', city: '', province: 'Ontario', postal_code: '', phone: '', is_bookable: true });
const locationForm = (item: Location): Form => ({ name: item.name, timezone: item.timezone, address_line1: item.address_line1 ?? '', address_line2: item.address_line2 ?? '', city: item.city ?? '', province: item.province ?? '', postal_code: item.postal_code ?? '', phone: item.phone ?? '', is_bookable: Boolean(Number(item.is_bookable)) });
const address = (item: Location) => [item.address_line1, item.address_line2, item.city, item.province, item.postal_code].filter(Boolean).join(', ');

export function LocationAdmin() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [items, setItems] = useState<Location[]>([]);
  const [form, setForm] = useState<Form>(() => blank());
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [panelMode, setPanelMode] = useState<PanelMode>(null);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [query, setQuery] = useState('');
  const [busy, setBusy] = useState(false);
  const [loadError, setLoadError] = useState('');
  const [panelError, setPanelError] = useState('');
  const [saved, setSaved] = useState('');
  const formGuard = useUnsavedForm();
  const selected = items.find(item => item.id === selectedId) ?? null;
  const filteredItems = useMemo(() => {
    const term = query.trim().toLocaleLowerCase();
    return term ? items.filter(item => `${item.name} ${address(item)} ${item.phone ?? ''}`.toLocaleLowerCase().includes(term)) : items;
  }, [items, query]);

  const load = useCallback(async (preferredId?: number | null) => {
    setBusy(true); setLoadError('');
    try {
      const token = await getAccessToken();
      const response = await fetch(`${api}/admin/locations`, { headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to load locations.')));
      const loaded = normalizeNumericIds<Location[]>(body.data);
      setItems(loaded);
      setSelectedId(current => {
        const requested = Number(preferredId ?? current ?? 0) || null;
        return loaded.some(item => item.id === requested) ? requested : null;
      });
    } catch (cause) { setLoadError(cause instanceof Error ? cause.message : t('Unable to load locations.')); }
    finally { setBusy(false); }
  }, [getAccessToken, t]);
  useEffect(() => { void load(); }, [load]);

  const field = <K extends keyof Form>(key: K, value: Form[K]) => setForm(current => ({ ...current, [key]: value }));
  const startNew = () => { formGuard.markClean(); setEditingId(null); setForm(blank()); setPanelError(''); setPanelMode('new'); };
  const showDetails = () => { if (!selected) return; formGuard.markClean(); setPanelError(''); setPanelMode('details'); };
  const startEdit = (item = selected) => { if (!item) return; formGuard.markClean(); setSelectedId(item.id); setEditingId(item.id); setForm(locationForm(item)); setPanelError(''); setPanelMode('edit'); };
  const closePanel = () => {
    if (formGuard.dirty && !window.confirm(t('Discard your unsaved changes?'))) return;
    formGuard.markClean(); setPanelMode(null); setEditingId(null); setPanelError('');
  };
  const submit = async (event: FormEvent) => {
    event.preventDefault(); setBusy(true); setPanelError(''); setSaved('');
    try {
      const token = await getAccessToken();
      const response = await fetch(editingId ? `${api}/admin/locations/${editingId}` : `${api}/admin/locations`, { method: editingId ? 'PATCH' : 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify(form) });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to save location.')));
      const savedId = Number(body.data?.id ?? editingId ?? 0) || null;
      const message = t('{{name}} was {{action}}.', { name: form.name, action: t(editingId ? 'updated' : 'created') });
      formGuard.markClean(); setPanelMode(null); setEditingId(null); await load(savedId); setSaved(message);
    } catch (cause) { setPanelError(cause instanceof Error ? cause.message : t('Unable to save location.')); }
    finally { setBusy(false); }
  };

  return <Stack spacing={2}>
    <Paper variant="outlined" sx={{ p: 1.5 }}><Stack component="nav" aria-label={t('Location actions')} direction={{ xs: 'column', md: 'row' }} gap={1} alignItems={{ md: 'center' }}>
      <Button variant="contained" startIcon={<Plus size={17}/>} onClick={startNew}>{t('New location')}</Button>
      <Divider orientation="vertical" flexItem sx={{ display: { xs: 'none', md: 'block' }, mx: .5 }}/>
      <Button startIcon={<Eye size={17}/>} disabled={!selected} onClick={showDetails}>{t('Details')}</Button>
      <Button startIcon={<Pencil size={17}/>} disabled={!selected} onClick={() => startEdit()}>{t('Edit')}</Button>
      <TextField size="small" label={t('Filter locations')} value={query} onChange={event => setQuery(event.target.value)} sx={{ ml: { md: 'auto' }, minWidth: { md: 250 } }} slotProps={{ input: { startAdornment: <InputAdornment position="start"><Search size={16}/></InputAdornment> } }}/>
    </Stack></Paper>
    {saved && <Alert severity="success" onClose={() => setSaved('')}>{saved}</Alert>}
    {loadError && <Alert severity="error" action={<Button color="inherit" onClick={() => void load()}>{t('Retry')}</Button>}>{loadError}</Alert>}
    <Paper variant="outlined" sx={{ overflow: 'hidden' }}>
      <Box sx={{ px: 2.5, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}><Typography variant="h5">{t('Clinic locations')}</Typography><Typography color="text.secondary">{t('Select a location to view its details or enable actions.')}</Typography></Box>
      <List disablePadding aria-label={t('Clinic locations')}>
        {filteredItems.map(item => <ListItemButton key={item.id} selected={selectedId === item.id} onClick={() => setSelectedId(item.id)} divider sx={{ py: 1.75, px: 2.5 }}>
          <ListItemText primary={<Stack direction="row" gap={1} alignItems="center" flexWrap="wrap"><Typography fontWeight={750}>{item.name}</Typography><Chip size="small" color={Boolean(Number(item.is_bookable)) ? 'success' : 'default'} label={t(Boolean(Number(item.is_bookable)) ? 'Bookable' : 'Not bookable')}/></Stack>} secondary={`${address(item) || t('Address not set')} · ${item.timezone}`} />
        </ListItemButton>)}
        {!busy && filteredItems.length === 0 && <Box sx={{ p: 5, textAlign: 'center' }}><Typography variant="h6">{t(query ? 'No matching locations' : 'No locations yet')}</Typography><Typography color="text.secondary" mb={2}>{t(query ? 'Try a different location name or address.' : 'Create the first clinic location or service area.')}</Typography>{!query && <Button variant="contained" startIcon={<Plus size={17}/>} onClick={startNew}>{t('New location')}</Button>}</Box>}
      </List>
    </Paper>
    <Drawer anchor="right" open={panelMode !== null} onClose={closePanel} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 620 }, maxWidth: '100%' } } }}>
      <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ px: 3, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}><Box><Typography variant="overline" color="primary">{t(panelMode === 'details' ? 'Location details' : panelMode === 'edit' ? 'Edit location' : 'New location')}</Typography><Typography variant="h5">{panelMode === 'new' ? t('Create a location') : selected?.name}</Typography></Box><IconButton aria-label={t('Close panel')} onClick={closePanel}><X/></IconButton></Stack>
      {panelMode === 'details' && selected && <LocationDetails item={selected} edit={() => startEdit(selected)}/>}
      {(panelMode === 'new' || panelMode === 'edit') && <Box component="form" onSubmit={submit} onChange={formGuard.markDirty} sx={{ display: 'flex', flexDirection: 'column', minHeight: 0, flex: 1 }}>
        <Box sx={{ p: 3, overflowY: 'auto', flex: 1 }}><LocationFields form={form} field={field} busy={busy} addressChange={value => { formGuard.markDirty(); setForm(current => ({ ...current, ...value })); }}/>{panelError && <Alert severity="error" sx={{ mt: 2 }}>{panelError}</Alert>}</Box>
        <Stack direction="row" justifyContent="flex-end" gap={1} sx={{ p: 2, borderTop: '1px solid', borderColor: 'divider' }}><Button onClick={closePanel} disabled={busy}>{t('Cancel')}</Button><Button type="submit" variant="contained" disabled={busy} startIcon={<Save size={17}/>}>{t(busy ? 'Saving…' : panelMode === 'edit' ? 'Save changes' : 'Add location')}</Button></Stack>
      </Box>}
    </Drawer>
  </Stack>;
}

function LocationDetails({ item, edit }: { item: Location; edit: () => void }) {
  const { t } = useTranslation();
  const rows = [[t('Status'), t(Boolean(Number(item.is_bookable)) ? 'Bookable' : 'Not bookable')], [t('Address'), address(item) || t('Address not set')], [t('Timezone'), item.timezone], [t('Phone'), item.phone || t('Not set')]];
  return <Stack spacing={3} sx={{ p: 3, overflowY: 'auto' }}><Stack divider={<Divider flexItem/>}>{rows.map(([label, value]) => <Box key={label} sx={{ py: 1.5 }}><Typography variant="caption" color="text.secondary">{label}</Typography><Typography fontWeight={600}>{value}</Typography></Box>)}</Stack><Button variant="contained" startIcon={<Pencil size={17}/>} onClick={edit}>{t('Edit')}</Button></Stack>;
}

function LocationFields({ form, field, busy, addressChange }: { form: Form; field: <K extends keyof Form>(key: K, value: Form[K]) => void; busy: boolean; addressChange: (value: Pick<Form, 'address_line1' | 'address_line2' | 'city' | 'province' | 'postal_code'>) => void }) {
  const { t } = useTranslation();
  return <Grid container spacing={2}>
    <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth label={t('Location name')} value={form.name} onChange={event => field('name', event.target.value)} inputProps={{ maxLength: 150 }}/></Grid>
    <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth label={t('Timezone')} value={form.timezone} onChange={event => field('timezone', event.target.value)} helperText={t('IANA timezone, for example America/Toronto')}/></Grid>
    <Grid size={12}><AddressEntry disabled={busy} value={{ address_line1: form.address_line1, address_line2: form.address_line2, city: form.city, province: form.province, postal_code: form.postal_code, country: 'Canada' }} onChange={value => addressChange({ address_line1: value.address_line1, address_line2: value.address_line2, city: value.city, province: value.province, postal_code: value.postal_code })}/></Grid>
    <Grid size={{ xs: 12, md: 6 }}><TextField fullWidth label={t('Phone')} value={form.phone} onChange={event => field('phone', event.target.value)}/></Grid>
    <Grid size={{ xs: 12, md: 6 }}><FormControlLabel control={<Switch checked={form.is_bookable} onChange={event => field('is_bookable', event.target.checked)}/>} label={t('Accepting bookings at this location')}/></Grid>
  </Grid>;
}

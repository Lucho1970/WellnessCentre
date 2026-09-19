import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { Alert, Box, Button, Chip, Dialog, DialogActions, DialogContent, DialogTitle, Divider, Drawer, FormControlLabel, Grid, IconButton, InputAdornment, List, ListItemButton, ListItemText, MenuItem, Paper, Stack, Switch, TextField, Typography } from '@mui/material';
import { Eye, Pencil, Plus, Save, Search, Settings2, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { useUnsavedForm } from '../shared/UnsavedChanges';
import { RoomCapabilities } from './RoomCapabilities';
const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type Location = { id: number; name: string };
type Room = { id: number; location_id: number; location_name: string; name: string; room_type: string | null; equipment_notes: string | null; turnover_minutes: number; is_bookable: number | boolean };
type Form = { location_id: string; name: string; room_type: string; equipment_notes: string; turnover_minutes: string; is_bookable: boolean };
type PanelMode = 'details' | 'new' | 'edit' | null;
const blank = (locationId = ''): Form => ({ location_id: locationId, name: '', room_type: 'Treatment room', equipment_notes: '', turnover_minutes: '0', is_bookable: true });
const roomForm = (item: Room): Form => ({ location_id: String(item.location_id), name: item.name, room_type: item.room_type ?? '', equipment_notes: item.equipment_notes ?? '', turnover_minutes: String(item.turnover_minutes), is_bookable: Boolean(Number(item.is_bookable)) });

export function RoomAdmin() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [locations, setLocations] = useState<Location[]>([]);
  const [items, setItems] = useState<Room[]>([]);
  const [form, setForm] = useState<Form>(() => blank());
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [panelMode, setPanelMode] = useState<PanelMode>(null);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [query, setQuery] = useState('');
  const [busy, setBusy] = useState(false);
  const [loadError, setLoadError] = useState('');
  const [panelError, setPanelError] = useState('');
  const [saved, setSaved] = useState('');
  const [capabilitiesOpen, setCapabilitiesOpen] = useState(false);
  const [capabilitiesDirty, setCapabilitiesDirty] = useState(false);
  const formGuard = useUnsavedForm();
  const selected = items.find(item => item.id === selectedId) ?? null;
  const filteredItems = useMemo(() => {
    const term = query.trim().toLocaleLowerCase();
    return term ? items.filter(item => `${item.name} ${item.location_name} ${item.room_type ?? ''}`.toLocaleLowerCase().includes(term)) : items;
  }, [items, query]);

  const load = useCallback(async (preferredId?: number | null) => {
    setBusy(true); setLoadError('');
    try {
      const token = await getAccessToken(); const headers = { Authorization: `Bearer ${token}` };
      const [locationResponse, roomResponse] = await Promise.all([fetch(`${api}/admin/locations`, { headers }), fetch(`${api}/admin/rooms`, { headers })]);
      const [locationBody, roomBody] = await Promise.all([locationResponse.json(), roomResponse.json()]);
      if (!locationResponse.ok) throw new Error(apiErrorMessage(locationBody, locationResponse.status, t('Unable to load locations.')));
      if (!roomResponse.ok) throw new Error(apiErrorMessage(roomBody, roomResponse.status, t('Unable to load rooms.')));
      const loadedLocations = normalizeNumericIds<Location[]>(locationBody.data); const loadedItems = normalizeNumericIds<Room[]>(roomBody.data);
      setLocations(loadedLocations); setItems(loadedItems); setForm(current => ({ ...current, location_id: current.location_id || String(loadedLocations[0]?.id ?? '') }));
      setSelectedId(current => { const requested = Number(preferredId ?? current ?? 0) || null; return loadedItems.some(item => item.id === requested) ? requested : null; });
    } catch (cause) { setLoadError(cause instanceof Error ? cause.message : t('Unable to load rooms.')); }
    finally { setBusy(false); }
  }, [getAccessToken, t]);
  useEffect(() => { void load(); }, [load]);

  const field = <K extends keyof Form>(key: K, value: Form[K]) => setForm(current => ({ ...current, [key]: value }));
  const startNew = () => { formGuard.markClean(); setEditingId(null); setForm(blank(String(locations[0]?.id ?? ''))); setPanelError(''); setPanelMode('new'); };
  const showDetails = () => { if (!selected) return; formGuard.markClean(); setPanelError(''); setPanelMode('details'); };
  const startEdit = (item = selected) => { if (!item) return; formGuard.markClean(); setSelectedId(item.id); setEditingId(item.id); setForm(roomForm(item)); setPanelError(''); setPanelMode('edit'); };
  const closePanel = () => { if (formGuard.dirty && !window.confirm(t('Discard your unsaved changes?'))) return; formGuard.markClean(); setPanelMode(null); setEditingId(null); setPanelError(''); };
  const closeCapabilities = () => { if (capabilitiesDirty && !window.confirm(t('Discard your unsaved changes?'))) return; setCapabilitiesDirty(false); setCapabilitiesOpen(false); };
  const submit = async (event: FormEvent) => {
    event.preventDefault(); setBusy(true); setPanelError(''); setSaved('');
    try {
      const token = await getAccessToken(); const payload = { ...form, location_id: Number(form.location_id), turnover_minutes: Number(form.turnover_minutes) };
      const response = await fetch(editingId ? `${api}/admin/rooms/${editingId}` : `${api}/admin/rooms`, { method: editingId ? 'PATCH' : 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to save room.')));
      const savedId = Number(body.data?.id ?? editingId ?? 0) || null; const message = t('{{name}} was {{action}}.', { name: form.name, action: t(editingId ? 'updated' : 'created') });
      formGuard.markClean(); setPanelMode(null); setEditingId(null); await load(savedId); setSaved(message);
    } catch (cause) { setPanelError(cause instanceof Error ? cause.message : t('Unable to save room.')); }
    finally { setBusy(false); }
  };

  return <Stack spacing={2}>
    <Paper variant="outlined" sx={{ p: 1.5 }}><Stack component="nav" aria-label={t('Room actions')} direction={{ xs: 'column', md: 'row' }} gap={1} alignItems={{ md: 'center' }}>
      <Button variant="contained" startIcon={<Plus size={17}/>} onClick={startNew} disabled={locations.length === 0}>{t('New room')}</Button>
      <Divider orientation="vertical" flexItem sx={{ display: { xs: 'none', md: 'block' }, mx: .5 }}/>
      <Button startIcon={<Eye size={17}/>} disabled={!selected} onClick={showDetails}>{t('Details')}</Button><Button startIcon={<Pencil size={17}/>} disabled={!selected} onClick={() => startEdit()}>{t('Edit')}</Button>
      <Button startIcon={<Settings2 size={17}/>} onClick={() => setCapabilitiesOpen(true)}>{t('Capabilities')}</Button>
      <TextField size="small" label={t('Filter rooms')} value={query} onChange={event => setQuery(event.target.value)} sx={{ ml: { md: 'auto' }, minWidth: { md: 250 } }} slotProps={{ input: { startAdornment: <InputAdornment position="start"><Search size={16}/></InputAdornment> } }}/>
    </Stack></Paper>
    {saved && <Alert severity="success" onClose={() => setSaved('')}>{saved}</Alert>}{loadError && <Alert severity="error" action={<Button color="inherit" onClick={() => void load()}>{t('Retry')}</Button>}>{loadError}</Alert>}
    <Paper variant="outlined" sx={{ overflow: 'hidden' }}>
      <Box sx={{ px: 2.5, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}><Typography variant="h5">{t('Rooms')}</Typography><Typography color="text.secondary">{t('Select a room to view its details or enable actions.')}</Typography></Box>
      <List disablePadding aria-label={t('Rooms')}>{filteredItems.map(item => <ListItemButton key={item.id} selected={selectedId === item.id} onClick={() => setSelectedId(item.id)} divider sx={{ py: 1.75, px: 2.5 }}><ListItemText primary={<Stack direction="row" gap={1} alignItems="center" flexWrap="wrap"><Typography fontWeight={750}>{item.name}</Typography><Chip size="small" color={Boolean(Number(item.is_bookable)) ? 'success' : 'default'} label={t(Boolean(Number(item.is_bookable)) ? 'Bookable' : 'Not bookable')}/></Stack>} secondary={`${item.location_name} · ${item.room_type || t('General')} · ${t('{{minutes}} min turnover', { minutes: item.turnover_minutes })}`}/></ListItemButton>)}
        {!busy && filteredItems.length === 0 && <Box sx={{ p: 5, textAlign: 'center' }}><Typography variant="h6">{t(query ? 'No matching rooms' : 'No rooms yet')}</Typography><Typography color="text.secondary" mb={2}>{t(query ? 'Try a different room or location name.' : locations.length ? 'Create the first room at a clinic location.' : 'Create a location before adding rooms.')}</Typography>{!query && locations.length > 0 && <Button variant="contained" startIcon={<Plus size={17}/>} onClick={startNew}>{t('New room')}</Button>}</Box>}
      </List>
    </Paper>
    <Drawer anchor="right" open={panelMode !== null} onClose={closePanel} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 620 }, maxWidth: '100%' } } }}>
      <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ px: 3, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}><Box><Typography variant="overline" color="primary">{t(panelMode === 'details' ? 'Room details' : panelMode === 'edit' ? 'Edit room' : 'New room')}</Typography><Typography variant="h5">{panelMode === 'new' ? t('Create a room') : selected?.name}</Typography></Box><IconButton aria-label={t('Close panel')} onClick={closePanel}><X/></IconButton></Stack>
      {panelMode === 'details' && selected && <RoomDetails item={selected} edit={() => startEdit(selected)}/>}
      {(panelMode === 'new' || panelMode === 'edit') && <Box component="form" onSubmit={submit} onChange={formGuard.markDirty} sx={{ display: 'flex', flexDirection: 'column', minHeight: 0, flex: 1 }}><Box sx={{ p: 3, overflowY: 'auto', flex: 1 }}><RoomFields form={form} locations={locations} field={field}/>{panelError && <Alert severity="error" sx={{ mt: 2 }}>{panelError}</Alert>}</Box><Stack direction="row" justifyContent="flex-end" gap={1} sx={{ p: 2, borderTop: '1px solid', borderColor: 'divider' }}><Button onClick={closePanel} disabled={busy}>{t('Cancel')}</Button><Button type="submit" variant="contained" disabled={busy || !form.location_id} startIcon={<Save size={17}/>}>{t(busy ? 'Saving…' : panelMode === 'edit' ? 'Save changes' : 'Add room')}</Button></Stack></Box>}
    </Drawer>
    <Dialog open={capabilitiesOpen} onClose={closeCapabilities} fullWidth maxWidth="lg"><DialogTitle>{t('Room capabilities')}</DialogTitle><DialogContent dividers><RoomCapabilities onDirtyChange={setCapabilitiesDirty}/></DialogContent><DialogActions><Button onClick={closeCapabilities}>{t('Close')}</Button></DialogActions></Dialog>
  </Stack>;
}

function RoomDetails({ item, edit }: { item: Room; edit: () => void }) {
  const { t } = useTranslation(); const rows = [[t('Status'), t(Boolean(Number(item.is_bookable)) ? 'Bookable' : 'Not bookable')], [t('Location'), item.location_name], [t('Room type'), item.room_type || t('General')], [t('Turnover time'), t('{{minutes}} minutes', { minutes: item.turnover_minutes })], [t('Equipment and room notes'), item.equipment_notes || t('Not set')]];
  return <Stack spacing={3} sx={{ p: 3, overflowY: 'auto' }}><Stack divider={<Divider flexItem/>}>{rows.map(([label, value]) => <Box key={label} sx={{ py: 1.5 }}><Typography variant="caption" color="text.secondary">{label}</Typography><Typography fontWeight={600}>{value}</Typography></Box>)}</Stack><Button variant="contained" startIcon={<Pencil size={17}/>} onClick={edit}>{t('Edit')}</Button></Stack>;
}

function RoomFields({ form, locations, field }: { form: Form; locations: Location[]; field: <K extends keyof Form>(key: K, value: Form[K]) => void }) {
  const { t } = useTranslation(); return <Grid container spacing={2}>
    <Grid size={{ xs: 12, md: 6 }}><TextField required select fullWidth label={t('Location')} value={form.location_id} onChange={event => field('location_id', event.target.value)}>{locations.map(item => <MenuItem key={item.id} value={String(item.id)}>{item.name}</MenuItem>)}</TextField></Grid>
    <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth label={t('Room name')} value={form.name} onChange={event => field('name', event.target.value)}/></Grid>
    <Grid size={{ xs: 12, md: 6 }}><TextField fullWidth label={t('Room type')} value={form.room_type} onChange={event => field('room_type', event.target.value)}/></Grid>
    <Grid size={{ xs: 12, md: 6 }}><TextField required type="number" fullWidth label={t('Turnover time (minutes)')} value={form.turnover_minutes} onChange={event => field('turnover_minutes', event.target.value)} inputProps={{ min: 0, max: 240, step: 5 }}/></Grid>
    <Grid size={12}><TextField multiline minRows={2} fullWidth label={t('Equipment and room notes')} value={form.equipment_notes} onChange={event => field('equipment_notes', event.target.value)}/></Grid>
    <Grid size={12}><FormControlLabel control={<Switch checked={form.is_bookable} onChange={event => field('is_bookable', event.target.checked)}/>} label={t('Available for booking')}/></Grid>
  </Grid>;
}

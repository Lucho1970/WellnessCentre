import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { Alert, Box, Button, Chip, Divider, Drawer, FormControlLabel, Grid, IconButton, InputAdornment, List, ListItemButton, ListItemText, MenuItem, Paper, Stack, Switch, TextField, Typography } from '@mui/material';
import { Eye, Pencil, Plus, Save, Search, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { useUnsavedForm } from '../shared/UnsavedChanges';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
const tenantId = import.meta.env.VITE_ENTRA_TENANT_ID ?? '';
type Location = { id: number; name: string };
type Practitioner = { practitioner_id: number; user_id: number; display_name: string; email: string; status: string; discipline: string; credentials: string | null; booking_mode: string; active: number | boolean; location_id: number | null; locations: string | null };
type NewForm = { tenant_id: string; object_id: string; email: string; display_name: string; location_id: string; discipline: string; credentials: string; booking_mode: string };
type EditForm = { practitioner_id: number; email: string; display_name: string; location_id: string; discipline: string; credentials: string; booking_mode: string; status: string; active: boolean };
type PanelMode = 'details' | 'new' | 'edit' | null;
const newForm = (locationId = ''): NewForm => ({ tenant_id: tenantId, object_id: '', email: '', display_name: '', location_id: locationId, discipline: 'Massage Therapy', credentials: '', booking_mode: 'practitioner_managed' });
const editForm = (item: Practitioner, fallbackLocation = ''): EditForm => ({ practitioner_id: item.practitioner_id, display_name: item.display_name, email: item.email, location_id: String(item.location_id ?? fallbackLocation), discipline: item.discipline, credentials: item.credentials ?? '', booking_mode: item.booking_mode, status: item.status, active: Boolean(Number(item.active)) });
const available = (item: Practitioner) => item.status === 'active' && Boolean(Number(item.active));

export function PractitionerAdmin() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [locations, setLocations] = useState<Location[]>([]);
  const [items, setItems] = useState<Practitioner[]>([]);
  const [addForm, setAddForm] = useState<NewForm>(() => newForm());
  const [editing, setEditing] = useState<EditForm | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [panelMode, setPanelMode] = useState<PanelMode>(null);
  const [query, setQuery] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [loadError, setLoadError] = useState('');
  const [panelError, setPanelError] = useState('');
  const [saved, setSaved] = useState('');
  const formGuard = useUnsavedForm();
  const selected = items.find(item => item.practitioner_id === selectedId) ?? null;
  const filteredItems = useMemo(() => {
    const term = query.trim().toLocaleLowerCase();
    return term ? items.filter(item => `${item.display_name} ${item.email} ${item.discipline} ${item.credentials ?? ''} ${item.locations ?? ''}`.toLocaleLowerCase().includes(term)) : items;
  }, [items, query]);

  const load = useCallback(async (preferredId?: number | null) => {
    setLoading(true); setLoadError('');
    try {
      const token = await getAccessToken();
      const [locationResponse, practitionerResponse] = await Promise.all([fetch(`${api}/locations`), fetch(`${api}/admin/practitioners`, { headers: { Authorization: `Bearer ${token}` } })]);
      const [locationBody, practitionerBody] = await Promise.all([locationResponse.json(), practitionerResponse.json()]);
      if (!locationResponse.ok) throw new Error(apiErrorMessage(locationBody, locationResponse.status, t('Unable to load locations.')));
      if (!practitionerResponse.ok) throw new Error(apiErrorMessage(practitionerBody, practitionerResponse.status, t('Unable to load practitioners.')));
      const loadedLocations = normalizeNumericIds<Location[]>(locationBody.data);
      const loadedItems = normalizeNumericIds<Practitioner[]>(practitionerBody.data);
      setLocations(loadedLocations); setItems(loadedItems); setAddForm(current => ({ ...current, location_id: current.location_id || String(loadedLocations[0]?.id ?? '') }));
      setSelectedId(current => { const requested = Number(preferredId ?? current ?? 0) || null; return loadedItems.some(item => item.practitioner_id === requested) ? requested : null; });
    } catch (cause) { setLoadError(cause instanceof Error ? cause.message : t('Unable to load practitioner administration.')); }
    finally { setLoading(false); }
  }, [getAccessToken, t]);
  useEffect(() => { void load(); }, [load]);

  const setAddField = <K extends keyof NewForm>(key: K, value: NewForm[K]) => setAddForm(current => ({ ...current, [key]: value }));
  const setEditField = <K extends keyof EditForm>(key: K, value: EditForm[K]) => setEditing(current => current ? { ...current, [key]: value } : null);
  const startNew = () => { formGuard.markClean(); setAddForm(newForm(String(locations[0]?.id ?? ''))); setEditing(null); setPanelError(''); setPanelMode('new'); };
  const showDetails = () => { if (!selected) return; formGuard.markClean(); setPanelError(''); setPanelMode('details'); };
  const startEdit = (item = selected) => { if (!item) return; formGuard.markClean(); setSelectedId(item.practitioner_id); setEditing(editForm(item, String(locations[0]?.id ?? ''))); setPanelError(''); setPanelMode('edit'); };
  const closePanel = () => { if (formGuard.dirty && !window.confirm(t('Discard your unsaved changes?'))) return; formGuard.markClean(); setPanelMode(null); setEditing(null); setPanelError(''); };

  const create = async (event: FormEvent) => {
    event.preventDefault(); setSaving(true); setPanelError(''); setSaved('');
    try {
      const token = await getAccessToken();
      const response = await fetch(`${api}/admin/practitioners/onboard`, { method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ ...addForm, location_id: Number(addForm.location_id) }) });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to add practitioner.')));
      const savedId = Number(body.data?.id ?? 0) || null; const name = addForm.display_name;
      formGuard.markClean(); setPanelMode(null); await load(savedId); setSaved(t('{{name}} was added as a practitioner.', { name }));
    } catch (cause) { setPanelError(cause instanceof Error ? cause.message : t('Unable to add practitioner.')); }
    finally { setSaving(false); }
  };
  const saveEdit = async (event: FormEvent) => {
    event.preventDefault(); if (!editing) return; setSaving(true); setPanelError(''); setSaved('');
    try {
      const token = await getAccessToken();
      const response = await fetch(`${api}/admin/practitioners/${editing.practitioner_id}`, { method: 'PATCH', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ ...editing, location_id: Number(editing.location_id) }) });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to update practitioner.')));
      const id = editing.practitioner_id, name = editing.display_name;
      formGuard.markClean(); setPanelMode(null); setEditing(null); await load(id); setSaved(t('{{name}} was {{action}}.', { name, action: t('updated') }));
    } catch (cause) { setPanelError(cause instanceof Error ? cause.message : t('Unable to update practitioner.')); }
    finally { setSaving(false); }
  };

  return <Stack spacing={2}>
    <Paper variant="outlined" sx={{ p: 1.5 }}><Stack component="nav" aria-label={t('Practitioner actions')} direction={{ xs: 'column', md: 'row' }} gap={1} alignItems={{ md: 'center' }}>
      <Button variant="contained" startIcon={<Plus size={17}/>} onClick={startNew} disabled={locations.length === 0}>{t('New practitioner')}</Button>
      <Divider orientation="vertical" flexItem sx={{ display: { xs: 'none', md: 'block' }, mx: .5 }}/>
      <Button startIcon={<Eye size={17}/>} disabled={!selected} onClick={showDetails}>{t('Details')}</Button><Button startIcon={<Pencil size={17}/>} disabled={!selected} onClick={() => startEdit()}>{t('Edit')}</Button>
      <TextField size="small" label={t('Filter practitioners')} value={query} onChange={event => setQuery(event.target.value)} sx={{ ml: { md: 'auto' }, minWidth: { md: 270 } }} slotProps={{ input: { startAdornment: <InputAdornment position="start"><Search size={16}/></InputAdornment> } }}/>
    </Stack></Paper>
    {saved && <Alert severity="success" onClose={() => setSaved('')}>{saved}</Alert>}{loadError && <Alert severity="error" action={<Button color="inherit" onClick={() => void load()}>{t('Retry')}</Button>}>{loadError}</Alert>}
    <Paper variant="outlined" sx={{ overflow: 'hidden' }}>
      <Box sx={{ px: 2.5, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}><Typography variant="h5">{t('Practitioners')}</Typography><Typography color="text.secondary">{t('Select a practitioner to view details or enable actions.')}</Typography></Box>
      <List disablePadding aria-label={t('Practitioners')}>{filteredItems.map(item => <ListItemButton key={item.practitioner_id} selected={selectedId === item.practitioner_id} onClick={() => setSelectedId(item.practitioner_id)} divider sx={{ py: 1.75, px: 2.5 }}><ListItemText primary={<Stack direction="row" gap={1} alignItems="center" flexWrap="wrap"><Typography fontWeight={750}>{item.display_name}</Typography><Chip size="small" color={available(item) ? 'success' : 'default'} label={t(available(item) ? 'Active' : 'Inactive')}/></Stack>} secondary={`${item.credentials || item.discipline} · ${item.locations || t('No active location')} · ${t(item.booking_mode === 'practitioner_managed' ? 'Practitioner managed' : 'Clinic managed')}`}/></ListItemButton>)}
        {!loading && filteredItems.length === 0 && <Box sx={{ p: 5, textAlign: 'center' }}><Typography variant="h6">{t(query ? 'No matching practitioners' : 'No practitioners yet')}</Typography><Typography color="text.secondary" mb={2}>{t(query ? 'Try a different name, email, discipline, or location.' : locations.length ? 'Link the first practitioner after creating their Microsoft Entra account.' : 'Create a location before adding practitioners.')}</Typography>{!query && locations.length > 0 && <Button variant="contained" startIcon={<Plus size={17}/>} onClick={startNew}>{t('New practitioner')}</Button>}</Box>}
      </List>
    </Paper>
    <Drawer anchor="right" open={panelMode !== null} onClose={closePanel} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 660 }, maxWidth: '100%' } } }}>
      <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ px: 3, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}><Box><Typography variant="overline" color="primary">{t(panelMode === 'details' ? 'Practitioner details' : panelMode === 'edit' ? 'Edit practitioner' : 'New practitioner')}</Typography><Typography variant="h5">{panelMode === 'new' ? t('Link a practitioner') : selected?.display_name}</Typography></Box><IconButton aria-label={t('Close panel')} onClick={closePanel}><X/></IconButton></Stack>
      {panelMode === 'details' && selected && <PractitionerDetails item={selected} edit={() => startEdit(selected)}/>}
      {panelMode === 'new' && <Box component="form" onSubmit={create} onChange={formGuard.markDirty} sx={{ display: 'flex', flexDirection: 'column', minHeight: 0, flex: 1 }}><Box sx={{ p: 3, overflowY: 'auto', flex: 1 }}><Alert severity="info" sx={{ mb: 3 }}>{t('Create the Microsoft Entra account and assign its Wellness Practitioner app role first, then link that identity here.')} {t('Use the immutable Entra Object ID, not an email address, as the identity key.')}</Alert><NewPractitionerFields form={addForm} locations={locations} field={setAddField}/>{panelError && <Alert severity="error" sx={{ mt: 2 }}>{panelError}</Alert>}</Box><PanelActions close={closePanel} busy={saving} submitLabel={saving ? t('Adding…') : t('Add practitioner')}/></Box>}
      {panelMode === 'edit' && editing && <Box component="form" onSubmit={saveEdit} onChange={formGuard.markDirty} sx={{ display: 'flex', flexDirection: 'column', minHeight: 0, flex: 1 }}><Box sx={{ p: 3, overflowY: 'auto', flex: 1 }}><EditPractitionerFields form={editing} locations={locations} field={setEditField}/>{panelError && <Alert severity="error" sx={{ mt: 2 }}>{panelError}</Alert>}</Box><PanelActions close={closePanel} busy={saving} submitLabel={saving ? t('Saving…') : t('Save changes')}/></Box>}
    </Drawer>
  </Stack>;
}

function PractitionerDetails({ item, edit }: { item: Practitioner; edit: () => void }) {
  const { t } = useTranslation();
  const rows = [[t('Account status'), t(item.status === 'active' ? 'Active' : 'Inactive')], [t('Practitioner availability'), t(Boolean(Number(item.active)) ? 'Available' : 'Unavailable')], [t('Discipline'), item.discipline], [t('Credentials'), item.credentials || t('Not set')], [t('Clinic location'), item.locations || t('No active location')], [t('Booking management'), t(item.booking_mode === 'practitioner_managed' ? 'Practitioner managed' : 'Clinic managed')], [t('Microsoft sign-in email'), item.email]];
  return <Stack spacing={3} sx={{ p: 3, overflowY: 'auto' }}><Stack divider={<Divider flexItem/>}>{rows.map(([label, value]) => <Box key={label} sx={{ py: 1.5 }}><Typography variant="caption" color="text.secondary">{label}</Typography><Typography fontWeight={600}>{value}</Typography></Box>)}</Stack><Button variant="contained" startIcon={<Pencil size={17}/>} onClick={edit}>{t('Edit')}</Button></Stack>;
}

function NewPractitionerFields({ form, locations, field }: { form: NewForm; locations: Location[]; field: <K extends keyof NewForm>(key: K, value: NewForm[K]) => void }) {
  const { t } = useTranslation(); return <Grid container spacing={2}>
    <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth label={t('Display name')} value={form.display_name} onChange={event => field('display_name', event.target.value)} inputProps={{ maxLength: 150 }}/></Grid><Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth type="email" label={t('Microsoft sign-in email')} value={form.email} onChange={event => field('email', event.target.value)} inputProps={{ maxLength: 190 }}/></Grid>
    <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth label={t('Entra Object ID')} value={form.object_id} onChange={event => field('object_id', event.target.value)} helperText={t('Found on the user profile in Microsoft Entra')}/></Grid><Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth label={t('Entra Tenant ID')} value={form.tenant_id} onChange={event => field('tenant_id', event.target.value)}/></Grid>
    <PractitionerProfessionalFields form={form} locations={locations} field={field}/>
  </Grid>;
}

function EditPractitionerFields({ form, locations, field }: { form: EditForm; locations: Location[]; field: <K extends keyof EditForm>(key: K, value: EditForm[K]) => void }) {
  const { t } = useTranslation(); return <Grid container spacing={2}>
    <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth label={t('Display name')} value={form.display_name} onChange={event => field('display_name', event.target.value)} inputProps={{ maxLength: 150 }}/></Grid><Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth type="email" label={t('Email')} value={form.email} onChange={event => field('email', event.target.value)} helperText={t('Changing this does not change the Microsoft Entra account.')} inputProps={{ maxLength: 190 }}/></Grid>
    <PractitionerProfessionalFields form={form} locations={locations} field={field}/>
    <Grid size={{ xs: 12, md: 6 }}><TextField required select fullWidth label={t('Account status')} value={form.status} onChange={event => field('status', event.target.value)}><MenuItem value="active">{t('Active')}</MenuItem><MenuItem value="inactive">{t('Inactive')}</MenuItem></TextField></Grid><Grid size={{ xs: 12, md: 6 }}><FormControlLabel control={<Switch checked={form.active} onChange={event => field('active', event.target.checked)}/>} label={t('Available as a practitioner')}/></Grid>
  </Grid>;
}

function PractitionerProfessionalFields<T extends Pick<NewForm, 'discipline' | 'credentials' | 'location_id' | 'booking_mode'>>({ form, locations, field }: { form: T; locations: Location[]; field: <K extends keyof T>(key: K, value: T[K]) => void }) {
  const { t } = useTranslation(); return <>
    <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth label={t('Discipline')} value={form.discipline} onChange={event => field('discipline', event.target.value as T['discipline'])} inputProps={{ maxLength: 100 }}/></Grid><Grid size={{ xs: 12, md: 6 }}><TextField fullWidth label={t('Credentials')} value={form.credentials} onChange={event => field('credentials', event.target.value as T['credentials'])} inputProps={{ maxLength: 500 }}/></Grid>
    <Grid size={{ xs: 12, md: 6 }}><TextField required select fullWidth label={t('Clinic location')} value={form.location_id} onChange={event => field('location_id', event.target.value as T['location_id'])}>{locations.map(location => <MenuItem key={location.id} value={String(location.id)}>{location.name}</MenuItem>)}</TextField></Grid><Grid size={{ xs: 12, md: 6 }}><TextField required select fullWidth label={t('Booking management')} value={form.booking_mode} onChange={event => field('booking_mode', event.target.value as T['booking_mode'])}><MenuItem value="practitioner_managed">{t('Practitioner managed')}</MenuItem><MenuItem value="clinic_managed">{t('Clinic managed')}</MenuItem></TextField></Grid>
  </>;
}

function PanelActions({ close, busy, submitLabel }: { close: () => void; busy: boolean; submitLabel: string }) {
  const { t } = useTranslation(); return <Stack direction="row" justifyContent="flex-end" gap={1} sx={{ p: 2, borderTop: '1px solid', borderColor: 'divider' }}><Button onClick={close} disabled={busy}>{t('Cancel')}</Button><Button type="submit" variant="contained" disabled={busy} startIcon={<Save size={17}/>}>{submitLabel}</Button></Stack>;
}

import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Alert, Button, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, Grid, MenuItem, Paper, Stack, Switch, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Typography } from '@mui/material';
import { Pencil, Save, UserPlus } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { useUnsavedForm } from '../shared/UnsavedChanges';

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
const tenantId = import.meta.env.VITE_ENTRA_TENANT_ID ?? '';

type Location = { id: number; name: string };
type Practitioner = { practitioner_id: number; display_name: string; email: string; status: string; discipline: string; credentials: string | null; booking_mode: string; active: number | boolean; location_id: number | null; locations: string | null };
type FormState = { tenant_id: string; object_id: string; email: string; display_name: string; location_id: string; discipline: string; credentials: string; booking_mode: string };
type EditState = { practitioner_id: number; email: string; display_name: string; location_id: string; discipline: string; credentials: string; booking_mode: string; status: string; active: boolean };
const emptyForm: FormState = { tenant_id: tenantId, object_id: '', email: '', display_name: '', location_id: '', discipline: 'Massage Therapy', credentials: '', booking_mode: 'practitioner_managed' };

export function PractitionerAdmin() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [locations, setLocations] = useState<Location[]>([]);
  const [practitioners, setPractitioners] = useState<Practitioner[]>([]);
  const [form, setForm] = useState<FormState>(emptyForm);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState('');
  const [editing, setEditing] = useState<EditState | null>(null);
  const addGuard = useUnsavedForm();
  const editGuard = useUnsavedForm();

  const load = useCallback(async () => {
    setLoading(true);setError('');
    try {
      const token = await getAccessToken();
      const [locationResponse, practitionerResponse] = await Promise.all([
        fetch(`${apiBaseUrl}/locations`),
        fetch(`${apiBaseUrl}/admin/practitioners`, { headers: { Authorization: `Bearer ${token}` } }),
      ]);
      const [locationBody, practitionerBody] = await Promise.all([locationResponse.json(), practitionerResponse.json()]);
      if (!locationResponse.ok) throw new Error(apiErrorMessage(locationBody, locationResponse.status, t('Unable to load locations.')));
      if (!practitionerResponse.ok) throw new Error(apiErrorMessage(practitionerBody, practitionerResponse.status, t('Unable to load practitioners.')));
      const loadedLocations = normalizeNumericIds<Location[]>(locationBody.data);
      setLocations(loadedLocations);setPractitioners(normalizeNumericIds(practitionerBody.data));
      setForm(current => ({ ...current, location_id: current.location_id || String(loadedLocations[0]?.id ?? '') }));
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to load practitioner administration.')); }
    finally { setLoading(false); }
  }, [getAccessToken, t]);

  useEffect(() => { void load(); }, [load]);
  const setField = (field: keyof FormState, value: string) => setForm(current => ({ ...current, [field]: value }));
  const submit = async (event: FormEvent) => {
    event.preventDefault();setSaving(true);setError('');setSaved('');
    try {
      const token = await getAccessToken();
      const response = await fetch(`${apiBaseUrl}/admin/practitioners/onboard`, { method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ ...form, location_id: Number(form.location_id) }) });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to add practitioner.')));
      addGuard.markClean();setSaved(t('{{name}} was added as a practitioner.', { name: form.display_name }));setForm({ ...emptyForm, location_id: form.location_id });await load();
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to add practitioner.')); }
    finally { setSaving(false); }
  };

  const startEditing = (practitioner: Practitioner) => {
    editGuard.markClean();
    setError('');setSaved('');
    setEditing({ practitioner_id: practitioner.practitioner_id, display_name: practitioner.display_name, email: practitioner.email, location_id: String(practitioner.location_id ?? locations[0]?.id ?? ''), discipline: practitioner.discipline, credentials: practitioner.credentials ?? '', booking_mode: practitioner.booking_mode, status: practitioner.status, active: Boolean(Number(practitioner.active)) });
  };
  const setEditField = <K extends keyof EditState>(field: K, value: EditState[K]) => setEditing(current => current ? { ...current, [field]: value } : null);
  const saveEdit = async (event: FormEvent) => {
    event.preventDefault();if(!editing)return;setSaving(true);setError('');setSaved('');
    try {
      const token=await getAccessToken();
      const response=await fetch(`${apiBaseUrl}/admin/practitioners/${editing.practitioner_id}`,{method:'PATCH',headers:{Authorization:`Bearer ${token}`,'Content-Type':'application/json'},body:JSON.stringify({...editing,location_id:Number(editing.location_id)})});
      const body=await response.json();if(!response.ok)throw new Error(apiErrorMessage(body,response.status,t('Unable to update practitioner.')));
      const name=editing.display_name;editGuard.markClean();setEditing(null);await load();setSaved(t('{{name}} was {{action}}.', { name, action: t('updated') }));
    } catch(cause){setError(cause instanceof Error?cause.message:t('Unable to update practitioner.'));}
    finally{setSaving(false);}
  };

  return <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 } }}>
    <Typography variant="h5">{t('Add a practitioner')}</Typography>
    <Typography color="text.secondary" mb={2}>{t('Create the Microsoft Entra account and assign its Wellness Practitioner app role first, then link that identity here.')}</Typography>
    <Alert severity="info" sx={{ mb: 3 }}>{t('Use the immutable Entra Object ID, not an email address, as the identity key.')}</Alert>
    <Stack component="form" onSubmit={submit} onChange={addGuard.markDirty} spacing={2} mb={4}>
      <Grid container spacing={2}>
        <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth label={t('Display name')} value={form.display_name} onChange={event => setField('display_name', event.target.value)} inputProps={{ maxLength: 150 }}/></Grid>
        <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth type="email" label={t('Microsoft sign-in email')} value={form.email} onChange={event => setField('email', event.target.value)} inputProps={{ maxLength: 190 }}/></Grid>
        <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth label={t('Entra Object ID')} value={form.object_id} onChange={event => setField('object_id', event.target.value)} helperText={t('Found on the user profile in Microsoft Entra')}/></Grid>
        <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth label={t('Entra Tenant ID')} value={form.tenant_id} onChange={event => setField('tenant_id', event.target.value)}/></Grid>
        <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth label={t('Discipline')} value={form.discipline} onChange={event => setField('discipline', event.target.value)} inputProps={{ maxLength: 100 }}/></Grid>
        <Grid size={{ xs: 12, md: 6 }}><TextField fullWidth label={t('Credentials')} value={form.credentials} onChange={event => setField('credentials', event.target.value)} inputProps={{ maxLength: 500 }}/></Grid>
        <Grid size={{ xs: 12, md: 6 }}><TextField required select fullWidth label={t('Clinic location')} value={form.location_id} onChange={event => setField('location_id', event.target.value)}>{locations.map(location => <MenuItem key={location.id} value={String(location.id)}>{location.name}</MenuItem>)}</TextField></Grid>
        <Grid size={{ xs: 12, md: 6 }}><TextField required select fullWidth label={t('Booking management')} value={form.booking_mode} onChange={event => setField('booking_mode', event.target.value)}><MenuItem value="practitioner_managed">{t('Practitioner managed')}</MenuItem><MenuItem value="clinic_managed">{t('Clinic managed')}</MenuItem></TextField></Grid>
      </Grid>
      {error && <Alert severity="error">{error}</Alert>}
      {saved && <Alert severity="success">{saved}</Alert>}
      <Button type="submit" variant="contained" startIcon={<UserPlus size={18}/>} disabled={saving || loading || locations.length === 0} sx={{ alignSelf: 'flex-start' }}>{saving ? t('Adding…') : t('Add practitioner')}</Button>
    </Stack>
    <Typography variant="h6" mb={1}>{t('Current practitioners')}</Typography>
    <TableContainer>
      <Table size="small">
        <TableHead><TableRow><TableCell>{t('Name')}</TableCell><TableCell>{t('Discipline')}</TableCell><TableCell>{t('Location')}</TableCell><TableCell>{t('Booking')}</TableCell><TableCell>{t('Status')}</TableCell><TableCell align="right">{t('Actions')}</TableCell></TableRow></TableHead>
        <TableBody>{practitioners.map(practitioner => <TableRow key={practitioner.practitioner_id} hover><TableCell><Typography fontWeight={600}>{practitioner.display_name}</Typography><Typography variant="caption" color="text.secondary">{practitioner.email}</Typography></TableCell><TableCell>{practitioner.credentials || practitioner.discipline}</TableCell><TableCell>{practitioner.locations || '—'}</TableCell><TableCell>{t(practitioner.booking_mode === 'practitioner_managed' ? 'Practitioner' : 'Clinic')}</TableCell><TableCell>{t(practitioner.status === 'active' && Boolean(Number(practitioner.active)) ? 'Active' : 'Inactive')}</TableCell><TableCell align="right"><Button size="small" startIcon={<Pencil size={16}/>} onClick={()=>startEditing(practitioner)}>{t('Edit')}</Button></TableCell></TableRow>)}</TableBody>
      </Table>
    </TableContainer>
    <Dialog open={editing!==null} onClose={()=>{if(!saving){editGuard.markClean();setEditing(null);}}} fullWidth maxWidth="sm" component="form" onSubmit={saveEdit} onChange={editGuard.markDirty}>
      <DialogTitle>{t('Edit practitioner')}</DialogTitle>
      {editing&&<DialogContent><Stack spacing={2} pt={1}>
        <TextField required label={t('Display name')} value={editing.display_name} onChange={event=>setEditField('display_name',event.target.value)} inputProps={{maxLength:150}}/>
        <TextField required type="email" label={t('Email')} value={editing.email} onChange={event=>setEditField('email',event.target.value)} helperText={t('Changing this does not change the Microsoft Entra account.')} inputProps={{maxLength:190}}/>
        <TextField required label={t('Discipline')} value={editing.discipline} onChange={event=>setEditField('discipline',event.target.value)} inputProps={{maxLength:100}}/>
        <TextField label={t('Credentials')} value={editing.credentials} onChange={event=>setEditField('credentials',event.target.value)} inputProps={{maxLength:500}}/>
        <TextField required select label={t('Clinic location')} value={editing.location_id} onChange={event=>setEditField('location_id',event.target.value)}>{locations.map(location=><MenuItem key={location.id} value={String(location.id)}>{location.name}</MenuItem>)}</TextField>
        <TextField required select label={t('Booking management')} value={editing.booking_mode} onChange={event=>setEditField('booking_mode',event.target.value)}><MenuItem value="practitioner_managed">{t('Practitioner managed')}</MenuItem><MenuItem value="clinic_managed">{t('Clinic managed')}</MenuItem></TextField>
        <TextField required select label={t('Account status')} value={editing.status} onChange={event=>setEditField('status',event.target.value)}><MenuItem value="active">{t('Active')}</MenuItem><MenuItem value="inactive">{t('Inactive')}</MenuItem></TextField>
        <FormControlLabel control={<Switch checked={editing.active} onChange={event=>setEditField('active',event.target.checked)}/>} label={t('Available as a practitioner')}/>
        {error&&<Alert severity="error">{error}</Alert>}
      </Stack></DialogContent>}
      <DialogActions><Button onClick={()=>{editGuard.markClean();setEditing(null);}} disabled={saving}>{t('Cancel')}</Button><Button type="submit" variant="contained" startIcon={<Save size={17}/>} disabled={saving}>{saving?t('Saving…'):t('Save changes')}</Button></DialogActions>
    </Dialog>
  </Paper>;
}

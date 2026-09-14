import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Alert, Button, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, Grid, MenuItem, Paper, Stack, Switch, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Typography } from '@mui/material';
import { Pencil, Save, UserPlus } from 'lucide-react';
import { useStaffAuth } from '../auth/AuthProvider';

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
const tenantId = import.meta.env.VITE_ENTRA_TENANT_ID ?? '';

type Location = { id: number; name: string };
type Practitioner = { practitioner_id: number; display_name: string; email: string; status: string; discipline: string; credentials: string | null; booking_mode: string; active: number | boolean; location_id: number | null; locations: string | null };
type FormState = { tenant_id: string; object_id: string; email: string; display_name: string; location_id: string; discipline: string; credentials: string; booking_mode: string };
type EditState = { practitioner_id: number; email: string; display_name: string; location_id: string; discipline: string; credentials: string; booking_mode: string; status: string; active: boolean };
const emptyForm: FormState = { tenant_id: tenantId, object_id: '', email: '', display_name: '', location_id: '', discipline: 'Massage Therapy', credentials: '', booking_mode: 'practitioner_managed' };

export function PractitionerAdmin() {
  const { getAccessToken } = useStaffAuth();
  const [locations, setLocations] = useState<Location[]>([]);
  const [practitioners, setPractitioners] = useState<Practitioner[]>([]);
  const [form, setForm] = useState<FormState>(emptyForm);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState('');
  const [editing, setEditing] = useState<EditState | null>(null);

  const load = useCallback(async () => {
    setLoading(true);setError('');
    try {
      const token = await getAccessToken();
      const [locationResponse, practitionerResponse] = await Promise.all([
        fetch(`${apiBaseUrl}/locations`),
        fetch(`${apiBaseUrl}/admin/practitioners`, { headers: { Authorization: `Bearer ${token}` } }),
      ]);
      const [locationBody, practitionerBody] = await Promise.all([locationResponse.json(), practitionerResponse.json()]);
      if (!locationResponse.ok) throw new Error(locationBody?.error?.message ?? 'Unable to load locations.');
      if (!practitionerResponse.ok) throw new Error(practitionerBody?.error?.message ?? 'Unable to load practitioners.');
      setLocations(locationBody.data);setPractitioners(practitionerBody.data);
      setForm(current => ({ ...current, location_id: current.location_id || String(locationBody.data[0]?.id ?? '') }));
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Unable to load practitioner administration.'); }
    finally { setLoading(false); }
  }, [getAccessToken]);

  useEffect(() => { void load(); }, [load]);
  const setField = (field: keyof FormState, value: string) => setForm(current => ({ ...current, [field]: value }));
  const submit = async (event: FormEvent) => {
    event.preventDefault();setSaving(true);setError('');setSaved('');
    try {
      const token = await getAccessToken();
      const response = await fetch(`${apiBaseUrl}/admin/practitioners/onboard`, { method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ ...form, location_id: Number(form.location_id) }) });
      const body = await response.json();
      if (!response.ok) throw new Error(body?.error?.message ?? 'Unable to add practitioner.');
      setSaved(`${form.display_name} was added as a practitioner.`);setForm({ ...emptyForm, location_id: form.location_id });await load();
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Unable to add practitioner.'); }
    finally { setSaving(false); }
  };

  const startEditing = (practitioner: Practitioner) => {
    setError('');setSaved('');
    setEditing({ practitioner_id: practitioner.practitioner_id, display_name: practitioner.display_name, email: practitioner.email, location_id: String(practitioner.location_id ?? locations[0]?.id ?? ''), discipline: practitioner.discipline, credentials: practitioner.credentials ?? '', booking_mode: practitioner.booking_mode, status: practitioner.status, active: Boolean(Number(practitioner.active)) });
  };
  const setEditField = <K extends keyof EditState>(field: K, value: EditState[K]) => setEditing(current => current ? { ...current, [field]: value } : null);
  const saveEdit = async (event: FormEvent) => {
    event.preventDefault();if(!editing)return;setSaving(true);setError('');setSaved('');
    try {
      const token=await getAccessToken();
      const response=await fetch(`${apiBaseUrl}/admin/practitioners/${editing.practitioner_id}`,{method:'PATCH',headers:{Authorization:`Bearer ${token}`,'Content-Type':'application/json'},body:JSON.stringify({...editing,location_id:Number(editing.location_id)})});
      const body=await response.json();if(!response.ok)throw new Error(body?.error?.message??'Unable to update practitioner.');
      const name=editing.display_name;setEditing(null);await load();setSaved(`${name} was updated.`);
    } catch(cause){setError(cause instanceof Error?cause.message:'Unable to update practitioner.');}
    finally{setSaving(false);}
  };

  return <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 } }}>
    <Typography variant="h5">Add a practitioner</Typography>
    <Typography color="text.secondary" mb={2}>Create the Microsoft Entra account and assign its Wellness Practitioner app role first, then link that identity here.</Typography>
    <Alert severity="info" sx={{ mb: 3 }}>Use the immutable Entra Object ID, not an email address, as the identity key.</Alert>
    <Stack component="form" onSubmit={submit} spacing={2} mb={4}>
      <Grid container spacing={2}>
        <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth label="Display name" value={form.display_name} onChange={event => setField('display_name', event.target.value)} inputProps={{ maxLength: 150 }}/></Grid>
        <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth type="email" label="Microsoft sign-in email" value={form.email} onChange={event => setField('email', event.target.value)} inputProps={{ maxLength: 190 }}/></Grid>
        <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth label="Entra Object ID" value={form.object_id} onChange={event => setField('object_id', event.target.value)} helperText="Found on the user profile in Microsoft Entra"/></Grid>
        <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth label="Entra Tenant ID" value={form.tenant_id} onChange={event => setField('tenant_id', event.target.value)}/></Grid>
        <Grid size={{ xs: 12, md: 6 }}><TextField required fullWidth label="Discipline" value={form.discipline} onChange={event => setField('discipline', event.target.value)} inputProps={{ maxLength: 100 }}/></Grid>
        <Grid size={{ xs: 12, md: 6 }}><TextField fullWidth label="Credentials" value={form.credentials} onChange={event => setField('credentials', event.target.value)} inputProps={{ maxLength: 500 }}/></Grid>
        <Grid size={{ xs: 12, md: 6 }}><TextField required select fullWidth label="Clinic location" value={form.location_id} onChange={event => setField('location_id', event.target.value)}>{locations.map(location => <MenuItem key={location.id} value={String(location.id)}>{location.name}</MenuItem>)}</TextField></Grid>
        <Grid size={{ xs: 12, md: 6 }}><TextField required select fullWidth label="Booking management" value={form.booking_mode} onChange={event => setField('booking_mode', event.target.value)}><MenuItem value="practitioner_managed">Practitioner managed</MenuItem><MenuItem value="clinic_managed">Clinic managed</MenuItem></TextField></Grid>
      </Grid>
      {error && <Alert severity="error">{error}</Alert>}
      {saved && <Alert severity="success">{saved}</Alert>}
      <Button type="submit" variant="contained" startIcon={<UserPlus size={18}/>} disabled={saving || loading || locations.length === 0} sx={{ alignSelf: 'flex-start' }}>{saving ? 'Adding…' : 'Add practitioner'}</Button>
    </Stack>
    <Typography variant="h6" mb={1}>Current practitioners</Typography>
    <TableContainer>
      <Table size="small">
        <TableHead><TableRow><TableCell>Name</TableCell><TableCell>Discipline</TableCell><TableCell>Location</TableCell><TableCell>Booking</TableCell><TableCell>Status</TableCell><TableCell align="right">Actions</TableCell></TableRow></TableHead>
        <TableBody>{practitioners.map(practitioner => <TableRow key={practitioner.practitioner_id} hover><TableCell><Typography fontWeight={600}>{practitioner.display_name}</Typography><Typography variant="caption" color="text.secondary">{practitioner.email}</Typography></TableCell><TableCell>{practitioner.credentials || practitioner.discipline}</TableCell><TableCell>{practitioner.locations || '—'}</TableCell><TableCell>{practitioner.booking_mode === 'practitioner_managed' ? 'Practitioner' : 'Clinic'}</TableCell><TableCell>{practitioner.status === 'active' && Boolean(Number(practitioner.active)) ? 'Active' : 'Inactive'}</TableCell><TableCell align="right"><Button size="small" startIcon={<Pencil size={16}/>} onClick={()=>startEditing(practitioner)}>Edit</Button></TableCell></TableRow>)}</TableBody>
      </Table>
    </TableContainer>
    <Dialog open={editing!==null} onClose={()=>!saving&&setEditing(null)} fullWidth maxWidth="sm" component="form" onSubmit={saveEdit}>
      <DialogTitle>Edit practitioner</DialogTitle>
      {editing&&<DialogContent><Stack spacing={2} pt={1}>
        <TextField required label="Display name" value={editing.display_name} onChange={event=>setEditField('display_name',event.target.value)} inputProps={{maxLength:150}}/>
        <TextField required type="email" label="Email" value={editing.email} onChange={event=>setEditField('email',event.target.value)} helperText="Changing this does not change the Microsoft Entra account." inputProps={{maxLength:190}}/>
        <TextField required label="Discipline" value={editing.discipline} onChange={event=>setEditField('discipline',event.target.value)} inputProps={{maxLength:100}}/>
        <TextField label="Credentials" value={editing.credentials} onChange={event=>setEditField('credentials',event.target.value)} inputProps={{maxLength:500}}/>
        <TextField required select label="Clinic location" value={editing.location_id} onChange={event=>setEditField('location_id',event.target.value)}>{locations.map(location=><MenuItem key={location.id} value={String(location.id)}>{location.name}</MenuItem>)}</TextField>
        <TextField required select label="Booking management" value={editing.booking_mode} onChange={event=>setEditField('booking_mode',event.target.value)}><MenuItem value="practitioner_managed">Practitioner managed</MenuItem><MenuItem value="clinic_managed">Clinic managed</MenuItem></TextField>
        <TextField required select label="Account status" value={editing.status} onChange={event=>setEditField('status',event.target.value)}><MenuItem value="active">Active</MenuItem><MenuItem value="inactive">Inactive</MenuItem></TextField>
        <FormControlLabel control={<Switch checked={editing.active} onChange={event=>setEditField('active',event.target.checked)}/>} label="Available as a practitioner"/>
        {error&&<Alert severity="error">{error}</Alert>}
      </Stack></DialogContent>}
      <DialogActions><Button onClick={()=>setEditing(null)} disabled={saving}>Cancel</Button><Button type="submit" variant="contained" startIcon={<Save size={17}/>} disabled={saving}>{saving?'Saving…':'Save changes'}</Button></DialogActions>
    </Dialog>
  </Paper>;
}

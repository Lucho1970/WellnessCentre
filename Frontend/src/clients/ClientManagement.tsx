import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Alert, Box, Button, Chip, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, Grid, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { ArrowLeft, ArrowRight, Merge, Plus, Search, Users } from 'lucide-react';
import { useStaffAuth } from '../auth/AuthProvider';
import { ClientInvitations } from './ClientInvitations';
import { useTranslation } from 'react-i18next';
import { apiErrorMessage } from '../shared/api';
import { AddressEntry, type AddressValue } from '../shared/AddressEntry';
import { useUnsavedChanges } from '../shared/UnsavedChanges';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type Summary = { id: number; display_name: string; email: string; phone: string | null; status: string };
const emptyAddress: AddressValue = { address_line1: '', address_line2: '', city: '', province: 'Ontario', postal_code: '', country: 'Canada', instructions: '' };
const empty = { given_name: '', family_name: '', email: '', phone: '', preferred_contact: 'email', date_of_birth: '', emergency_contact_name: '', emergency_contact_phone: '', administrative_notes: '', status: 'active', revision: '', address: emptyAddress };
type Form = typeof empty;
type Detail = Summary & Form;
type Candidate = Summary & { date_of_birth?: string | null };
type MergePreview = { survivor: Detail; duplicate: Detail; relationship_counts: Record<string, number>; blocked: boolean; blocked_reason: string | null };
class ClientRequestError extends Error { constructor(message:string,public code='',public fields:Record<string,unknown>={}){super(message);} }

export function ClientManagement({ canMerge = false }: { canMerge?: boolean }) {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [query, setQuery] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [more, setMore] = useState(false);
  const [items, setItems] = useState<Summary[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [opening, setOpening] = useState(false);
  const [error, setError] = useState('');
  const [formError, setFormError] = useState('');
  const [notice, setNotice] = useState('');
  const [refresh, setRefresh] = useState(0);
  const [open, setOpen] = useState(false);
  const [id, setId] = useState<number | null>(null);
  const [form, setForm] = useState<Form>(empty);
  const [original, setOriginal] = useState<Form>(empty);
  const [duplicateCandidates, setDuplicateCandidates] = useState<Candidate[]>([]);
  const [mergeOpen,setMergeOpen]=useState(false);
  const [mergeDuplicate,setMergeDuplicate]=useState<Summary|null>(null);
  const [mergeSearch,setMergeSearch]=useState('');
  const [mergeResults,setMergeResults]=useState<Summary[]>([]);
  const [mergeSurvivor,setMergeSurvivor]=useState<Summary|null>(null);
  const [mergePreview,setMergePreview]=useState<MergePreview|null>(null);
  const [mergeReason,setMergeReason]=useState('');
  const [primaryEmailSource,setPrimaryEmailSource]=useState<'survivor'|'duplicate'>('survivor');
  const [profileSource,setProfileSource]=useState<'survivor'|'duplicate'>('survivor');
  const [addressSource,setAddressSource]=useState<'survivor'|'duplicate'>('survivor');
  const [mergeConfirmation,setMergeConfirmation]=useState('');
  const [mergeBusy,setMergeBusy]=useState(false);
  const [mergeError,setMergeError]=useState('');
  useUnsavedChanges(
    (open && JSON.stringify(form) !== JSON.stringify(original)) ||
    (mergeOpen && mergePreview !== null && (mergeReason.trim() !== '' || mergeConfirmation.trim() !== '' || primaryEmailSource !== 'survivor' || profileSource !== 'survivor' || addressSource !== 'survivor')),
  );

  const request = useCallback(async (path: string, init: RequestInit = {}) => {
    const token = await getAccessToken();
    const response = await fetch(`${api}/clients${path}`, { ...init, headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', ...init.headers } });
    const body = await response.json();
    if (!response.ok) throw new ClientRequestError(apiErrorMessage(body, response.status, t('Unable to complete the client request.')),typeof body?.error?.code==='string'?body.error.code:'',body?.error?.fields??{});
    return body.data;
  }, [getAccessToken, t]);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true); setError('');
    void request(`?q=${encodeURIComponent(search)}&page=${page}`, { signal: controller.signal })
      .then(data => { if (!controller.signal.aborted) { setItems(data.items); setMore(data.has_more); } })
      .catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : t('Unable to load clients.')); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [request, search, page, refresh]);

  const newClient = () => { const values={ ...empty, address:{...emptyAddress} }; setId(null); setForm(values); setOriginal(values); setFormError(''); setDuplicateCandidates([]); setOpen(true); };
  const edit = async (clientId: number) => {
    setOpening(true); setError('');
    try {
      const client: Detail = await request(`/${clientId}`);
      const values = { ...empty, ...Object.fromEntries(Object.keys(empty).filter(key=>key!=='address').map(key => [key, client[key as keyof Form] ?? ''])), address: client.address ? { ...emptyAddress, ...client.address } : { ...emptyAddress } } as Form;
      setId(clientId); setForm(values); setOriginal(values); setFormError(''); setDuplicateCandidates([]); setOpen(true);
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to open client.')); }
    finally { setOpening(false); }
  };
  const close = () => {
    if (saving) return;
    if (JSON.stringify(form) !== JSON.stringify(original) && !window.confirm(t('Discard your unsaved client changes?'))) return;
    setOpen(false);
  };
  const saveClient = async (confirmPossibleDuplicate=false) => {
    setSaving(true); setFormError(''); setDuplicateCandidates([]);
    try {
      await request(id === null ? '' : `/${id}`, { method: id === null ? 'POST' : 'PATCH', body: JSON.stringify({...form,confirm_possible_duplicate:confirmPossibleDuplicate}) });
      setNotice(t(id === null ? 'Client created. The record is ready for booking.' : 'Client details saved.'));
      setOpen(false); setRefresh(value => value + 1);
    } catch (cause) { if(cause instanceof ClientRequestError&&cause.code==='possible_duplicate'&&Array.isArray(cause.fields.candidates))setDuplicateCandidates(cause.fields.candidates as Candidate[]); setFormError(cause instanceof Error ? cause.message : t('Unable to save client.')); }
    finally { setSaving(false); }
  };
  const save = (event: FormEvent) => { event.preventDefault(); void saveClient(false); };
  const beginMerge=(client:Summary)=>{setMergeDuplicate(client);setMergeSearch('');setMergeResults([]);setMergeSurvivor(null);setMergePreview(null);setMergeReason('');setPrimaryEmailSource('survivor');setProfileSource('survivor');setAddressSource('survivor');setMergeConfirmation('');setMergeError('');setMergeOpen(true);};
  const searchMerge=async()=>{if(!mergeDuplicate||mergeSearch.trim().length<2)return;setMergeBusy(true);setMergeError('');try{const data=await request(`?q=${encodeURIComponent(mergeSearch.trim())}&page=1`);setMergeResults((data.items as Summary[]).filter(item=>item.id!==mergeDuplicate.id));}catch(cause){setMergeError(cause instanceof Error?cause.message:t('Unable to load clients.'));}finally{setMergeBusy(false);}};
  const chooseSurvivor=async(client:Summary)=>{if(!mergeDuplicate)return;setMergeBusy(true);setMergeError('');setMergeSurvivor(client);try{setMergePreview(await request(`/${client.id}/merge-preview/${mergeDuplicate.id}`));setMergeConfirmation('');}catch(cause){setMergePreview(null);setMergeError(cause instanceof Error?cause.message:t('Unable to review this merge.'));}finally{setMergeBusy(false);}};
  const completeMerge=async()=>{if(!mergeDuplicate||!mergeSurvivor||!mergePreview)return;setMergeBusy(true);setMergeError('');try{await request(`/${mergeSurvivor.id}/merge/${mergeDuplicate.id}`,{method:'POST',body:JSON.stringify({survivor_revision:mergePreview.survivor.revision,duplicate_revision:mergePreview.duplicate.revision,primary_email_source:primaryEmailSource,profile_source:profileSource,address_source:addressSource,reason:mergeReason,confirmation:mergeConfirmation})});setMergeOpen(false);setNotice(t('Client records merged. Both email addresses were preserved.'));setRefresh(value=>value+1);}catch(cause){setMergeError(cause instanceof Error?cause.message:t('Unable to merge client records.'));}finally{setMergeBusy(false);}};
  const field = (key: keyof Form, label: string, options: { required?: boolean; type?: string; maxLength?: number } = {}) => <TextField
    fullWidth label={label} value={form[key]} required={options.required} type={options.type ?? 'text'} disabled={saving}
    onChange={event => setForm(current => ({ ...current, [key]: event.target.value }))}
    inputProps={{ maxLength: options.maxLength, ...(options.type === 'date' ? { max: new Date().toISOString().slice(0, 10) } : {}) }}
    InputLabelProps={options.type === 'date' ? { shrink: true } : undefined}
  />;

  return <Stack spacing={3}>
    <Paper variant="outlined" sx={{ p: 3 }}>
      <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ xs: 'flex-start', sm: 'center' }} gap={2}>
        <Box><Typography variant="h5">{t('Client directory')}</Typography><Typography color="text.secondary">{t('Contact details and booking records for your clinic.')}</Typography></Box>
        <Button variant="contained" startIcon={<Plus size={18} />} onClick={newClient} disabled={opening}>{t('Add client')}</Button>
      </Stack>
      <Stack component="form" direction="row" gap={1} mt={3} onSubmit={(event: FormEvent) => { event.preventDefault(); setSearch(query.trim()); setPage(1); setRefresh(value => value + 1); }}>
        <TextField fullWidth size="small" label={t('Search name, email, or phone')} value={query} inputProps={{ maxLength: 190 }} onChange={event => setQuery(event.target.value)} />
        <Button type="submit" variant="outlined" startIcon={<Search size={18} />}>{t('Search')}</Button>
      </Stack>
    </Paper>
    {notice && <Alert severity="success" onClose={() => setNotice('')}>{notice}</Alert>}
    {error && <Alert severity="error" action={<Button color="inherit" onClick={() => setRefresh(value => value + 1)}>{t('Retry')}</Button>}>{error}</Alert>}
    {loading ? <Stack alignItems="center" p={4}><CircularProgress aria-label={t('Loading clients')} /></Stack> : !error && <>
      {items.length === 0 ? <Paper variant="outlined" sx={{ p: 5, textAlign: 'center' }}><Users size={32} /><Typography variant="h6" mt={1}>{t(search ? 'No matching clients' : 'No clients yet')}</Typography><Typography color="text.secondary">{t(search ? 'Try a different name, email, or phone number.' : 'Add your first client to prepare for appointment booking.')}</Typography></Paper> :
        <Stack spacing={1.5}>{items.map(client => <Paper variant="outlined" key={client.id} sx={{ p: 2.5 }}><Stack direction={{ xs: 'column', sm: 'row' }} gap={2} alignItems={{ xs: 'flex-start', sm: 'center' }} justifyContent="space-between">
          <Box sx={{ minWidth: 0, overflowWrap: 'anywhere' }}><Stack direction="row" gap={1} alignItems="center"><Typography fontWeight={700}>{client.display_name}</Typography><Chip size="small" label={client.status} color={client.status === 'active' ? 'success' : 'default'} variant="outlined" /></Stack><Typography color="text.secondary">{client.email}</Typography>{client.phone && <Typography color="text.secondary">{client.phone}</Typography>}</Box>
          <Stack direction="row" gap={1}><Button variant="outlined" disabled={opening} aria-label={t('Edit {{name}}',{name:client.display_name})} onClick={() => void edit(client.id)}>{t('View / edit')}</Button>{canMerge&&client.status!=='inactive'&&<Button color="warning" variant="outlined" startIcon={<Merge size={16}/>} onClick={()=>beginMerge(client)}>{t('Merge duplicate')}</Button>}</Stack>
        </Stack></Paper>)}</Stack>}
      <Stack direction="row" alignItems="center" justifyContent="space-between"><Button startIcon={<ArrowLeft size={16} />} disabled={page === 1} onClick={() => setPage(value => value - 1)}>{t('Previous')}</Button><Typography color="text.secondary">{t('Page {{page}}',{page})}</Typography><Button endIcon={<ArrowRight size={16} />} disabled={!more} onClick={() => setPage(value => value + 1)}>{t('Next')}</Button></Stack>
    </>}
    <Dialog open={open} onClose={close} fullWidth maxWidth="md">
      <Box component="form" onSubmit={save}>
        <DialogTitle>{t(id === null ? 'Add client' : 'Edit client')}</DialogTitle>
        <DialogContent dividers>
          <Typography color="text.secondary" mb={3}>{t('Saving a client record does not create a sign-in account or send an email.')}</Typography>
          <Grid container spacing={2}>
            <Grid size={{ xs: 12, sm: 6 }}>{field('given_name', t('First name'), { required: true, maxLength: 100 })}</Grid>
            <Grid size={{ xs: 12, sm: 6 }}>{field('family_name', t('Last name'), { required: true, maxLength: 100 })}</Grid>
            <Grid size={{ xs: 12, sm: 6 }}>{field('email', t('Email'), { required: true, type: 'email', maxLength: 190 })}</Grid>
            <Grid size={{ xs: 12, sm: 6 }}>{field('phone', t('Phone'), { type: 'tel', maxLength: 40, required: form.preferred_contact === 'phone' })}</Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField fullWidth select label={t('Preferred contact')} disabled={saving} value={form.preferred_contact} onChange={event => setForm(current => ({ ...current, preferred_contact: event.target.value }))}><MenuItem value="email">{t('Email')}</MenuItem><MenuItem value="phone">{t('Phone')}</MenuItem></TextField></Grid>
            <Grid size={{ xs: 12, sm: 6 }}>{field('date_of_birth', t('Date of birth (optional)'), { type: 'date' })}</Grid>
            <Grid size={12}><Typography variant="subtitle1" fontWeight={700}>{t('Service address (optional)')}</Typography><Typography variant="body2" color="text.secondary" mb={1}>{t('Used for mobile visits. Existing appointment destination snapshots do not change when this address is edited.')}</Typography><AddressEntry showInstructions disabled={saving} value={form.address} onChange={address=>setForm(current=>({...current,address}))}/></Grid>
            <Grid size={12}><Typography variant="subtitle1" fontWeight={700}>{t('Emergency contact')}</Typography></Grid>
            <Grid size={{ xs: 12, sm: 6 }}>{field('emergency_contact_name', t('Contact name (optional)'), { maxLength: 150 })}</Grid>
            <Grid size={{ xs: 12, sm: 6 }}>{field('emergency_contact_phone', t('Contact phone (optional)'), { type: 'tel', maxLength: 40 })}</Grid>
            <Grid size={12}><TextField fullWidth multiline minRows={3} disabled={saving} label={t('Administrative notes (optional)')} helperText={t('Booking and contact notes only. Do not enter treatment or clinical notes here.')} value={form.administrative_notes} inputProps={{ maxLength: 4000 }} onChange={event => setForm(current => ({ ...current, administrative_notes: event.target.value }))} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField fullWidth select disabled={saving} label={t('Status')} helperText={t('Inactive clients cannot receive new bookings.')} value={form.status} onChange={event => setForm(current => ({ ...current, status: event.target.value }))}><MenuItem value="active">{t('Active')}</MenuItem><MenuItem value="inactive">{t('Inactive')}</MenuItem>{!['active', 'inactive'].includes(form.status) && <MenuItem value={form.status}>{t('{{status}} — choose a new status',{status:form.status})}</MenuItem>}</TextField></Grid>
          </Grid>
          {formError && <Alert severity="error" sx={{ mt: 2 }}>{formError}</Alert>}
          {duplicateCandidates.length>0&&<Alert severity="warning" sx={{mt:2}} action={<Button color="inherit" disabled={saving} onClick={()=>void saveClient(true)}>{t('Create anyway')}</Button>}><Typography fontWeight={700}>{t('Possible duplicate client')}</Typography>{duplicateCandidates.map(candidate=><Typography key={candidate.id} variant="body2">{candidate.display_name} — {candidate.email}{candidate.phone?` — ${candidate.phone}`:''}</Typography>)}</Alert>}
          {id !== null && <ClientInvitations key={id} clientId={id} request={request} />}
        </DialogContent>
        <DialogActions sx={{ p: 2 }}><Button onClick={close} disabled={saving}>{t('Cancel')}</Button><Button type="submit" variant="contained" disabled={saving}>{t(saving ? 'Saving…' : 'Save client')}</Button></DialogActions>
      </Box>
    </Dialog>
    <Dialog open={mergeOpen} onClose={()=>!mergeBusy&&setMergeOpen(false)} fullWidth maxWidth="md">
      <DialogTitle>{t('Merge duplicate client')}</DialogTitle><DialogContent dividers><Stack spacing={2}>
        <Alert severity="warning">{t('This permanently reassigns the duplicate client’s records to the survivor and makes the duplicate inactive. It does not delete audit history.')}</Alert>
        {mergeDuplicate&&<Paper variant="outlined" sx={{p:2}}><Typography variant="overline">{t('Duplicate record')}</Typography><Typography fontWeight={700}>{mergeDuplicate.display_name}</Typography><Typography>{mergeDuplicate.email}</Typography></Paper>}
        {!mergePreview&&<><Stack component="form" direction="row" gap={1} onSubmit={event=>{event.preventDefault();void searchMerge();}}><TextField fullWidth label={t('Find the surviving client')} value={mergeSearch} onChange={event=>setMergeSearch(event.target.value)} helperText={t('Enter at least 2 characters from the client’s name, email, or phone.')}/><Button type="submit" variant="outlined" disabled={mergeBusy||mergeSearch.trim().length<2}>{t('Search')}</Button></Stack>{mergeResults.map(client=><Paper variant="outlined" key={client.id} sx={{p:2}}><Stack direction="row" justifyContent="space-between" alignItems="center"><Box><Typography fontWeight={700}>{client.display_name}</Typography><Typography color="text.secondary">{client.email}{client.phone?` · ${client.phone}`:''}</Typography></Box><Button onClick={()=>void chooseSurvivor(client)}>{t('Keep this client')}</Button></Stack></Paper>)}</>}
        {mergePreview&&<><Paper variant="outlined" sx={{p:2}}><Typography variant="overline">{t('Surviving record')}</Typography><Typography fontWeight={700}>{mergePreview.survivor.display_name}</Typography><Typography>{mergePreview.survivor.email}</Typography></Paper>{mergePreview.blocked&&<Alert severity="error">{t(mergePreview.blocked_reason??'This merge is blocked.')}</Alert>}<Typography fontWeight={700}>{t('Records that will be reassigned')}</Typography><Stack direction="row" flexWrap="wrap" gap={1}>{Object.entries(mergePreview.relationship_counts).map(([key,value])=><Chip key={key} label={`${key.replaceAll('_',' ')}: ${value}`}/>)}</Stack><TextField select label={t('Primary email to keep')} value={primaryEmailSource} onChange={event=>setPrimaryEmailSource(event.target.value as 'survivor'|'duplicate')}><MenuItem value="survivor">{mergePreview.survivor.email}</MenuItem><MenuItem value="duplicate">{mergePreview.duplicate.email}</MenuItem></TextField><TextField select label={t('Profile details to keep')} value={profileSource} onChange={event=>setProfileSource(event.target.value as 'survivor'|'duplicate')}><MenuItem value="survivor">{t('Surviving record')}</MenuItem><MenuItem value="duplicate">{t('Duplicate record')}</MenuItem></TextField><TextField select label={t('Service address to keep')} value={addressSource} onChange={event=>setAddressSource(event.target.value as 'survivor'|'duplicate')}><MenuItem value="survivor">{t('Surviving record')}</MenuItem><MenuItem value="duplicate">{t('Duplicate record')}</MenuItem></TextField><TextField label={t('Reason for merge')} required value={mergeReason} inputProps={{maxLength:500}} onChange={event=>setMergeReason(event.target.value)}/><TextField label={t('Type {{confirmation}} to confirm',{confirmation:`MERGE ${mergeDuplicate?.id} INTO ${mergeSurvivor?.id}`})} required value={mergeConfirmation} onChange={event=>setMergeConfirmation(event.target.value)}/></>}
        {mergeError&&<Alert severity="error">{mergeError}</Alert>}
      </Stack></DialogContent><DialogActions><Button disabled={mergeBusy} onClick={()=>setMergeOpen(false)}>{t('Cancel')}</Button>{mergePreview&&<Button color="warning" variant="contained" disabled={mergeBusy||mergePreview.blocked||mergeReason.trim().length<5||mergeConfirmation!==`MERGE ${mergeDuplicate?.id} INTO ${mergeSurvivor?.id}`} onClick={()=>void completeMerge()}>{t(mergeBusy?'Merging…':'Merge client records')}</Button>}</DialogActions>
    </Dialog>
  </Stack>;
}

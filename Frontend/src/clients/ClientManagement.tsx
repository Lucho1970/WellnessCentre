import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Alert, Box, Button, Chip, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, Grid, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { ArrowLeft, ArrowRight, Plus, Search, Users } from 'lucide-react';
import { useStaffAuth } from '../auth/AuthProvider';
import { ClientInvitations } from './ClientInvitations';
import { useTranslation } from 'react-i18next';
import { apiErrorMessage } from '../shared/api';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type Summary = { id: number; display_name: string; email: string; phone: string | null; status: string };
const empty = { given_name: '', family_name: '', email: '', phone: '', preferred_contact: 'email', date_of_birth: '', emergency_contact_name: '', emergency_contact_phone: '', administrative_notes: '', status: 'active', revision: '' };
type Form = typeof empty;
type Detail = Summary & Form;

export function ClientManagement() {
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

  const request = useCallback(async (path: string, init: RequestInit = {}) => {
    const token = await getAccessToken();
    const response = await fetch(`${api}/clients${path}`, { ...init, headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', ...init.headers } });
    const body = await response.json();
    if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to complete the client request.')));
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

  const newClient = () => { setId(null); setForm({ ...empty }); setOriginal({ ...empty }); setFormError(''); setOpen(true); };
  const edit = async (clientId: number) => {
    setOpening(true); setError('');
    try {
      const client: Detail = await request(`/${clientId}`);
      const values = Object.fromEntries(Object.keys(empty).map(key => [key, client[key as keyof Form] ?? ''])) as Form;
      setId(clientId); setForm(values); setOriginal(values); setFormError(''); setOpen(true);
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to open client.')); }
    finally { setOpening(false); }
  };
  const close = () => {
    if (saving) return;
    if (JSON.stringify(form) !== JSON.stringify(original) && !window.confirm(t('Discard your unsaved client changes?'))) return;
    setOpen(false);
  };
  const save = async (event: FormEvent) => {
    event.preventDefault(); setSaving(true); setFormError('');
    try {
      await request(id === null ? '' : `/${id}`, { method: id === null ? 'POST' : 'PATCH', body: JSON.stringify(form) });
      setNotice(t(id === null ? 'Client created. The record is ready for booking.' : 'Client details saved.'));
      setOpen(false); setRefresh(value => value + 1);
    } catch (cause) { setFormError(cause instanceof Error ? cause.message : t('Unable to save client.')); }
    finally { setSaving(false); }
  };
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
          <Button variant="outlined" disabled={opening} aria-label={t('Edit {{name}}',{name:client.display_name})} onClick={() => void edit(client.id)}>{t('View / edit')}</Button>
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
            <Grid size={12}><Typography variant="subtitle1" fontWeight={700}>{t('Emergency contact')}</Typography></Grid>
            <Grid size={{ xs: 12, sm: 6 }}>{field('emergency_contact_name', t('Contact name (optional)'), { maxLength: 150 })}</Grid>
            <Grid size={{ xs: 12, sm: 6 }}>{field('emergency_contact_phone', t('Contact phone (optional)'), { type: 'tel', maxLength: 40 })}</Grid>
            <Grid size={12}><TextField fullWidth multiline minRows={3} disabled={saving} label={t('Administrative notes (optional)')} helperText={t('Booking and contact notes only. Do not enter treatment or clinical notes here.')} value={form.administrative_notes} inputProps={{ maxLength: 4000 }} onChange={event => setForm(current => ({ ...current, administrative_notes: event.target.value }))} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField fullWidth select disabled={saving} label={t('Status')} helperText={t('Inactive clients cannot receive new bookings.')} value={form.status} onChange={event => setForm(current => ({ ...current, status: event.target.value }))}><MenuItem value="active">{t('Active')}</MenuItem><MenuItem value="inactive">{t('Inactive')}</MenuItem>{!['active', 'inactive'].includes(form.status) && <MenuItem value={form.status}>{t('{{status}} — choose a new status',{status:form.status})}</MenuItem>}</TextField></Grid>
          </Grid>
          {formError && <Alert severity="error" sx={{ mt: 2 }}>{formError}</Alert>}
          {id !== null && <ClientInvitations key={id} clientId={id} request={request} />}
        </DialogContent>
        <DialogActions sx={{ p: 2 }}><Button onClick={close} disabled={saving}>{t('Cancel')}</Button><Button type="submit" variant="contained" disabled={saving}>{t(saving ? 'Saving…' : 'Save client')}</Button></DialogActions>
      </Box>
    </Dialog>
  </Stack>;
}

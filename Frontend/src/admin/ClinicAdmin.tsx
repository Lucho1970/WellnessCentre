import { useEffect, useState, type FormEvent } from 'react';
import { Button, MenuItem, Paper, Stack, Typography } from '@mui/material';
import { Alert, TextField, useFormValidation, withFormValidation } from '../shared/FormValidation';
import { apiRequest } from '../shared/api';
import { useStaffAuth } from '../auth/AuthProvider';
import { useTranslation } from 'react-i18next';
import { useUnsavedForm } from '../shared/UnsavedChanges';

type Clinic = { id: number; name: string; website_url?: string | null; portal_host: string | null; current: boolean; location_count: number };
type Draft = { name: string; website_url: string; portal_host: string; initial_location_name: string; timezone: string };
const blank = (): Draft => ({ name: '', website_url: '', portal_host: '', initial_location_name: '', timezone: 'America/Toronto' });
function ClinicAdminForm() {
  const { t } = useTranslation(); const { getAccessToken } = useStaffAuth(); const validation = useFormValidation(); const guard = useUnsavedForm();
  const [clinics, setClinics] = useState<Clinic[]>([]); const [draft, setDraft] = useState<Draft | null>(null);
  const [editing, setEditing] = useState<number | null>(null); const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const [saved, setSaved] = useState('');
  const headers = async () => ({ Authorization: `Bearer ${await getAccessToken()}`, 'Content-Type': 'application/json' });
  const load = async () => { const data = await apiRequest<{items: Clinic[]}>('/admin/clinics', { headers: await headers() }); setClinics(data.items); };
  useEffect(() => { void load().catch(cause => { validation.capture(cause); setError(cause instanceof Error ? cause.message : t('Unable to load clinics.')); }); }, [getAccessToken]);
  const open = (clinic?: Clinic) => { guard.markClean(); validation.clear(); setError(''); setSaved(''); setEditing(clinic?.id ?? null); setDraft(clinic ? { ...blank(), name: clinic.name, website_url: clinic.website_url ?? '', portal_host: clinic.portal_host ?? window.location.hostname } : blank()); };
  const close = () => { if (guard.dirty && !window.confirm(t('Discard your unsaved changes?'))) return; guard.markClean(); setDraft(null); validation.clear(); setError(''); };
  const submit = async (event: FormEvent) => {
    event.preventDefault(); if (!draft) return; setBusy(true); setError(''); setSaved(''); validation.clear();
    try {
      await apiRequest(`/admin/clinics${editing ? `/${editing}` : ''}`, { method: editing ? 'PATCH' : 'POST', headers: await headers(), body: JSON.stringify(draft) });
      guard.markClean(); setDraft(null); setSaved(t(editing ? 'Clinic settings saved.' : 'Clinic created. Configure its portal host before opening it.')); await load();
    } catch (cause) { validation.capture(cause); setError(cause instanceof Error ? cause.message : t('Unable to save clinic.')); }
    finally { setBusy(false); }
  };
  return <Stack spacing={2}>
    <Alert severity="info">{t('Clinics have separate records and portal hosts. Locations belong to one clinic; manage them from that clinic portal.')}</Alert>
    {saved && <Alert severity="success">{saved}</Alert>}{error && <Alert severity="error">{error}</Alert>}
    {!draft && <Button variant="contained" onClick={() => open()}>{t('Create clinic')}</Button>}
    {draft && <Paper component="form" onSubmit={submit} onChange={guard.markDirty} variant="outlined" sx={{ p: 3 }}><Stack spacing={2}>
      <Typography variant="h6">{t(editing ? 'Edit clinic' : 'Create clinic')}</Typography>
      <TextField name="name" label={t('Clinic name')} required value={draft.name} onChange={e => setDraft({ ...draft, name: e.target.value })}/>
      <TextField name="website_url" label={t('Public website URL (optional)')} value={draft.website_url} onChange={e => setDraft({ ...draft, website_url: e.target.value })}/>
      <TextField name="portal_host" label={t('Portal hostname')} required value={draft.portal_host} helperText={t('Hostname only, for example livinlively.copihue.ca. Configure DNS, HTTPS and identity-provider redirect URLs separately.')} onChange={e => setDraft({ ...draft, portal_host: e.target.value })}/>
      {!editing && <><TextField name="initial_location_name" label={t('First location name')} required value={draft.initial_location_name} onChange={e => setDraft({ ...draft, initial_location_name: e.target.value })}/><TextField name="timezone" label={t('Timezone')} select value={draft.timezone} onChange={e => setDraft({ ...draft, timezone: e.target.value })}>{['America/Toronto','America/Vancouver','America/Edmonton','America/Winnipeg','America/Halifax','America/St_Johns'].map(zone => <MenuItem value={zone} key={zone}>{zone}</MenuItem>)}</TextField><Alert severity="info">{t('A new clinic starts empty with one location. Your administrator identity receives separate access; existing clients, practitioners and services are not copied.')}</Alert></>}
      <Stack direction="row" spacing={1}><Button disabled={busy} onClick={close}>{t('Close')}</Button><Button disabled={busy} type="submit" variant="contained">{t(busy ? 'Saving…' : editing ? 'Save changes' : 'Create clinic')}</Button></Stack>
    </Stack></Paper>}
    {clinics.map(clinic => <Paper key={clinic.id} variant="outlined" sx={{ p: 2 }}><Stack spacing={1}>
      <Typography variant="h6">{clinic.name}</Typography><Typography>{clinic.portal_host || t('Portal host not configured')}</Typography>
      <Typography>{t('{{count}} locations in this clinic', { count: clinic.location_count })}</Typography>
      {clinic.current ? <Stack direction="row" spacing={1}><Button onClick={() => open(clinic)} disabled={!!draft}>{t('Edit clinic')}</Button><Button href="/admin/locations">{t('Manage locations')}</Button></Stack> : clinic.portal_host && <Button component="a" href={`https://${clinic.portal_host}/staff/login`}>{t('Open clinic portal')}</Button>}
    </Stack></Paper>)}
  </Stack>;
}
export const ClinicAdmin = withFormValidation(ClinicAdminForm);

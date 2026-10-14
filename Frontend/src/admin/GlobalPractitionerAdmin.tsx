import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Button, MenuItem, Paper, Stack, Typography } from '@mui/material';
import { Alert, TextField, useFormValidation, withFormValidation } from '../shared/FormValidation';
import { apiRequest } from '../shared/api';
import { useStaffAuth } from '../auth/AuthProvider';
import { useTranslation } from 'react-i18next';

type Assignment = { clinic_id: number; clinic_name: string; status: string; version: number };
type Person = { id: number; display_name: string; email: string; clinics: Assignment[] };
type Candidate = { practitioner_id: number; display_name: string; email: string; clinic_name: string };
type Clinic = { id: number; name: string; status: string };
type Location = { id: number; name: string };
const blank = { display_name: '', email: '', tenant_id: '', object_id: '' };
function GlobalPractitionerAdminForm() {
  const { t } = useTranslation(); const { getAccessToken } = useStaffAuth(); const validation = useFormValidation();
  const [people, setPeople] = useState<Person[]>([]); const [candidates, setCandidates] = useState<Candidate[]>([]); const [clinics, setClinics] = useState<Clinic[]>([]);
  const [draft, setDraft] = useState<typeof blank | null>(null); const [candidate, setCandidate] = useState(''); const [selected, setSelected] = useState<Person | null>(null);
  const [clinic, setClinic] = useState(''); const [location, setLocation] = useState(''); const [locations, setLocations] = useState<Location[]>([]);
  const [query, setQuery] = useState(''); const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const [message, setMessage] = useState('');
  const headers = useCallback(async () => ({ Authorization: `Bearer ${await getAccessToken()}`, 'Content-Type': 'application/json' }), [getAccessToken]);
  const load = useCallback(async () => {
    const auth = await headers();
    const [directory, existing, clinicData] = await Promise.all([
      apiRequest<{ items: Person[] }>('/application/practitioners', { headers: auth }),
      apiRequest<{ items: Candidate[] }>('/application/practitioners/import-candidates', { headers: auth }),
      apiRequest<{ items: Clinic[] }>('/application/clinics', { headers: auth }),
    ]);
    setPeople(directory.items); setCandidates(existing.items); setClinics(clinicData.items);
  }, [headers]);
  useEffect(() => { void load().catch(cause => setError(String(cause.message))); }, [load]);
  useEffect(() => {
    let cancelled = false; setLocations([]); setLocation('');
    if (clinic) void headers().then(auth => apiRequest<{ items: Location[] }>(`/application/clinics/${clinic}/locations`, { headers: auth })).then(data => { if (!cancelled) setLocations(data.items); }).catch(cause => { if (!cancelled) setError(String(cause.message)); });
    return () => { cancelled = true; };
  }, [clinic, headers]);
  const act = async (path: string, method: string, body: object, success: string) => {
    setBusy(true); setError(''); setMessage(''); validation.clear();
    try { await apiRequest(path, { method, headers: await headers(), body: JSON.stringify(body) }); setDraft(null); setSelected(null); setCandidate(''); setMessage(t(success)); await load(); }
    catch (cause) { validation.capture(cause); setError(cause instanceof Error ? cause.message : t('Unable to save practitioner.')); }
    finally { setBusy(false); }
  };
  const create = (event: FormEvent) => { event.preventDefault(); if (draft) void act('/application/practitioners', 'POST', draft, 'Global practitioner registered. Assign a clinic next.'); };
  const assign = (event: FormEvent) => { event.preventDefault(); if (selected) void act(`/application/practitioners/${selected.id}/clinics`, 'POST', { clinic_id: Number(clinic), location_id: Number(location), ...(selected.clinics.find(a => a.clinic_id === Number(clinic)) ? { version: Number(selected.clinics.find(a => a.clinic_id === Number(clinic))!.version) } : {}) }, 'Clinic assigned. Credentials remain private until the practitioner shares them.'); };
  return <Stack spacing={2}>
    <Alert severity="info">{t('Register each practitioner once, then assign clinics. Credentials belong to the practitioner and are shared by their choice.')}</Alert>
    {error && <Alert severity="error" action={<Button onClick={() => void load().catch(cause => setError(cause.message))}>{t('Retry')}</Button>}>{error}</Alert>}{message && <Alert severity="success">{message}</Alert>}
    <Paper variant="outlined" sx={{ p: 2 }}><Stack spacing={2}>
      <Typography variant="h6">{t('Import existing practitioner')}</Typography>
      <TextField name="practitioner_id" select label={t('Existing clinic practitioner')} value={candidate} onChange={e => setCandidate(e.target.value)}>{candidates.map(person => <MenuItem key={person.practitioner_id} value={person.practitioner_id}>{person.display_name} · {person.clinic_name} · {person.email}</MenuItem>)}</TextField>
      <Button disabled={busy || !candidate} onClick={() => void act('/application/practitioners/import', 'POST', { practitioner_id: Number(candidate) }, 'Practitioner imported. Existing clinic records are preserved.')}>{t('Import practitioner')}</Button>
    </Stack></Paper>
    {!draft && <Button disabled={busy} variant="contained" onClick={() => { validation.clear(); setDraft({ ...blank }); }}>{t('Register new practitioner')}</Button>}
    {draft && <Paper component="form" onSubmit={create} variant="outlined" sx={{ p: 2 }}><Stack spacing={2}>
      <Typography variant="h6">{t('Register new practitioner')}</Typography>
      {(['display_name', 'email', 'tenant_id', 'object_id'] as const).map(field => <TextField key={field} name={field} required label={t({ display_name: 'Display name', email: 'Email', tenant_id: 'Microsoft Entra tenant ID', object_id: 'Entra Object ID' }[field])} value={draft[field]} onChange={e => setDraft({ ...draft, [field]: e.target.value })}/>)}
      <Typography color="text.secondary">{t('Use the existing Microsoft account Object ID. Email alone never links an identity. Practitioner sign-in also requires the provider role or accepted external staff identity.')}</Typography>
      <Stack direction="row" spacing={1}><Button disabled={busy} onClick={() => setDraft(null)}>{t('Close')}</Button><Button type="submit" disabled={busy} variant="contained">{t('Register practitioner')}</Button></Stack>
    </Stack></Paper>}
    <TextField label={t('Find a global practitioner')} value={query} onChange={e => setQuery(e.target.value)}/>
    {people.filter(person => `${person.display_name} ${person.email}`.toLowerCase().includes(query.toLowerCase())).map(person => <Paper key={person.id} variant="outlined" sx={{ p: 2 }}><Stack spacing={1}>
      <Typography variant="h6">{person.display_name}</Typography><Typography>{person.email}</Typography>
      {person.clinics.map(assignment => <Stack key={assignment.clinic_id} direction={{ xs: 'column', sm: 'row' }} spacing={1} alignItems={{ sm: 'center' }}>
        <Typography>{assignment.clinic_name} · {t(assignment.status === 'active' ? 'Assigned' : 'Removed')}</Typography>
        {assignment.status === 'active' && <Button disabled={busy} color="error" onClick={() => { if (window.confirm(t('Remove this practitioner from this clinic? Future appointments and active recurring series must be handled first.'))) void act(`/application/practitioners/${person.id}/clinics/${assignment.clinic_id}`, 'DELETE', { version: Number(assignment.version) }, 'Clinic assignment removed. Historical records are preserved.'); }}>{t('Remove clinic assignment')}</Button>}
      </Stack>)}
      <Button disabled={busy} onClick={() => { setSelected(person); setClinic(''); setLocation(''); validation.clear(); }}>{t('Assign a clinic')}</Button>
    </Stack></Paper>)}
    {selected && <Paper component="form" onSubmit={assign} variant="outlined" sx={{ p: 2 }}><Stack spacing={2}>
      <Typography variant="h6">{t('Assign clinic for {{name}}', { name: selected.display_name })}</Typography>
      <TextField name="clinic_id" select required label={t('Clinic')} value={clinic} onChange={e => setClinic(e.target.value)}>{clinics.filter(item => item.status === 'active' && !selected.clinics.some(a => a.clinic_id === item.id && a.status === 'active')).map(item => <MenuItem key={item.id} value={item.id}>{item.name}</MenuItem>)}</TextField>
      <TextField name="location_id" select required label={t('Location')} value={location} onChange={e => setLocation(e.target.value)}>{locations.map(item => <MenuItem key={item.id} value={item.id}>{item.name}</MenuItem>)}</TextField>
      <Stack direction="row" spacing={1}><Button disabled={busy} onClick={() => setSelected(null)}>{t('Close')}</Button><Button type="submit" disabled={busy || !location} variant="contained">{t('Assign practitioner')}</Button></Stack>
    </Stack></Paper>}
  </Stack>;
}
export const GlobalPractitionerAdmin = withFormValidation(GlobalPractitionerAdminForm);

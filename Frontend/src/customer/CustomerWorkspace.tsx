import { useEffect, useState, type FormEvent } from 'react';
import { Alert, Button, Divider, Grid, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { customerFetch } from './session';

export type CustomerStatus = { onboarding_status: 'not_linked' | 'pending_review' | 'linked'; review_code?: string };
const emptyAddress = { address_line1: '', address_line2: '', city: '', province: '', postal_code: '', country: 'Canada', instructions: '' };
const emptyProfile = { given_name: '', family_name: '', email: '', phone: '', preferred_contact: 'email', address: emptyAddress, revision: '' };
type Profile = typeof emptyProfile;
type Appointment = { id: number; starts_at: string; ends_at: string; status: string; service: string; practitioner: string; location: string; timezone: string; delivery_mode: string };
export function CustomerWorkspace({ status, onRefresh }: { status: CustomerStatus; onRefresh: () => void }) {
  const [mode, setMode] = useState<'choose' | 'profile' | 'invite' | 'appointments'>(status.onboarding_status === 'linked' ? 'profile' : 'choose');
  const [profile, setProfile] = useState<Profile>(emptyProfile);
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [token, setToken] = useState(() => sessionStorage.getItem('wellness.customer.invitation') ?? '');
  const [claimantName, setClaimantName] = useState('');
  const [loading, setLoading] = useState(false), [saving, setSaving] = useState(false), [error, setError] = useState(''), [notice, setNotice] = useState('');
  const [reload, setReload] = useState(0);
  useEffect(() => {
    if (status.onboarding_status !== 'linked' || !['profile','appointments'].includes(mode)) return;
    const controller = new AbortController(); setLoading(true); setError('');
    void customerFetch(mode === 'profile' ? '/profile' : '/appointments', { signal: controller.signal }).then(data => {
      if (controller.signal.aborted) return;
      if (mode === 'profile') setProfile({ ...emptyProfile, ...data, address: data.address ?? { ...emptyAddress } });
      else setAppointments(data.items);
    }).catch(cause => { if (!controller.signal.aborted) setError(cause.message); }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [mode, status.onboarding_status, reload]);
  const save = async (event: FormEvent) => {
    event.preventDefault(); setSaving(true); setError('');
    try {
      if (mode === 'invite') {
        await customerFetch('/invitations/accept', { method: 'POST', body: JSON.stringify({ token, claimant_name: claimantName }) });
        sessionStorage.removeItem('wellness.customer.invitation'); setToken(''); onRefresh();
      } else if (status.onboarding_status === 'linked') {
        const data = await customerFetch('/profile', { method: 'PATCH', body: JSON.stringify(profile) });
        setProfile(data); setNotice('Profile saved. Existing appointment destinations have not changed.');
      } else {
        await customerFetch('/register', { method: 'POST', body: JSON.stringify(profile) }); onRefresh();
      }
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Unable to save.'); }
    finally { setSaving(false); }
  };
  const field = (key: 'given_name' | 'family_name' | 'email' | 'phone', label: string, maxLength: number) => <TextField fullWidth required label={label} value={profile[key] ?? ''} disabled={saving} type={key === 'email' ? 'email' : 'text'} inputProps={{ maxLength }} onChange={e => setProfile(p => ({ ...p, [key]: e.target.value }))} />;
  if (status.onboarding_status === 'pending_review') return <Stack spacing={2}>
    <Alert severity="info">Your invitation was accepted. Staff must verify your identity before you can view the client record.</Alert>
    <Typography>Give this review code to the clinic through your established contact channel: <strong>{status.review_code}</strong></Typography>
    <Button onClick={onRefresh}>Check approval status</Button>
  </Stack>;
  return <Stack spacing={2} mt={3}>
    <Divider />
    <Typography variant="h6">{status.onboarding_status === 'linked' ? 'My client account' : 'Set up your client account'}</Typography>
    {status.onboarding_status === 'linked' ? <Stack direction="row" spacing={1}><Button disabled={saving} onClick={() => setMode('profile')}>My profile</Button><Button disabled={saving} onClick={() => setMode('appointments')}>My appointments</Button></Stack>
      : <><Typography>If the clinic has booked you before, request an invitation instead of creating another record. Email matching does not link accounts.</Typography><Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}><Button onClick={() => setMode('profile')}>I am a new client</Button><Button onClick={() => setMode('invite')}>I have an invitation</Button></Stack></>}
    {error && <Alert severity="error">{error}{status.onboarding_status === 'linked' && <Button disabled={saving || loading} onClick={() => setReload(v => v + 1)}>Reload saved information</Button>}</Alert>}{notice && <Alert severity="success">{notice}</Alert>}
    {loading ? <Typography role="status">Loading your information…</Typography> : mode === 'appointments' ? <>
      <Typography>Most recent 100 appointments. Contact the clinic to book or make changes.</Typography>
      {appointments.length === 0 ? <Typography>No appointments yet.</Typography> : appointments.map(a => <Stack key={a.id} spacing={0.5} sx={{ borderBottom: '1px solid', borderColor: 'divider', py: 2 }}>
        <Typography fontWeight={700}>{a.service}</Typography><Typography>{new Date(a.starts_at.replace(' ', 'T') + 'Z').toLocaleString(undefined, { timeZone: a.timezone })} ({a.timezone})</Typography>
        <Typography>{a.practitioner} · {a.delivery_mode === 'mobile' ? 'At client location' : a.location} · {a.status.replaceAll('_', ' ')}</Typography>
      </Stack>)}
    </> : mode !== 'choose' && <Stack component="form" spacing={2} onSubmit={save}>
      {mode === 'invite' ? <><TextField required label="Your full name" inputProps={{ maxLength: 150 }} value={claimantName} onChange={e => setClaimantName(e.target.value)} /><TextField required label="Invitation code" value={token} inputProps={{ maxLength: 64 }} onChange={e => setToken(e.target.value.trim())} /><Typography>Accepting submits a claim for staff review; it does not reveal or change an existing client record.</Typography></> : <>
        <Grid container spacing={2}><Grid size={{ xs: 12, sm: 6 }}>{field('given_name','First name',100)}</Grid><Grid size={{ xs: 12, sm: 6 }}>{field('family_name','Last name',100)}</Grid></Grid>
        {field('email','Contact email',190)}{field('phone','Phone',40)}
        <TextField select label="Preferred contact" value={profile.preferred_contact} disabled={saving} onChange={e => setProfile(p => ({ ...p, preferred_contact: e.target.value }))}><MenuItem value="email">Email</MenuItem><MenuItem value="phone">Phone</MenuItem></TextField>
        <Typography variant="subtitle1">Mobile visit address</Typography>
        {(Object.keys(emptyAddress) as (keyof typeof emptyAddress)[]).map(key => <TextField key={key} fullWidth label={({ address_line1: 'Street address', address_line2: 'Unit (optional)', city: 'City', province: 'Province / region', postal_code: 'Postal code', country: 'Country', instructions: 'Access instructions (optional)' })[key]} required={!['address_line2','instructions'].includes(key)} disabled={saving} value={profile.address[key]} inputProps={{ maxLength: key === 'instructions' ? 500 : key === 'postal_code' ? 20 : key === 'city' ? 100 : ['province','country'].includes(key) ? 80 : 190 }} onChange={e => setProfile(p => ({ ...p, address: { ...p.address, [key]: e.target.value } }))} />)}
        <Typography variant="body2">Contact details do not change your sign-in identity. Do not enter clinical notes here.</Typography>
      </>}
      <Button type="submit" variant="contained" disabled={saving}>{saving ? 'Saving…' : mode === 'invite' ? 'Accept invitation' : status.onboarding_status === 'linked' ? 'Save profile' : 'Create my client record'}</Button>
    </Stack>}
  </Stack>;
}

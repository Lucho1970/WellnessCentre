import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { Button, Checkbox, FormControlLabel, Paper, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from './AuthProvider';
import { ApiError, apiRequest } from '../shared/api';
import { Alert, TextField, useFormValidation, withFormValidation } from '../shared/FormValidation';
import { useUnsavedForm } from '../shared/UnsavedChanges';

type Offer = { available: boolean; enabled: boolean; activation_available: boolean; terms: { id: number; version: number; content: string } | null; plan: { id: number; name: string; base_monthly_cents: number | string | null } | null };
type Profile = { given_name: string; family_name: string; email: string; phone: string; discipline: string; credentials: string; service_area: string; services: string; enquiry_reference: string };
type Application = Profile & { id: number; status: string; version: number; verification_code: string; review_message: string; account_available: boolean; subscription: { status: string; trial_ends_at: string | null } | null; checklist: Record<string, boolean> };
type State = { offer: Offer; application: Application | null };
type Pending = { path: string; body: Record<string, unknown> };
const blank = (): Profile => ({ given_name: '', family_name: '', email: '', phone: '', discipline: '', credentials: '', service_area: '', services: '', enquiry_reference: '' });
const labels: Record<string, string> = { submitted: 'Application submitted', changes_requested: 'Changes requested', approved: 'Application approved', rejected: 'Application not approved', withdrawn: 'Application withdrawn', activated: 'Account activated' };
function ApplicationForm() {
  const { t, i18n } = useTranslation(); const auth = useStaffAuth(); const validation = useFormValidation(); const guard = useUnsavedForm();
  const [state, setState] = useState<State | null>(null); const [profile, setProfile] = useState<Profile>(blank);
  const [accepted, setAccepted] = useState(false); const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false); const [blocked, setBlocked] = useState(false); const [error, setError] = useState(''); const [pending, setPending] = useState<Pending | null>(null);
  const fail = useCallback((cause: unknown) => {
    validation.capture(cause); setError(cause instanceof Error ? cause.message : t('Unable to load your application.'));
    if (cause instanceof ApiError && [401, 403, 404, 409].includes(cause.status)) { setState(null); setProfile(blank()); setPending(null); setBlocked(true); guard.markClean(); }
  }, [validation.capture, guard.markClean, t]);
  const load = useCallback(async (signal?: AbortSignal) => {
    setBusy(true); setError('');
    try {
      const data: State = auth.isAuthenticated
        ? await apiRequest<State>('/practitioner-applications/me', { headers: { Authorization: `Bearer ${await auth.getAccessToken()}` }, signal })
        : { offer: await apiRequest<Offer>('/public/practitioner-applications', { signal }), application: null };
      if (signal?.aborted) return;
      setState(data); setBlocked(false); setAccepted(false); setConfirmed(false); setPending(null); guard.markClean();
      setProfile(data.application ? Object.fromEntries(Object.keys(blank()).map(key => [key, data.application![key as keyof Profile]])) as Profile : blank());
    } catch (cause) { if (!signal?.aborted) fail(cause); }
    finally { if (!signal?.aborted) setBusy(false); }
  }, [auth.isAuthenticated, auth.getAccessToken, fail, guard.markClean]);
  const loadRef=useRef(load);loadRef.current=load;
  const identityKey=auth.isAuthenticated?(auth.account?.homeAccountId??'signed-in'):'anonymous';
  useEffect(() => { const controller = new AbortController(); setState(null);setProfile(blank()); void loadRef.current(controller.signal); return () => controller.abort(); }, [identityKey]);
  const perform = async (operation: Pending) => {
    setBusy(true); setError(''); validation.clear(); setPending(operation);
    try { await apiRequest(operation.path, { method: 'POST', headers: { Authorization: `Bearer ${await auth.getAccessToken()}`, 'Content-Type': 'application/json' }, body: JSON.stringify(operation.body) }); }
    catch (cause) { if (cause instanceof ApiError && cause.status < 500) setPending(null); fail(cause); setBusy(false); return; }
    guard.markClean(); setPending(null); await load();
  };
  const send = (event: FormEvent) => {
    event.preventDefault(); if (!state?.offer.terms) return;
    void perform({ path: '/practitioner-applications/me', body: { ...profile, version: Number(state.application?.version ?? 0), terms_id: state.offer.terms.id, accept_terms: accepted, information_confirmed: confirmed, request_key: crypto.randomUUID() } });
  };
  const app = state?.application; const editable = auth.isAuthenticated && state?.offer.enabled && (!app || app.status === 'changes_requested');
  const money = (cents: number | string) => new Intl.NumberFormat(i18n.language.startsWith('fr') ? 'fr-CA' : 'en-CA', { style: 'currency', currency: 'CAD' }).format(Number(cents) / 100);
  const field = (key: keyof Profile, label: string, required = true, multiline = false) => <TextField name={key} label={t(label)} value={profile[key]} required={required} multiline={multiline} minRows={multiline ? 3 : undefined} type={key === 'email' ? 'email' : 'text'} disabled={busy || !!pending || blocked} onChange={event => { setProfile({ ...profile, [key]: event.target.value }); guard.markDirty(); }} />;
  return <Stack spacing={2} sx={{ maxWidth: 780, mx: 'auto' }}>
    <Typography variant="h4" component="h1">{t('Apply to join the clinic')}</Typography>
    <Typography>{t('Use your dedicated practitioner account to submit an application and follow its review. Applying does not grant clinic access or publish your profile.')}</Typography>
    {error && <Alert severity="error">{error}</Alert>}
    <Stack direction="row" spacing={1}><Button disabled={busy} onClick={() => { if (!guard.dirty || window.confirm(t('Discard your unsaved changes?'))) void load(); }}>{t('Refresh application')}</Button>{auth.isAuthenticated && <Button disabled={busy} onClick={() => { if (!guard.dirty || window.confirm(t('Discard your unsaved changes?'))) void auth.signOut().catch(fail); }}>{t('SignOut')}</Button>}</Stack>
    {busy && <Typography role="status">{t('Loading…')}</Typography>}
    {!auth.configured && state?.offer.available && <Alert severity="info">{t('Practitioner sign-in is not configured. Contact the clinic.')}</Alert>}
    {pending && !busy && <><Alert severity="warning">{t('The save outcome is uncertain. Retry the same application change before editing.')}</Alert><Button onClick={() => void perform(pending)}>{t('Retry application change')}</Button></>}
    {state && !app && !state.offer.enabled && <Alert severity="info">{t('Practitioner applications are currently closed. You can still contact the clinic about joining.')}</Alert>}
    {!auth.isAuthenticated && state?.offer.available && <><Button variant="contained" disabled={!auth.configured || busy} onClick={() => void auth.signIn().catch(fail)}>{t(state.offer.enabled?'Sign in or register to apply':'Sign in to check your application')}</Button><Button href={`${import.meta.env.BASE_URL}staff/login`}>{t('Existing workforce staff sign-in')}</Button></>}
    {state && (editable || app?.status === 'approved') && <>
      <Alert severity="info">{t('Eligible new memberships receive six calendar months free after approval and activation. There is no automatic paid conversion or retroactive trial charge. SMS funding is assigned separately.')}</Alert>
      {state.offer.plan && <Typography>{state.offer.plan.name} · {state.offer.plan.base_monthly_cents === null ? t('Regular pricing has not been set. No dollar estimate is available yet.') : t('Published monthly base rate: {{amount}}. Other agreed charges are described in the joining terms.', { amount: money(state.offer.plan.base_monthly_cents) })}</Typography>}
    </>}
    {app && <Paper variant="outlined" sx={{ p: 2 }}><Stack spacing={1}>
      <Typography variant="h6">{t(labels[app.status] ?? app.status)}</Typography>
      <Typography>{t('Application reference: {{id}}', { id: app.id })}</Typography>
      {app.review_message && <Typography sx={{ whiteSpace: 'pre-wrap' }}>{app.review_message}</Typography>}
      {app.status !== 'activated' && <><TextField label={t('Verification code')} value={app.verification_code} slotProps={{ input: { readOnly: true } }} /><Typography>{t('Confirm this code with the clinic through an independently trusted contact method. Your contact email alone does not identify your sign-in account.')}</Typography></>}
      {app.status === 'approved' && !state?.offer.activation_available && <Alert severity="info">{t('Your application is approved. The clinic will tell you when account activation is ready. Your trial has not started.')}</Alert>}
      {app.status === 'activated' && !app.account_available && <Alert severity="warning">{t('Contact the clinic about your account access. Refreshing this application cannot restore revoked access.')}</Alert>}
      {app.subscription?.trial_ends_at && <Typography>{t('Your recorded trial ends: {{date}}', { date: new Date(app.subscription.trial_ends_at.replace(' ', 'T') + 'Z').toLocaleString(i18n.language) })}</Typography>}
      {app.account_available && <><Typography>{t('Complete your services, availability and credential review in the practitioner portal. Publication is a separate clinic decision.')}</Typography>{Object.entries(app.checklist).map(([key, done]) => <Typography key={key}>{t(({ services: 'Services configured', availability: 'Availability configured', credentials: 'Current credentials verified', published: 'Public profile published' } as Record<string, string>)[key] ?? key)}: {t(done ? 'Complete' : 'Pending')}</Typography>)}<Button variant="contained" href={`${import.meta.env.BASE_URL}staff/external`} onClick={() => sessionStorage.removeItem('wellness.staff.application-return')}>{t('Open practitioner portal')}</Button></>}
      {['submitted', 'changes_requested', 'approved'].includes(app.status) && <Button disabled={busy || !!pending || blocked} onClick={() => { if (window.confirm(t('Withdraw this application? Your submitted history will be retained.'))) void perform({ path: '/practitioner-applications/withdraw', body: { version: Number(app.version), request_key: crypto.randomUUID() } }); }}>{t('Withdraw application')}</Button>}
    </Stack></Paper>}
    {editable && <Paper component="form" onSubmit={send} variant="outlined" sx={{ p: 2 }}><Stack spacing={2}>
      <Typography>{t('Do not include patient information, payment details or identity documents in this application.')}</Typography>
      {field('given_name', 'First name')}{field('family_name', 'Last name')}{field('email', 'Contact email')}{field('phone', 'Contact phone (optional)', false)}{field('discipline', 'Discipline / type of practice')}{field('credentials', 'Credentials and professional registration', true, true)}{field('service_area', 'City, province and service area')}{field('services', 'Services you would like to offer', true, true)}{field('enquiry_reference', 'Prior enquiry reference (optional)', false)}
      {state?.offer.terms && <><Typography variant="h6">{t('Joining terms — version {{version}}', { version: state.offer.terms.version })}</Typography><Typography sx={{ whiteSpace: 'pre-wrap' }}>{state.offer.terms.content}</Typography></>}
      <FormControlLabel control={<Checkbox checked={confirmed} disabled={busy || !!pending} onChange={e => { setConfirmed(e.target.checked); guard.markDirty(); }} />} label={t('I confirm that these details are accurate and may be used by the clinic and platform administrators to review my application.')} />
      <FormControlLabel control={<Checkbox checked={accepted} disabled={busy || !!pending} onChange={e => { setAccepted(e.target.checked); guard.markDirty(); }} />} label={t('I accept the joining terms displayed above.')} />
      <Button type="submit" variant="contained" disabled={busy || !!pending || blocked || !accepted || !confirmed || !state?.offer.terms}>{t(app ? 'Submit revised application' : 'Submit practitioner application')}</Button>
    </Stack></Paper>}
    {app?.status === 'approved' && state?.offer.activation_available && state.offer.terms && <Paper variant="outlined" sx={{ p: 2 }}><Stack spacing={2}>
      <Typography variant="h6">{t('Joining terms — version {{version}}', { version: state.offer.terms.version })}</Typography><Typography sx={{ whiteSpace: 'pre-wrap' }}>{state.offer.terms.content}</Typography>
      <FormControlLabel control={<Checkbox checked={accepted} disabled={busy || !!pending} onChange={e => setAccepted(e.target.checked)} />} label={t('I accept the joining terms displayed above.')} />
      <Button variant="contained" disabled={!accepted || busy || !!pending || blocked} onClick={() => void perform({ path: '/practitioner-applications/activate', body: { version: Number(app.version), terms_id: state.offer.terms!.id, accept_terms: accepted, request_key: crypto.randomUUID() } })}>{t('Activate my approved account')}</Button>
    </Stack></Paper>}
  </Stack>;
}
export const PractitionerApplicationPage = withFormValidation(ApplicationForm);

import { Alert, FormControlLabel, TextField, withFormValidation, useFormValidation } from '../shared/FormValidation';
import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { Box, Button, Checkbox, Chip, CircularProgress, Divider, Grid, MenuItem, Paper, Stack, Typography } from '@mui/material';
import { CalendarPlus, RefreshCw } from 'lucide-react';
import { customerFetch } from './session';
import { useTranslation } from 'react-i18next';
import { formatDateTime } from '../i18n/format';
import { AddressEntry } from '../shared/AddressEntry';
import { useUnsavedForm } from '../shared/UnsavedChanges';
import { CustomerBooking } from './CustomerBooking';
import { clearCustomerBookingIntent, customerBookingIntent } from './bookingIntent';
import { CustomerAppointmentManager, type CustomerAppointment } from './CustomerAppointmentManager';
import { pendingAppointmentLink, clearAppointmentLink } from './appointmentLink';
import { CustomerAppointmentLink } from './CustomerAppointmentLink';
import { CustomerForms } from '../forms/FormTasks';

export type CustomerStatus = { onboarding_status: 'not_linked' | 'pending_review' | 'linked'; review_code?: string; capabilities?: string[] };
const emptyAddress = { address_line1: '', address_line2: '', city: '', province: '', postal_code: '', country: 'Canada', instructions: '' };
const emptyProfile = { given_name: '', family_name: '', email: '', phone: '', preferred_contact: 'email', address: emptyAddress, revision: '' };
type Profile = typeof emptyProfile;
type Appointment = CustomerAppointment;
type AppointmentView = 'upcoming' | 'past' | 'all';
const appointmentInstant = (value: string) => new Date(`${value.replace(' ', 'T')}Z`).getTime();
function CustomerWorkspaceForm({ status, onRefresh }: { status: CustomerStatus; onRefresh: () => void }) {
  const formValidation = useFormValidation();
  const { t, i18n } = useTranslation();
  const canBook=status.capabilities?.includes('book_own_appointments')??false;
  const previousOnboarding = useRef(status.onboarding_status);
  const [workflowLocked, setWorkflowLocked] = useState(false);
  const [mode, setMode] = useState<'choose' | 'profile' | 'invite' | 'appointments' | 'booking' | 'manage' | 'link' | 'forms'>(status.onboarding_status === 'linked' ? (pendingAppointmentLink() ? 'link' : canBook&&customerBookingIntent() ? 'booking' : 'appointments') : 'choose');
  const [appointmentView, setAppointmentView] = useState<AppointmentView>('upcoming');
  const [showCanceled, setShowCanceled] = useState(() => { try { return localStorage.getItem('wellness.client.showCanceledAppointments') === 'true'; } catch { return false; } });
  const [profile, setProfile] = useState<Profile>(emptyProfile);
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [managing, setManaging] = useState<Appointment | null>(null);
  const [token, setToken] = useState(() => sessionStorage.getItem('wellness.customer.invitation') ?? '');
  const [claimantName, setClaimantName] = useState('');
  const [loading, setLoading] = useState(false), [saving, setSaving] = useState(false), [error, setError] = useState(''), [notice, setNotice] = useState('');
  const [reload, setReload] = useState(0);
  const [calendarDownloading, setCalendarDownloading] = useState<number | null>(null);
  const { markDirty, markClean } = useUnsavedForm();
  useEffect(() => {
    if (status.onboarding_status === 'linked' && (mode === 'choose' || mode === 'invite')) setMode(pendingAppointmentLink() ? 'link' : canBook&&customerBookingIntent() ? 'booking' : 'appointments');
  }, [canBook, mode, status.onboarding_status]);
  useEffect(() => {
    const wasLinked = previousOnboarding.current === 'linked';
    previousOnboarding.current = status.onboarding_status;
    if (!wasLinked && status.onboarding_status === 'linked') {
      if (pendingAppointmentLink()) setMode('link'); else if (canBook && customerBookingIntent()) setMode('booking');
    }
  }, [canBook, status.onboarding_status]);
  useEffect(() => {
    if (status.onboarding_status !== 'linked' || !['profile','appointments'].includes(mode)) return;
    const controller = new AbortController(); setLoading(true); (formValidation.clear(), setError(''));
    void customerFetch(mode === 'profile' ? '/profile' : `/appointments?show_canceled=${showCanceled ? '1' : '0'}`, { signal: controller.signal }).then(data => {
      if (controller.signal.aborted) return;
      if (mode === 'profile') setProfile({ ...emptyProfile, ...data, address: data.address ?? { ...emptyAddress } });
      else setAppointments(data.items);
    }).catch(cause => { formValidation.capture(cause); if (!controller.signal.aborted) setError(cause.message); }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [mode, status.onboarding_status, reload, showCanceled]);
  const visibleAppointments = useMemo(() => {
    const now = Date.now();
    const rows = appointments.filter(appointment => (showCanceled || !appointment.status.startsWith('canceled')) && (appointmentView === 'all' || (appointmentView === 'upcoming'
      ? appointmentInstant(appointment.ends_at) >= now
      : appointmentInstant(appointment.ends_at) < now)));
    return [...rows].sort((left, right) => appointmentView === 'upcoming'
      ? appointmentInstant(left.starts_at) - appointmentInstant(right.starts_at)
      : appointmentInstant(right.starts_at) - appointmentInstant(left.starts_at));
  }, [appointmentView, appointments, showCanceled]);
  const save = async (event: FormEvent) => {
    event.preventDefault(); setSaving(true); (formValidation.clear(), setError(''));
    try {
      if (mode === 'invite') {
        await customerFetch('/invitations/accept', { method: 'POST', body: JSON.stringify({ token, claimant_name: claimantName }) });
        markClean(); sessionStorage.removeItem('wellness.customer.invitation'); setToken(''); onRefresh();
      } else if (status.onboarding_status === 'linked') {
        const data = await customerFetch('/profile', { method: 'PATCH', body: JSON.stringify(profile) });
        markClean(); setProfile(data); setNotice(t('Profile saved. Existing appointment destinations have not changed.'));
      } else {
        await customerFetch('/register', { method: 'POST', body: JSON.stringify(profile) }); markClean(); onRefresh();
      }
    } catch (cause) { formValidation.capture(cause); setError(cause instanceof Error ? cause.message : t('Unable to save.')); }
    finally { setSaving(false); }
  };
  const field = (key: 'given_name' | 'family_name' | 'email' | 'phone', label: string, maxLength: number) => <TextField fullWidth required label={label} value={profile[key] ?? ''} disabled={saving} type={key === 'email' ? 'email' : 'text'} inputProps={{ maxLength }} onChange={e => setProfile(p => ({ ...p, [key]: e.target.value }))} />;
  const downloadCalendar = async (appointmentId: number) => {
    setCalendarDownloading(appointmentId); (formValidation.clear(), setError(''));
    try {
      const data = await customerFetch(`/appointments/${appointmentId}/calendar`);
      if (typeof data.content !== 'string' || !/^appointment-\d+\.ics$/.test(data.filename)) throw new Error(t('The calendar file could not be prepared.'));
      const url = URL.createObjectURL(new Blob([data.content], { type: 'text/calendar;charset=utf-8' }));
      const link = document.createElement('a'); link.href = url; link.download = data.filename;
      document.body.appendChild(link); link.click(); link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (cause) { formValidation.capture(cause); setError(cause instanceof Error ? cause.message : t('Unable to download the calendar file.')); }
    finally { setCalendarDownloading(null); }
  };
  if (status.onboarding_status === 'pending_review') return <Stack spacing={2}>
    <Alert severity="info">{t('Your invitation was accepted. Staff must verify your identity before you can view the client record.')}</Alert>
    <Typography>{t('Give this review code to the clinic through your established contact channel:')} <strong>{status.review_code}</strong></Typography>
    <Button onClick={onRefresh}>{t('Check approval status')}</Button>
  </Stack>;
  return <Stack spacing={2} mt={3}>
    <Divider />
    <Typography variant="h6">{t(status.onboarding_status === 'linked' ? 'My client account' : 'Set up your client account')}</Typography>
    {status.onboarding_status === 'linked' ? <Stack direction="row" spacing={1} flexWrap="wrap"><Button variant={mode === 'appointments' ? 'contained' : 'text'} disabled={saving || workflowLocked} onClick={() => setMode('appointments')}>{t('My appointments')}</Button>{canBook&&<Button variant={mode === 'booking' ? 'contained' : 'text'} disabled={saving || workflowLocked} onClick={() => { setNotice(''); setMode('booking'); }}>{t('Book appointment')}</Button>}<Button variant={mode === 'profile' ? 'contained' : 'text'} disabled={saving || workflowLocked} onClick={() => setMode('profile')}>{t('My profile')}</Button><Button disabled={saving || workflowLocked} variant={mode === 'forms' ? 'contained' : 'text'} onClick={() => setMode('forms')}>{t('My forms')}</Button></Stack>
      : <><Typography>{t('If the clinic has booked you before, request an invitation instead of creating another record. Email matching does not link accounts.')}</Typography><Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}><Button onClick={() => setMode('profile')}>{t('I am a new client')}</Button><Button onClick={() => setMode('invite')}>{t('I have an invitation')}</Button></Stack></>}
    {error && <Alert severity="error">{error}{status.onboarding_status === 'linked' && <Button disabled={saving || loading} onClick={() => setReload(v => v + 1)}>{t('Reload saved information')}</Button>}</Alert>}{notice && <Alert severity="success">{notice}</Alert>}
    {mode === 'forms' ? <CustomerForms onLockedChange={setWorkflowLocked}/> : mode === 'link' ? <CustomerAppointmentLink onLockedChange={setWorkflowLocked} close={() => { clearAppointmentLink(); setMode('appointments'); }} complete={message => { setWorkflowLocked(false); setNotice(message); setAppointmentView('upcoming'); setReload(value => value + 1); setMode('appointments'); }}/> : mode === 'booking' ? <CustomerBooking onLockedChange={setWorkflowLocked} cancel={() => { clearCustomerBookingIntent(); setMode('appointments'); }} complete={(id, message) => { setWorkflowLocked(false); clearCustomerBookingIntent(); setNotice(message ?? t('Appointment #{{id}} confirmed. A confirmation email is being sent.', { id })); setAppointmentView('upcoming'); setReload(value => value + 1); setMode('appointments'); }} /> : mode === 'manage' && managing ? <CustomerAppointmentManager onLockedChange={setWorkflowLocked} appointment={managing} close={() => { setManaging(null); setMode('appointments'); }} complete={message => { setWorkflowLocked(false); setManaging(null); setNotice(message); setAppointmentView('upcoming'); setReload(value => value + 1); setMode('appointments'); }} /> : loading ? <CircularProgress aria-label={t(mode === 'appointments' ? 'Loading appointments' : 'Loading your information…')} /> : mode === 'appointments' ? <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 } }}>
      <Stack direction={{ xs: 'column', sm: 'row' }} gap={2} justifyContent="space-between" mb={2}>
        <Box><Typography variant="h6">{t('My appointments')}</Typography><Typography color="text.secondary">{t('Book a new appointment or manage an upcoming appointment here.')}</Typography></Box>
        <Stack direction="row" spacing={1} alignItems="center">
          <TextField name="appointmentView" select size="small" label={t('Show')} value={appointmentView} onChange={event => setAppointmentView(event.target.value as AppointmentView)} sx={{ minWidth: 170 }}>
            <MenuItem value="upcoming">{t('Upcoming')}</MenuItem><MenuItem value="past">{t('Past')}</MenuItem><MenuItem value="all">{t('All appointments')}</MenuItem>
          </TextField>
          <Button startIcon={<RefreshCw size={16} />} disabled={loading} onClick={() => setReload(value => value + 1)}>{t('Refresh')}</Button>
        </Stack>
      </Stack>
      <FormControlLabel name="showCanceled" control={<Checkbox checked={showCanceled} onChange={event => { const checked = event.target.checked; setShowCanceled(checked); try { localStorage.setItem('wellness.client.showCanceledAppointments', String(checked)); } catch { /* Browsers may disable storage. */ } }} />} label={t('Show canceled appointments')} />
      {visibleAppointments.length === 0 ? <Typography color="text.secondary">{t('No appointments in this view.')}</Typography> : <Stack spacing={2}>
        {visibleAppointments.map(appointment => <Box key={appointment.id} sx={{ p: 2, border: '1px solid', borderColor: 'divider', borderRadius: 2 }}>
          <Stack direction="row" flexWrap="wrap" gap={1} alignItems="center"><Typography fontWeight={700}>{appointment.service}</Typography>{appointment.recurring_series_id && <Chip size="small" label={t('Recurring series #{{id}}', { id: appointment.recurring_series_id })} />}<Chip size="small" variant="outlined" label={t(appointment.status.replaceAll('_', ' '))} /></Stack>
          <Typography>{formatDateTime(`${appointment.starts_at.replace(' ', 'T')}Z`, i18n.resolvedLanguage, { timeZone: appointment.timezone, dateStyle: 'medium', timeStyle: 'short' })} – {formatDateTime(`${appointment.ends_at.replace(' ', 'T')}Z`, i18n.resolvedLanguage, { timeZone: appointment.timezone, dateStyle: 'medium', timeStyle: 'short' })}</Typography>
          <Typography>{appointment.practitioner}</Typography>
          <Typography color="text.secondary">{appointment.delivery_mode === 'mobile' ? t('On-Site (client location)') : `${t('In clinic')} · ${appointment.location}`} · {t('Appointment #{{id}}', { id: appointment.id })}</Typography>
          {!appointment.status.startsWith('canceled') && <Button size="small" startIcon={<CalendarPlus size={16} />} disabled={calendarDownloading === appointment.id} onClick={() => void downloadCalendar(appointment.id)}>{t('Add to calendar')}</Button>}
          {['requested','confirmed','rescheduled'].includes(appointment.status) && appointmentInstant(appointment.ends_at) > Date.now() && <Button size="small" onClick={() => { setManaging(appointment); setNotice(''); setMode('manage'); }}>{t('View or change')}</Button>}
        </Box>)}
      </Stack>}
    </Paper> : mode !== 'choose' && <Stack component="form" spacing={2} onSubmit={save} onChange={markDirty}>
      {mode === 'invite' ? <><TextField name="claimantName" required label={t('Your full name')} inputProps={{ maxLength: 150 }} value={claimantName} onChange={e => setClaimantName(e.target.value)} /><TextField name="token" required label={t('Invitation code')} value={token} inputProps={{ maxLength: 64 }} onChange={e => setToken(e.target.value.trim())} /><Typography>{t('Accepting submits a claim for staff review; it does not reveal or change an existing client record.')}</Typography></> : <>
        <Grid container spacing={2}><Grid size={{ xs: 12, sm: 6 }}>{field('given_name',t('First name'),100)}</Grid><Grid size={{ xs: 12, sm: 6 }}>{field('family_name',t('Last name'),100)}</Grid></Grid>
        {field('email',t('Contact email'),190)}{field('phone',t('Phone'),40)}
        <TextField name="preferred_contact" select label={t('Preferred contact')} value={profile.preferred_contact} disabled={saving} onChange={e => setProfile(p => ({ ...p, preferred_contact: e.target.value }))}><MenuItem value="email">{t('Email')}</MenuItem><MenuItem value="phone">{t('Phone')}</MenuItem></TextField>
        <Typography variant="subtitle1">{t('On-Site visit address')}</Typography>
        <AddressEntry required showInstructions disabled={saving} value={profile.address} onChange={address => setProfile(p => ({ ...p, address: { ...emptyAddress, ...address, instructions: address.instructions ?? '' } }))} />
        <Typography variant="body2">{t('Contact details do not change your sign-in identity. Do not enter clinical notes here.')}</Typography>
      </>}
      <Button type="submit" variant="contained" disabled={saving}>{t(saving ? 'Saving…' : mode === 'invite' ? 'Accept invitation' : status.onboarding_status === 'linked' ? 'Save profile' : 'Create my client record')}</Button>
    </Stack>}
  </Stack>;
}
export const CustomerWorkspace = withFormValidation(CustomerWorkspaceForm);

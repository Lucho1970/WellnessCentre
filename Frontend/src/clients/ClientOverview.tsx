import { Alert, TextField, withFormValidation, useFormValidation } from '../shared/FormValidation';
import { useEffect, useState } from 'react';
import { Box, Button, Chip, CircularProgress, Divider, MenuItem, Paper, Stack, Tab, Tabs, Typography } from '@mui/material';
import { ArrowLeft, ArrowRight, CalendarDays, Eye } from 'lucide-react';
import { Link as RouterLink } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { formatDateTime, appLocale } from '../i18n/format';
import { pagePath } from '../portal/access';
import { ClientForms } from '../forms/ClientForms';

export type OverviewTab = 'appointments' | 'access' | 'forms';
type Client = { id: number; display_name: string; status: string };
type Appointment = { id: number; starts_at: string; ends_at: string; created_at: string; status: string; delivery_mode: string; service_name: string; service_name_fr: string | null; practitioner_name: string; location_name: string; timezone: string; room_name: string | null; base_price_cents: number | null; mobile_fee_cents: number; cancellation_fee_cents: number | null; currency: string; source: string };
type History = { client: Client; items: Appointment[]; page: number; has_more: boolean; counts: { total: number; upcoming: number; past: number; canceled: number } };
type Access = { practitioner_id: number; user_id: number; display_name: string; discipline: string; account_status: string; practitioner_active: boolean; membership_required: boolean; membership_status: string | null; identity_status: string | null; appointment_count: number; created_client: boolean; first_appointment_at: string | null; last_appointment_at: string | null; booking_mode: string; roles: string[]; permissions: string[]; configured_access: { staff_configuration_active: boolean; client_directory: boolean; booking_contact: boolean; client_administration: boolean; appointments: string; appointment_changes: string; logistics_notes: string } };
type AccessList = { client: Client; items: Access[]; page: number; has_more: boolean };
type Request = (path: string, init?: RequestInit) => Promise<unknown>;

function ClientOverviewForm({ clientId, initialTab, request, back, onLockedChange }: { clientId: number; initialTab: OverviewTab; request: Request; back: () => void; onLockedChange?: (locked: boolean) => void }) {
  const formValidation = useFormValidation();
  const { t, i18n } = useTranslation();
  const [tab, setTab] = useState<OverviewTab>(initialTab), [view, setView] = useState('all');
  const [formsLocked, setFormsLocked] = useState(false);
  useEffect(() => { onLockedChange?.(formsLocked); }, [formsLocked, onLockedChange]);
  useEffect(() => () => onLockedChange?.(false), [onLockedChange]);
  const [historyPage, setHistoryPage] = useState(1), [accessPage, setAccessPage] = useState(1);
  const [history, setHistory] = useState<History | null>(null), [access, setAccess] = useState<AccessList | null>(null);
  const [busy, setBusy] = useState(true), [error, setError] = useState(''), [retry, setRetry] = useState(0);
  useEffect(() => {
    if(tab==='forms'){setBusy(false);(formValidation.clear(), setError(''));return;}
    const controller = new AbortController(); setBusy(true); (formValidation.clear(), setError(''));
    const path = tab === 'appointments' ? `/${clientId}/appointments?view=${view}&page=${historyPage}` : `/${clientId}/practitioner-access?page=${accessPage}`;
    void request(path, { signal: controller.signal }).then(result => {
      if (controller.signal.aborted) return;
      const data = result as History | AccessList;
      if (Number(data.client?.id) !== clientId || !Array.isArray(data.items)) throw new Error(t('The client overview response is invalid.'));
      if (tab === 'appointments') setHistory(data as History); else setAccess(data as AccessList);
    }).catch(cause => { formValidation.capture(cause); if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : t('Unable to load client overview.')); })
      .finally(() => { if (!controller.signal.aborted) setBusy(false); });
    return () => controller.abort();
  }, [clientId, tab, view, historyPage, accessPage, request, retry, t]);
  const time = (value: string, zone: string) => formatDateTime(`${value.replace(' ', 'T')}Z`, i18n.language, { timeZone: zone, dateStyle: 'medium', timeStyle: 'short' });
  const money = (cents: number, currency: string) => new Intl.NumberFormat(appLocale(i18n.language), { style: 'currency', currency }).format(cents / 100);
  const scope = (value: string) => value === 'clinic' ? t('All clinic appointments') : value === 'own' ? t('Their own appointments only') : t('No configured access');
  const current = tab === 'appointments' ? history : access;
  const page = tab === 'appointments' ? historyPage : accessPage;
  const setPage = tab === 'appointments' ? setHistoryPage : setAccessPage;
  return <Stack spacing={2} sx={{ p: { xs: 2, sm: 3 }, overflowY: 'auto' }}>
    <Button disabled={formsLocked} onClick={back} startIcon={<ArrowLeft size={17}/>} sx={{ alignSelf: 'flex-start' }}>{t('Back to client details')}</Button>
    <Tabs value={tab} onChange={(_, value: OverviewTab) => setTab(value)} variant="scrollable" allowScrollButtonsMobile aria-label={t('Client overview sections')}>
      <Tab disabled={formsLocked} value="appointments" label={t('Appointment history')}/><Tab disabled={formsLocked} value="access" label={t('Practitioner access')}/><Tab disabled={formsLocked} value="forms" label={t('Client forms')}/>
    </Tabs>
    {tab === 'forms' && <ClientForms key={clientId} clientId={clientId} onLockedChange={setFormsLocked}/>}
    {tab === 'appointments' && <>
      <TextField name="view" select label={t('Appointment history filter')} value={view} onChange={event => { setView(event.target.value); setHistoryPage(1); }}>
        <MenuItem value="all">{t('All appointments')}</MenuItem><MenuItem value="upcoming">{t('Upcoming appointments')}</MenuItem><MenuItem value="past">{t('Past appointments')}</MenuItem><MenuItem value="canceled">{t('Cancelled appointments')}</MenuItem>
      </TextField>
      {!busy && !error && history && <Stack direction="row" gap={1} flexWrap="wrap" aria-label={t('Appointment totals')}>
        <Chip label={t('Total: {{count}}', { count: history.counts.total })}/><Chip label={t('Upcoming: {{count}}', { count: history.counts.upcoming })}/><Chip label={t('Past: {{count}}', { count: history.counts.past })}/><Chip label={t('Cancelled: {{count}}', { count: history.counts.canceled })}/>
      </Stack>}
    </>}
    {tab === 'access' && <Alert severity="info">{t('These are configured portal permissions. Actual access also requires authorized staff sign-in. Booking contact access does not grant access to another practitioner’s private notes.')}</Alert>}
    {busy && <Stack alignItems="center" p={3}><CircularProgress aria-label={t('Loading client overview')}/></Stack>}
    {error && <Alert severity="error" action={<Button color="inherit" onClick={() => setRetry(value => value + 1)}>{t('Retry')}</Button>}>{error}</Alert>}
    {!busy && !error && tab === 'appointments' && history && <>
      {!history.items.length && <Typography>{t('No appointments match this view.')}</Typography>}
      {history.items.map(appointment => <Paper key={appointment.id} variant="outlined" sx={{ p: 2 }}><Stack spacing={1}>
        <Stack direction="row" alignItems="center" flexWrap="wrap" gap={1}><CalendarDays size={18}/><Typography fontWeight={750}>{i18n.language.startsWith('fr') && appointment.service_name_fr ? appointment.service_name_fr : appointment.service_name}</Typography><Chip size="small" label={t(appointment.status.replaceAll('_', ' '))}/></Stack>
        <Typography>{time(appointment.starts_at, appointment.timezone)} – {time(appointment.ends_at, appointment.timezone)}</Typography>
        <Typography variant="body2" color="text.secondary">{appointment.timezone}</Typography>
        <Typography>{t('Practitioner')}: {appointment.practitioner_name}</Typography>
        <Typography>{t('Location')}: {appointment.location_name} · {t(appointment.delivery_mode === 'mobile' ? 'On-Site' : 'In clinic')}{appointment.room_name ? ` · ${appointment.room_name}` : ''}</Typography>
        <Typography>{t('Appointment price')}: {appointment.base_price_cents === null ? t('Not recorded') : money(Number(appointment.base_price_cents) + Number(appointment.mobile_fee_cents), appointment.currency)}</Typography>
        {Number(appointment.mobile_fee_cents) > 0 && <Typography variant="body2">{t('On-Site fee')}: {money(Number(appointment.mobile_fee_cents), appointment.currency)}</Typography>}
        {appointment.cancellation_fee_cents !== null && <Typography>{t('Cancellation fee')}: {money(Number(appointment.cancellation_fee_cents), appointment.currency)}</Typography>}
        <Typography variant="body2" color="text.secondary">{t('Appointment #{{id}}', { id: appointment.id })} · {t('Created')}: {time(appointment.created_at, appointment.timezone)}</Typography>
        <Button component={RouterLink} to={`${pagePath('admin', 'appointments')}?appointment_id=${appointment.id}&return_client_id=${clientId}`} startIcon={<Eye size={16}/>} sx={{ alignSelf: 'flex-start' }}>{t('Open appointment')}</Button>
        <AppointmentChanges clientId={clientId} appointmentId={appointment.id} timezone={appointment.timezone} currency={appointment.currency} request={request}/>
      </Stack></Paper>)}
    </>}
    {!busy && !error && tab === 'access' && access && <>
      <Typography variant="body2">{t('All clinic practitioner profiles are shown, including inactive accounts and profiles without a relationship. Appointment relationships include cancelled appointments.')}</Typography>
      {!access.items.length && <Typography>{t('No practitioner profiles in this clinic.')}</Typography>}
      {access.items.map(practitioner => <Paper key={practitioner.practitioner_id} variant="outlined" sx={{ p: 2 }}><Stack spacing={1}>
        <Stack direction="row" flexWrap="wrap" gap={1} alignItems="center"><Typography fontWeight={750}>{practitioner.display_name}</Typography><Chip size="small" label={t(practitioner.account_status === 'active' ? 'Active account' : 'Account access blocked')} color={practitioner.account_status === 'active' ? 'success' : 'default'}/></Stack>
        <Typography>{practitioner.discipline}</Typography>
        {practitioner.membership_required && !practitioner.configured_access.staff_configuration_active && practitioner.account_status === 'active' && <Alert severity="warning">{t('The required staff identity or membership is inactive or missing. Membership access is blocked.')}</Alert>}
        {!practitioner.practitioner_active && <Alert severity="warning">{t('The practitioner profile is inactive. Deactivate the staff account to block its configured staff access.')}</Alert>}
        <Typography>{t('Appointments with this client: {{count}}', { count: practitioner.appointment_count })}</Typography>
        {practitioner.created_client && <Typography>{t('This practitioner created the client record.')}</Typography>}
        {!practitioner.appointment_count && !practitioner.created_client && <Typography color="text.secondary">{t('No appointment or client-creation relationship.')}</Typography>}
        {practitioner.first_appointment_at && <Typography variant="body2">{t('First scheduled appointment (UTC)')}: {time(practitioner.first_appointment_at, 'UTC')}</Typography>}
        {practitioner.last_appointment_at && <Typography variant="body2">{t('Latest scheduled appointment (UTC)')}: {time(practitioner.last_appointment_at, 'UTC')}</Typography>}
        <Divider/>
        <Typography>{t('Client directory')}: {t(practitioner.configured_access.client_directory ? 'Configured access' : 'No configured access')}</Typography>
        <Typography>{t('Booking contact details')}: {t(practitioner.configured_access.booking_contact ? 'Configured access' : 'No configured access')}</Typography>
        <Typography>{t('Client administration')}: {t(practitioner.configured_access.client_administration ? 'Configured access' : 'No configured access')}</Typography>
        <Typography>{t('Appointment visibility')}: {scope(practitioner.configured_access.appointments)}</Typography>
        <Typography>{t('Appointment changes')}: {scope(practitioner.configured_access.appointment_changes)}</Typography>
        <Typography>{t('Appointment logistics notes')}: {scope(practitioner.configured_access.logistics_notes)}</Typography>
        <Typography variant="body2" color="text.secondary">{t('Local roles')}: {practitioner.roles.map(role => t(role)).join(', ') || t('None')}</Typography>
        {!!practitioner.permissions.length && <Typography variant="body2" color="text.secondary">{t('Additional permissions')}: {practitioner.permissions.map(permission => t(permission)).join(', ')}</Typography>}
      </Stack></Paper>)}
    </>}
    {tab !== 'forms' && !busy && !error && current && <Box><Stack direction="row" justifyContent="space-between" alignItems="center"><Button startIcon={<ArrowLeft size={16}/>} disabled={page === 1} onClick={() => setPage(value => value - 1)}>{t('Previous')}</Button><Typography>{t('Page {{page}}', { page })}</Typography><Button endIcon={<ArrowRight size={16}/>} disabled={!current.has_more} onClick={() => setPage(value => value + 1)}>{t('Next')}</Button></Stack></Box>}
  </Stack>;
}
export const ClientOverview = withFormValidation(ClientOverviewForm);


type Change = { id: number; kind: 'status' | 'reassignment' | 'fee_adjustment'; created_at: string; from_status: string | null; to_status: string | null; actor_name: string | null; reason: string | null; fee_triggered_cents: number | null; original_fee_cents: number | null; adjusted_fee_cents: number | null; previous_practitioner: string | null; next_practitioner: string | null };
function AppointmentChangesValidated({ clientId, appointmentId, timezone, currency, request }: { clientId: number; appointmentId: number; timezone: string; currency: string; request: Request }) {
  const formValidation = useFormValidation();
  const { t, i18n } = useTranslation();
  const [open, setOpen] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState(''), [page, setPage] = useState(1), [retry, setRetry] = useState(0);
  const [data, setData] = useState<{ items: Change[]; has_more: boolean } | null>(null);
  useEffect(() => {
    if (!open) return;
    const controller = new AbortController(); setBusy(true); (formValidation.clear(), setError(''));
    void request(`/${clientId}/appointments/${appointmentId}/history?page=${page}`, { signal: controller.signal }).then(result => {
      if (controller.signal.aborted) return;
      const response = result as { appointment: { id: number }; items: Change[]; has_more: boolean };
      if (Number(response.appointment?.id) !== appointmentId || !Array.isArray(response.items)) throw new Error(t('The client overview response is invalid.'));
      setData(response);
    }).catch(cause => { formValidation.capture(cause); if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : t('Unable to load recorded changes.')); })
      .finally(() => { if (!controller.signal.aborted) setBusy(false); });
    return () => controller.abort();
  }, [open, clientId, appointmentId, page, retry, request, t]);
  const money = (value: number | null) => value === null ? t('Not recorded') : new Intl.NumberFormat(appLocale(i18n.language), { style: 'currency', currency }).format(Number(value) / 100);
  return <Stack spacing={1}>
    <Button onClick={() => setOpen(value => !value)} aria-expanded={open} aria-controls={`appointment-changes-${appointmentId}`} sx={{ alignSelf: 'flex-start' }}>{t(open ? 'Hide recorded changes' : 'View recorded changes')}</Button>
    {open && <Stack id={`appointment-changes-${appointmentId}`} spacing={1}>
      <Typography variant="body2" color="text.secondary">{t('Only saved status changes, practitioner reassignments and cancellation fee adjustments are shown. Unrecorded previous appointment times cannot be reconstructed.')}</Typography>
      {busy && <CircularProgress size={22} aria-label={t('Loading recorded changes')}/>}
      {error && <Alert severity="error" action={<Button color="inherit" onClick={() => setRetry(value => value + 1)}>{t('Retry')}</Button>}>{error}</Alert>}
      {!busy && !error && data && <>
        {!data.items.length && <Typography>{t('No recorded changes for this appointment.')}</Typography>}
        {data.items.map(change => <Box key={`${change.kind}-${change.id}`} sx={{ p: 1.5, borderLeft: '3px solid', borderColor: 'divider' }}>
          <Typography fontWeight={700}>{t(change.kind === 'status' ? 'Status change' : change.kind === 'reassignment' ? 'Practitioner reassignment' : 'Cancellation fee adjustment')}</Typography>
          <Typography variant="body2">{formatDateTime(`${change.created_at.replace(' ', 'T')}Z`, i18n.language, { timeZone: timezone, dateStyle: 'medium', timeStyle: 'short' })} · {change.actor_name || t('System or unavailable staff')}</Typography>
          {change.kind === 'status' && <Typography>{change.from_status ? t(change.from_status.replaceAll('_', ' ')) : t('No previous status')} → {t((change.to_status ?? '').replaceAll('_', ' '))}</Typography>}
          {change.kind === 'reassignment' && <Typography>{change.previous_practitioner || t('Not recorded')} → {change.next_practitioner || t('Not recorded')}</Typography>}
          {change.kind === 'fee_adjustment' && <Typography>{money(change.original_fee_cents)} → {money(change.adjusted_fee_cents)}</Typography>}
          {Number(change.fee_triggered_cents) > 0 && <Typography>{t('Cancellation fee')}: {money(change.fee_triggered_cents)}</Typography>}
          {change.reason && <Typography sx={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{t('Reason')}: {change.reason}</Typography>}
        </Box>)}
        <Stack direction="row" justifyContent="space-between"><Button disabled={page === 1} onClick={() => setPage(value => value - 1)}>{t('Previous changes')}</Button><Typography>{t('Page {{page}}', { page })}</Typography><Button disabled={!data.has_more} onClick={() => setPage(value => value + 1)}>{t('Next changes')}</Button></Stack>
      </>}
    </Stack>}
  </Stack>;
}
const AppointmentChanges = withFormValidation(AppointmentChangesValidated);

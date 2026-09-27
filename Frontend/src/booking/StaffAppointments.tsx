import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { Alert, Box, Button, ButtonBase, Checkbox, Chip, CircularProgress, Divider, Drawer, FormControlLabel, Grid, IconButton, List, ListItemButton, ListItemText, MenuItem, Paper, Stack, Step, StepLabel, Stepper, TextField, Typography } from '@mui/material';
import { CalendarClock, CalendarPlus, CalendarX, Eye, RefreshCw, X } from 'lucide-react';
import { useStaffAuth } from '../auth/AuthProvider';
import { useTranslation } from 'react-i18next';
import { formatCad, formatDateTime } from '../i18n/format';
import { apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { AddressEntry, type AddressValue } from '../shared/AddressEntry';
import { useUnsavedChanges } from '../shared/UnsavedChanges';
import { portalLink } from '../shared/urls';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type Client = { id: number; display_name: string; email: string; phone: string | null };
type Combination = { location_id: number; location_name: string; timezone: string; service_id: number; service_name: string; requires_room: number; offers_mobile: number; offers_clinic: number; travel_buffer_minutes: number; mobile_fee_cents: number; mobile_radius_km: number | null; base_price_cents: number; practitioner_id: number; practitioner_name: string; duration_option_id: number; duration_minutes: number };
type Room = { id: number; name: string; location_id: number };
type Slot = { duration_option_id: number; starts_at: string; ends_at: string; available_room_ids: number[] };
type CancellationPreview = { window_minutes: number; deadline: string; inside_fee_window: boolean; fee_cents: number; appointment_total_cents: number; currency: string };
type Destination = AddressValue & { instructions: string };
type CoverageValidation = { destination: Destination; distance_km: number; radius_km: number; token: string; expires_at: string };
const emptyDestination = (): Destination => ({address_line1:'',address_line2:'',city:'',province:'Ontario',postal_code:'',country:'Canada',instructions:''});
function addressText(value: string | Destination | null, unavailable: string) { if(!value)return ''; try { const address=typeof value==='string'?JSON.parse(value):value; return [address.address_line1,address.address_line2,address.city,address.province,address.postal_code,address.country,address.instructions].filter(Boolean).join(', '); } catch { return unavailable; } }
type Appointment = { delivery_mode: 'clinic'|'mobile'; destination_snapshot: string | Destination | null; travel_buffer_minutes: number; base_price_cents: number | null; mobile_fee_cents: number; id: number; client_name: string; service_name: string; practitioner_name: string; location_name: string; timezone: string; room_id: number | null; room_name: string | null; duration_option_id: number; starts_at: string; ends_at: string; status: string; version: number };
type Payload = { delivery_mode: 'clinic'|'mobile'; destination?: Destination; address_validation_token?: string; quoted_base_price_cents: number; quoted_mobile_fee_cents: number; client_id: number; location_id: number; service_id: number; practitioner_id: number; duration_option_id: number; starts_at: string; room_id?: number; idempotency_key: string };
class RequestError extends Error { constructor(message: string, readonly status: number, readonly code: string, readonly fields: Record<string, unknown> = {}) { super(message); } }
function displayTime(value: string, zone: string, language?: string, database = false) {
  return formatDateTime(database ? `${value.replace(' ', 'T')}Z` : value, language, { timeZone: zone, dateStyle: 'medium', timeStyle: 'short' });
}
function today(zone: string) { return new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()); }
function unique(rows: Combination[], key: 'location_id' | 'service_id' | 'practitioner_id' | 'duration_option_id') {
  return [...new Map(rows.map(row => [String(row[key]), row])).values()];
}

export function StaffAppointments({ canBook, practitionerMode = false, canScheduleOthers = false, canManageFees = false, canAddClients = false, canApproveOnsiteArea = false }: { canBook: boolean; practitionerMode?: boolean; canScheduleOthers?: boolean; canManageFees?: boolean; canAddClients?: boolean; canApproveOnsiteArea?: boolean }) {
  const { t, i18n } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [view, setView] = useState('upcoming');
  const [page, setPage] = useState(1);
  const [refresh, setRefresh] = useState(0);
  const [listBusy, setListBusy] = useState(true);
  const [listError, setListError] = useState('');
  const [notice, setNotice] = useState('');
  const [creating, setCreating] = useState(false);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [detailsOpen, setDetailsOpen] = useState(false);
  const [managing, setManaging] = useState<{ appointment: Appointment; action: 'reschedule' | 'cancel' } | null>(null);
  const selected = appointments.find(item => item.id === selectedId) ?? null;
  const canChangeSelected = Boolean(!creating && canBook && selected && ['requested','confirmed','rescheduled'].includes(selected.status) && new Date(`${selected.ends_at.replace(' ', 'T')}Z`).getTime() > Date.now());
  useEffect(() => { if (detailsOpen && !selected) setDetailsOpen(false); }, [detailsOpen, selected]);
  const request = useCallback(async (path: string, init: RequestInit = {}) => {
    const token = await getAccessToken();
    const response = await fetch(`${api}${path}`, { ...init, headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', ...init.headers } });
    const text = await response.text();
    let body;
    try { body = JSON.parse(text); } catch { throw new RequestError(t('The server returned an unreadable response (HTTP {{status}}). Try again or contact the administrator.', { status: response.status }), response.status, 'invalid_response'); }
    if (!response.ok) throw new RequestError(apiErrorMessage(body, response.status, t('Request failed (HTTP {{status}}).', { status: response.status })), response.status, body?.error?.code ?? 'request_failed', body?.error?.fields ?? {});
    return normalizeNumericIds(body.data);
  }, [getAccessToken, t]);
  useEffect(() => {
    const controller = new AbortController(); setListBusy(true); setListError('');
    void request(`/appointments?view=${view}&page=${page}${practitionerMode ? '&scope=practitioner' : ''}`, { signal: controller.signal })
      .then(data => { if (!controller.signal.aborted) { setAppointments(data); setSelectedId(current => data.some((item: Appointment) => item.id === current) ? current : null); } })
      .catch(error => { if (!controller.signal.aborted) setListError(error.message); })
      .finally(() => { if (!controller.signal.aborted) setListBusy(false); });
    return () => controller.abort();
  }, [request, view, page, refresh, practitionerMode]);
  const startChange = (action: 'reschedule' | 'cancel') => { if (!selected || !canChangeSelected) return; setCreating(false); setDetailsOpen(false); setManaging({ appointment: selected, action }); };
  return <Stack spacing={2}>
    <Paper variant="outlined" sx={{ p: 1.5 }}><Stack component="nav" aria-label={t('Appointment actions')} direction={{ xs: 'column', md: 'row' }} gap={1} alignItems={{ md: 'center' }}>
      {canBook && <Button variant="contained" startIcon={<CalendarPlus size={17}/>} disabled={creating} onClick={() => { setCreating(true); setNotice(''); }}>{t('Book appointment')}</Button>}
      <Divider orientation="vertical" flexItem sx={{ display: { xs: 'none', md: 'block' }, mx: .5 }}/>
      <Button startIcon={<Eye size={17}/>} disabled={!selected} onClick={() => setDetailsOpen(true)}>{t('More details')}</Button>
      {canBook && <><Button startIcon={<CalendarClock size={17}/>} disabled={!canChangeSelected} onClick={() => startChange('reschedule')}>{t('Reschedule')}</Button><Button color="error" startIcon={<CalendarX size={17}/>} disabled={!canChangeSelected} onClick={() => startChange('cancel')}>{t('Cancel appointment')}</Button></>}
      <TextField select size="small" label={t('Show')} value={view} onChange={event => { setView(event.target.value); setPage(1); setSelectedId(null); setDetailsOpen(false); }} sx={{ ml: { md: 'auto' }, minWidth: { md: 170 } }}><MenuItem value="upcoming">{t('Upcoming')}</MenuItem><MenuItem value="past">{t('Past')}</MenuItem><MenuItem value="all">{t('All appointments')}</MenuItem></TextField>
      <Button startIcon={<RefreshCw size={16}/>} disabled={listBusy} onClick={() => setRefresh(value => value + 1)}>{t('Refresh')}</Button>
    </Stack></Paper>
    {notice && <Alert severity="success" onClose={() => setNotice('')}>{notice}</Alert>}
    {creating && <BookingForm request={request} practitionerMode={practitionerMode} canScheduleOthers={canScheduleOthers} canAddClients={canAddClients} canApproveOnsiteArea={canApproveOnsiteArea} cancel={() => setCreating(false)} complete={id => { setCreating(false); setNotice(t('Appointment #{{id}} confirmed. A confirmation email is being sent.', { id })); setView('upcoming'); setPage(1); setRefresh(value => value + 1); }} />}
    {managing && <ManageAppointment key={`${managing.appointment.id}-${managing.action}`} appointment={managing.appointment} initialAction={managing.action} request={request} canAssessFees={!practitionerMode || canManageFees} canManageFees={canManageFees} close={() => setManaging(null)} complete={message => { setManaging(null); setNotice(message); setRefresh(value => value + 1); }} />}
    <Paper variant="outlined" sx={{ overflow: 'hidden' }}>
      <Box sx={{ px: 2.5, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}><Typography variant="h5">{t('Appointments')}</Typography><Typography color="text.secondary">{t('Select an appointment to view details or enable actions. Times are shown in each clinic location’s timezone.')}</Typography></Box>
      {listError && <Alert severity="error">{listError}</Alert>}
      {listBusy ? <Box sx={{ p: 3 }}><CircularProgress aria-label={t('Loading appointments')} /></Box> : !listError && <Stack>
        <List disablePadding aria-label={t('Appointments')}>
          {appointments.map(item => <ListItemButton key={item.id} selected={selectedId === item.id} onClick={() => setSelectedId(item.id)} divider sx={{ py: 1.75, px: 2.5 }}>
            <ListItemText primary={<Stack direction="row" flexWrap="wrap" gap={1} alignItems="center"><Typography fontWeight={750}>{item.client_name} · {item.service_name}</Typography><Chip size="small" label={t(item.status.replaceAll('_', ' '))} variant="outlined" /></Stack>} secondary={<Stack><Typography variant="body2" color="text.secondary">{displayTime(item.starts_at, item.timezone, i18n.resolvedLanguage, true)} – {displayTime(item.ends_at, item.timezone, i18n.resolvedLanguage, true)}</Typography><Typography variant="body2" color="text.secondary">{item.practitioner_name} · {item.location_name} · #{item.id}</Typography></Stack>} />
          </ListItemButton>)}
          {appointments.length === 0 && <Box sx={{ p: 5, textAlign: 'center' }}><Typography color="text.secondary">{t('No appointments in this view.')}</Typography></Box>}
        </List>
        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ p: 2 }}><Button disabled={page === 1} onClick={() => { setPage(value => value - 1); setSelectedId(null); setDetailsOpen(false); }}>{t('Previous')}</Button><Typography>{t('Page {{page}}',{page})}</Typography><Button disabled={appointments.length < 50} onClick={() => { setPage(value => value + 1); setSelectedId(null); setDetailsOpen(false); }}>{t('Next')}</Button></Stack>
      </Stack>}
    </Paper>
    <Drawer anchor="right" open={detailsOpen && Boolean(selected)} onClose={() => setDetailsOpen(false)} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 620 }, maxWidth: '100%' } } }}>
      {selected && <><Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ px: 3, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}><Box><Typography variant="overline" color="primary">{t('Appointment details')}</Typography><Typography variant="h5">{selected.client_name} · {selected.service_name}</Typography></Box><IconButton aria-label={t('Close panel')} onClick={() => setDetailsOpen(false)}><X/></IconButton></Stack><Stack spacing={2} sx={{ p: 3, overflowY: 'auto' }}>
        {[[t('Status'), t(selected.status.replaceAll('_', ' '))], [t('Starts'), displayTime(selected.starts_at, selected.timezone, i18n.resolvedLanguage, true)], [t('Ends'), displayTime(selected.ends_at, selected.timezone, i18n.resolvedLanguage, true)], [t('Practitioner'), selected.practitioner_name], [t('Location'), selected.location_name], [t('Room'), selected.room_name || t('Not set')], [t('Visit type'), t(selected.delivery_mode === 'mobile' ? 'On-Site (client location)' : 'In clinic')], [t('Appointment ID'), String(selected.id)]].map(([label, value]) => <Box key={label}><Typography variant="caption" color="text.secondary">{label}</Typography><Typography fontWeight={600}>{value}</Typography></Box>)}
        {selected.delivery_mode === 'mobile' && <><Box><Typography variant="caption" color="text.secondary">{t('Visit address')}</Typography><Typography fontWeight={600}>{addressText(selected.destination_snapshot, t('Address unavailable')) || t('Address unavailable')}</Typography></Box><Typography variant="body2">{t('Travel reserved: {{minutes}} minutes before and after', { minutes: selected.travel_buffer_minutes })}</Typography></>}
        {selected.base_price_cents !== null && selected.base_price_cents !== undefined && <Typography variant="body2">{t('Treatment {{treatment}} + On-Site fee {{mobile}} (before applicable taxes)', { treatment: formatCad(Number(selected.base_price_cents), i18n.resolvedLanguage), mobile: formatCad(Number(selected.mobile_fee_cents), i18n.resolvedLanguage) })}</Typography>}
        {canBook && <Stack direction="row" gap={1}><Button disabled={!canChangeSelected} onClick={() => startChange('reschedule')}>{t('Reschedule')}</Button><Button color="error" disabled={!canChangeSelected} onClick={() => startChange('cancel')}>{t('Cancel appointment')}</Button></Stack>}
      </Stack></>}
    </Drawer>
  </Stack>;
}

type ManageProps = { appointment: Appointment; initialAction: 'reschedule' | 'cancel'; request: (path: string, init?: RequestInit) => Promise<any>; canAssessFees: boolean; canManageFees: boolean; close: () => void; complete: (message: string) => void };
function ManageAppointment({ appointment, initialAction, request, canAssessFees, canManageFees, close, complete }: ManageProps) {
  const { t, i18n } = useTranslation();
  const appointmentDate = new Intl.DateTimeFormat('en-CA', { timeZone: appointment.timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(`${appointment.starts_at.replace(' ', 'T')}Z`));
  const action = initialAction;
  const [date, setDate] = useState(appointmentDate);
  const [slots, setSlots] = useState<Slot[]>([]);
  const [slot, setSlot] = useState<Slot | null>(null);
  const [room, setRoom] = useState(appointment.room_id ? String(appointment.room_id) : '');
  const [reason, setReason] = useState('');
  const [searched, setSearched] = useState(false);
  const [busy, setBusy] = useState(initialAction === 'cancel');
  const [error, setError] = useState('');
  const [cancellation, setCancellation] = useState<CancellationPreview | null>(null);
  const [clientRequested, setClientRequested] = useState(false);
  const [adjustedFee, setAdjustedFee] = useState('');
  const dirty = date !== appointmentDate || slot !== null || room !== (appointment.room_id ? String(appointment.room_id) : '') || reason.trim() !== '' || clientRequested || (cancellation !== null && adjustedFee !== (Number(cancellation.fee_cents)/100).toFixed(2));
  useUnsavedChanges(dirty);
  const closeSafely = () => { if (busy || (dirty && !window.confirm(t('Discard your unsaved changes?')))) return; close(); };
  const needsRoom = appointment.room_id !== null;
  useEffect(() => {
    if (initialAction !== 'cancel') return;
    let active = true;
    void request(`/appointments/${appointment.id}/cancellation-preview`).then(preview => {
      if (active) { setCancellation(preview); setAdjustedFee((Number(preview.fee_cents)/100).toFixed(2)); }
    }).catch(cause => { if (active) setError(cause instanceof Error ? cause.message : t('Unable to load the cancellation policy.')); }).finally(() => { if (active) setBusy(false); });
    return () => { active = false; };
  }, [appointment.id, initialAction, request, t]);
  const loadSlots = async (event: FormEvent) => {
    event.preventDefault(); setBusy(true); setError(''); setSlots([]); setSlot(null); setSearched(false);
    try {
      const data = await request(`/appointments/${appointment.id}/availability?date_from=${date}&date_to=${date}`);
      const currentStart=new Date(`${appointment.starts_at.replace(' ', 'T')}Z`).getTime();
      setSlots(data.availability.filter((item: Slot) => Number(item.duration_option_id) === Number(appointment.duration_option_id) && new Date(item.starts_at).getTime() !== currentStart)); setSearched(true);
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to load times.')); }
    finally { setBusy(false); }
  };
  const submit = async () => {
    if (action === 'reschedule' && (!slot || (needsRoom && !room))) return;
    setBusy(true); setError('');
    try {
      const body = action === 'cancel'
        ? { action, version: Number(appointment.version), reason, apply_cancellation_fee: clientRequested, ...(clientRequested && canManageFees && cancellation && Math.round(Number(adjustedFee)*100) !== Number(cancellation.fee_cents) ? { adjusted_fee_cents: Math.round(Number(adjustedFee)*100) } : {}) }
        : { action, version: Number(appointment.version), starts_at: slot!.starts_at, ...(needsRoom ? { room_id: Number(room) } : {}), reason };
      await request(`/appointments/${appointment.id}`, { method: 'PATCH', body: JSON.stringify(body) });
      complete(t(action === 'cancel' ? 'Appointment #{{id}} was canceled.' : 'Appointment #{{id}} was rescheduled.', { id: appointment.id }));
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to change the appointment.')); }
    finally { setBusy(false); }
  };
  return <Drawer anchor="right" open onClose={closeSafely} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 620 }, maxWidth: '100%' } } }}>
    <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" gap={2} sx={{ p: 3, borderBottom: '1px solid', borderColor: 'divider' }}>
      <Box><Typography variant="overline" color="primary">{t(action === 'reschedule' ? 'Reschedule' : 'Cancel appointment')}</Typography><Typography variant="h5">{t('Appointment #{{id}}', { id: appointment.id })}</Typography><Typography color="text.secondary">{appointment.client_name} · {appointment.service_name}</Typography></Box>
      <IconButton aria-label={t('Close panel')} disabled={busy} onClick={closeSafely}><X/></IconButton>
    </Stack>
    <Stack spacing={2} sx={{ p: 3, overflowY: 'auto' }}>
    {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
    {action === 'reschedule' && <Stack spacing={2}>
      <Typography>{t('Choose a new available time. The client, service, location, delivery mode, duration, and price remain unchanged.')}</Typography>
      <Stack component="form" direction={{ xs: 'column', sm: 'row' }} gap={2} onSubmit={loadSlots}><TextField required type="date" label={t('Appointment date')} value={date} disabled={busy} InputLabelProps={{ shrink: true }} inputProps={{ min: today(appointment.timezone) }} onChange={event => { setDate(event.target.value); setSlots([]); setSlot(null); setSearched(false); }} /><Button type="submit" variant="outlined" disabled={busy || !date}>{t(busy ? 'Searching…' : 'Find times')}</Button></Stack>
      {searched && slots.length === 0 && <Alert severity="info">{t('No bookable times on this day. Try another day or check working hours and room assignments.')}</Alert>}
      <Stack direction="row" flexWrap="wrap" gap={1}>{slots.map(item => <Button key={item.starts_at} variant={slot?.starts_at === item.starts_at ? 'contained' : 'outlined'} onClick={() => { setSlot(item); setRoom(item.available_room_ids.length === 1 ? String(item.available_room_ids[0]) : ''); }}>{displayTime(item.starts_at, appointment.timezone, i18n.resolvedLanguage)}</Button>)}</Stack>
      {slot && needsRoom && <TextField select required label={t('Available room')} value={room} onChange={event => setRoom(event.target.value)}>{slot.available_room_ids.map(id => <MenuItem key={id} value={String(id)}>{id === Number(appointment.room_id) && appointment.room_name ? appointment.room_name : t('Room {{number}}', { number: id })}</MenuItem>)}</TextField>}
      <TextField label={t('Reason or note (optional)')} value={reason} multiline minRows={2} inputProps={{ maxLength: 1000 }} onChange={event => setReason(event.target.value)} />
      <Stack direction="row" gap={2}><Button disabled={busy} onClick={closeSafely}>{t('Cancel')}</Button><Button variant="contained" disabled={busy || !slot || (needsRoom && !room)} onClick={() => void submit()}>{t(busy ? 'Saving…' : 'Confirm reschedule')}</Button></Stack>
    </Stack>}
    {action === 'cancel' && <Stack spacing={2}>
      <Alert severity="warning">{t('Canceling releases the time and room. The appointment remains in history.')}</Alert>
      {canAssessFees && <FormControlLabel control={<Checkbox checked={clientRequested} onChange={event => setClientRequested(event.target.checked)}/>} label={t('This cancellation was requested by the client')}/>}
      {cancellation && <Alert severity={clientRequested && cancellation.fee_cents > 0 ? 'warning' : 'info'}>{clientRequested ? (cancellation.fee_cents > 0 ? t('The calculated policy fee is {{fee}}.', { fee: formatCad(cancellation.fee_cents, i18n.resolvedLanguage) }) : t('No cancellation fee applies.')) : t('Clinic-initiated cancellations do not assess a client fee.')}</Alert>}
      {clientRequested && canManageFees && cancellation && cancellation.fee_cents > 0 && <TextField required type="number" label={t('Assessed cancellation fee (CAD)')} value={adjustedFee} onChange={event => setAdjustedFee(event.target.value)} inputProps={{ min: 0, max: cancellation.fee_cents/100, step: .01 }} helperText={t('Reducing or waiving the calculated fee requires a reason and is recorded in the audit history.')}/>}
      <TextField label={t('Cancellation reason (optional)')} value={reason} multiline minRows={2} inputProps={{ maxLength: 1000 }} onChange={event => setReason(event.target.value)} />
      <Stack direction="row" gap={2}><Button disabled={busy} onClick={closeSafely}>{t('Back')}</Button><Button color="error" variant="contained" disabled={busy || !cancellation || Boolean(clientRequested && canManageFees && Math.round(Number(adjustedFee)*100) !== Number(cancellation.fee_cents) && !reason.trim())} onClick={() => void submit()}>{t(busy ? 'Saving…' : 'Confirm cancellation')}</Button></Stack>
    </Stack>}
    </Stack>
  </Drawer>;
}

type FormProps = { request: (path: string, init?: RequestInit) => Promise<any>; practitionerMode: boolean; canScheduleOthers: boolean; canAddClients: boolean; canApproveOnsiteArea: boolean; cancel: () => void; complete: (id: number) => void };
function BookingForm({ request, practitionerMode, canScheduleOthers, canAddClients, canApproveOnsiteArea, cancel, complete }: FormProps) {
  const { t, i18n } = useTranslation();
  const money = (cents: number) => formatCad(cents, i18n.resolvedLanguage);
  const [options, setOptions] = useState<Combination[]>([]);
  const [rooms, setRooms] = useState<Room[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [step, setStep] = useState(0);
  const [clientQuery, setClientQuery] = useState('');
  const [clientBirthdate, setClientBirthdate] = useState('');
  const [clients, setClients] = useState<Client[]>([]);
  const [client, setClient] = useState<Client | null>(null);
  const [clientBusy, setClientBusy] = useState(false);
  const [clientMore, setClientMore] = useState(false);
  const [clientError, setClientError] = useState('');
  const [clientSearched, setClientSearched] = useState(false);
  const [addingClient, setAddingClient] = useState(false);
  const [newClient, setNewClient] = useState({ given_name: '', family_name: '', email: '', phone: '', date_of_birth: '' });
  const [newClientBusy, setNewClientBusy] = useState(false);
  const [newClientError, setNewClientError] = useState('');
  const [duplicateClients, setDuplicateClients] = useState<Client[]>([]);
  const [createdClientId, setCreatedClientId] = useState<number | null>(null);
  const [invitationBusy, setInvitationBusy] = useState(false);
  const [invitationStatus, setInvitationStatus] = useState('');
  const [invitationLink, setInvitationLink] = useState('');
  const [mode,setMode]=useState<'clinic'|'mobile'>('mobile');
  const [destination,setDestination]=useState<Destination>(emptyDestination);
  const [clientAddressState,setClientAddressState]=useState<'idle'|'loading'|'saved'|'missing'|'custom'|'error'>('idle');
  const [coverage,setCoverage]=useState<CoverageValidation|null>(null);
  const [coverageBusy,setCoverageBusy]=useState(false);
  const [areaApproved,setAreaApproved]=useState(false);
  const [approvalBusy,setApprovalBusy]=useState(false);
  const approvalLookup=useRef<AbortController|null>(null);
  const addressComplete=(['address_line1','city','province','postal_code','country'] as const).every(key=>destination[key].trim());
  const addressReady=mode==='clinic'||(addressComplete&&(Boolean(coverage)||areaApproved));
  const [location, setLocation] = useState('');
  const [preferredLocation, setPreferredLocation] = useState('');
  const [service, setService] = useState('');
  const [practitioner, setPractitioner] = useState('');
  const [duration, setDuration] = useState('');
  const [date, setDate] = useState('');
  const [slots, setSlots] = useState<Slot[]>([]);
  const [slot, setSlot] = useState<Slot | null>(null);
  const [room, setRoom] = useState('');
  const [searched, setSearched] = useState(false);
  const [busy, setBusy] = useState(false);
  const [pending, setPending] = useState<Payload | null>(null);
  const pendingRef = useRef<Payload | null>(null);
  const sending = useRef(false);
  const eligibleOptions=options.filter(row=>Number(mode==='mobile'?row.offers_mobile:row.offers_clinic));
  const selected = eligibleOptions.find(row => String(row.location_id) === location && String(row.service_id) === service && String(row.practitioner_id) === practitioner && String(row.duration_option_id) === duration);
  const timezone = options.find(row => String(row.location_id) === location)?.timezone ?? 'America/Toronto';
  const needsRoom=mode==='clinic'&&Boolean(Number(selected?.requires_room));
  const mobileFee=mode==='mobile'?Number(selected?.mobile_fee_cents??0):0;
  const locationRows = eligibleOptions.filter(row => String(row.location_id) === location);
  const serviceRows = locationRows.filter(row => String(row.service_id) === service);
  const practitionerRows = serviceRows.filter(row => String(row.practitioner_id) === practitioner);
  const practitionerLocked=practitionerMode&&!canScheduleOthers;
  const assignedPractitioner=practitionerLocked?unique(options,'practitioner_id')[0]:undefined;
  useUnsavedChanges(Boolean(
    clientQuery.trim() || clientBirthdate || client || location || service || duration || date || slot || room || pending ||
    (addingClient && Object.values(newClient).some(value => value.trim())) ||
    destination.address_line1 || destination.address_line2 || destination.city || destination.postal_code || destination.instructions
  ));
  useEffect(() => {
    const controller = new AbortController();
    void request(`/booking-options${practitionerMode ? '?scope=practitioner' : ''}`, { signal: controller.signal }).then(data => { if (!controller.signal.aborted) { setOptions(data.combinations); setRooms(data.rooms); setPreferredLocation(data.default_location_id ? String(data.default_location_id) : ''); } })
      .catch(cause => { if (!controller.signal.aborted) setError(cause.message); }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [request]);
  useEffect(() => {
    if (!practitionerLocked || !assignedPractitioner) return;
    const assigned=String(assignedPractitioner.practitioner_id);
    if (practitioner!==assigned) setPractitioner(assigned);
  }, [assignedPractitioner, practitioner, practitionerLocked]);
  useEffect(() => {
    if(location)return;
    const availableLocations=unique(options.filter(row=>Number(mode==='mobile'?row.offers_mobile:row.offers_clinic)),'location_id');
    if(preferredLocation&&availableLocations.some(row=>String(row.location_id)===preferredLocation))setLocation(preferredLocation);
    else if(availableLocations.length===1)setLocation(String(availableLocations[0].location_id));
  },[location,mode,options,preferredLocation]);
  useEffect(() => {
    const term = clientQuery.trim();
    setClients([]); setClientMore(false); setClientError(''); setClientSearched(false);
    if (client || (term.length < 2 && !clientBirthdate)) { setClientBusy(false); return; }
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      setClientBusy(true);
      const params=new URLSearchParams();if(term.length>=2)params.set('q',term);if(clientBirthdate)params.set('date_of_birth',clientBirthdate);if(practitionerMode)params.set('scope','practitioner');
      void request(`/booking-clients?${params.toString()}`, { signal: controller.signal })
        .then(data => { if (!controller.signal.aborted) { setClients(data.items); setClientMore(data.has_more); setClientSearched(true); } })
        .catch(cause => { if (!controller.signal.aborted) setClientError(cause instanceof Error ? cause.message : t('Unable to search clients.')); })
        .finally(() => { if (!controller.signal.aborted) setClientBusy(false); });
    }, term.length >= 2 ? 300 : 0);
    return () => { window.clearTimeout(timer); controller.abort(); };
  }, [clientQuery, clientBirthdate, client, request, practitionerMode, t]);
  useEffect(() => {
    if(!client||mode!=='mobile'){setClientAddressState('idle');return;}
    const controller=new AbortController();setClientAddressState('loading');setCoverage(null);setDestination(emptyDestination());
    void request(`/booking-clients/${client.id}/address`,{signal:controller.signal})
      .then(data=>{if(controller.signal.aborted)return;if(data.address){setDestination({...data.address,instructions:data.address.instructions??''});setClientAddressState('saved');}else setClientAddressState('missing');})
      .catch(()=>{if(!controller.signal.aborted)setClientAddressState('error');});
    return()=>controller.abort();
  },[client?.id,mode,request]);
  useEffect(() => {
    setAreaApproved(false);
    if (!client || mode !== 'mobile' || !selected || !addressComplete) return;
    const controller = new AbortController();
    approvalLookup.current = controller;
    void request('/address-coverage/approval', { method:'POST', body:JSON.stringify({ client_id:Number(client.id), location_id:Number(location), service_id:Number(service), practitioner_id:Number(practitioner), destination }), signal:controller.signal })
      .then(result => { if (!controller.signal.aborted) setAreaApproved(Boolean(result.approved)); }).catch(() => {});
    return () => controller.abort();
  }, [request,client?.id,mode,location,service,practitioner,selected?.duration_option_id,destination.address_line1,destination.address_line2,destination.city,destination.province,destination.postal_code,destination.country,addressComplete]);
  const clearSlots = () => { setSlot(null); setRoom(''); setSlots([]); setSearched(false); setError(''); };
  const createClient = async (event: FormEvent) => {
    event.preventDefault(); setNewClientBusy(true); setNewClientError(''); setDuplicateClients([]);
    try {
      const created: Client = await request('/clients', { method: 'POST', body: JSON.stringify({ ...newClient, preferred_contact: 'email', status: 'active' }) });
      setClient(created); setCreatedClientId(Number(created.id)); setAddingClient(false); setClientQuery(''); setClientBirthdate(''); setClients([]);
      setInvitationStatus(''); setInvitationLink('');
    } catch (cause) {
      if (cause instanceof RequestError && cause.code === 'possible_duplicate' && Array.isArray(cause.fields.candidates)) setDuplicateClients(cause.fields.candidates as Client[]);
      setNewClientError(cause instanceof Error ? cause.message : t('Unable to create the client.'));
    } finally { setNewClientBusy(false); }
  };
  const sendInvitation = async () => {
    if (!client || createdClientId !== Number(client.id) || !window.confirm(t('Send a private portal invitation to {{email}}?', { email: client.email }))) return;
    setInvitationBusy(true); setInvitationStatus(''); setInvitationLink('');
    try {
      const result = await request(`/clients/${client.id}/invitations`, { method: 'POST', body: JSON.stringify({ delivery: 'email' }) });
      if (result.delivery === 'email_accepted') setInvitationStatus(t('The mail provider accepted the invitation. The client must still complete sign-in and identity review.'));
      else { setInvitationStatus(t('The invitation email was not confirmed. Copy and send this private link through a verified contact channel.')); setInvitationLink(`${portalLink('client/invite')}#token=${result.token}`); }
    } catch (cause) { setInvitationStatus(cause instanceof Error ? cause.message : t('Unable to send the invitation.')); }
    finally { setInvitationBusy(false); }
  };
  const findSlots = async (event: FormEvent) => {
    event.preventDefault(); if (!selected) return; setBusy(true); clearSlots();
    try {
      const data = await request(`/availability?location_id=${location}&service_id=${service}&practitioner_id=${practitioner}&delivery_mode=${mode}&date_from=${date}&date_to=${date}`);
      setSlots(data.availability.filter((item: Slot) => Number(item.duration_option_id) === Number(duration))); setSearched(true);
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to load times.')); }
    finally { setBusy(false); }
  };
  const validateCoverage = async () => {
    if (!selected || !addressComplete) return;
    setCoverageBusy(true); setCoverage(null); setError(''); clearSlots();
    try {
      const result = await request('/address-coverage/validate', { method: 'POST', body: JSON.stringify({ location_id:Number(location), service_id:Number(service), practitioner_id:Number(practitioner), destination }) });
      setDestination(result.destination); setCoverage(result);
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to validate this address.')); }
    finally { setCoverageBusy(false); }
  };
  const approveCoverage = async () => {
    if (!client || !coverage || !selected || !canApproveOnsiteArea || !window.confirm(t('I independently confirm this visit address fits clinic travel policy for this practitioner, service, and base location. Save this approval for future bookings?'))) return;
    setApprovalBusy(true); setError('');
    try {
      await request('/address-coverage/approve', { method:'POST', body:JSON.stringify({ client_id:Number(client.id), location_id:Number(location), service_id:Number(service), practitioner_id:Number(practitioner), destination, address_validation_token:coverage.token }) });
      approvalLookup.current?.abort();
      setAreaApproved(true);
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to save the On-Site approval.')); }
    finally { setApprovalBusy(false); }
  };
  const revokeCoverage = async () => {
    if (!client || !selected || !canApproveOnsiteArea || !window.confirm(t('Remove this On-Site approval? Future bookings will require a new distance check.'))) return;
    setApprovalBusy(true); setError('');
    try {
      await request('/address-coverage/revoke', { method:'POST', body:JSON.stringify({ client_id:Number(client.id), location_id:Number(location), service_id:Number(service), practitioner_id:Number(practitioner), destination }) });
      approvalLookup.current?.abort();
      setAreaApproved(false); setCoverage(null);
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to remove the On-Site approval.')); }
    finally { setApprovalBusy(false); }
  };
  const confirm = async () => {
    if (sending.current || !client || !selected || !slot) return;
    sending.current = true; setBusy(true); setError('');
    const payload = pendingRef.current ?? { delivery_mode:mode, ...(mode==='mobile'?{destination,...(!areaApproved&&coverage?{address_validation_token:coverage.token}:{})}:{}), quoted_base_price_cents:Number(selected.base_price_cents),quoted_mobile_fee_cents:mobileFee, client_id: Number(client.id), location_id: Number(location), service_id: Number(service), practitioner_id: Number(practitioner), duration_option_id: Number(duration), starts_at: slot.starts_at, ...(needsRoom ? { room_id: Number(room) } : {}), idempotency_key: crypto.randomUUID() };
    pendingRef.current = payload; setPending(payload);
    try { const result = await request('/appointments', { method: 'POST', body: JSON.stringify(payload) }); pendingRef.current = null; setPending(null); complete(result.id); }
    catch (cause) {
      const rejected = cause instanceof RequestError && cause.status >= 400 && cause.status < 500 && cause.code !== 'invalid_response';
      const coverageRejected = cause instanceof RequestError && ['coverage_validation_required','invalid_coverage_validation','coverage_validation_mismatch','coverage_validation_expired'].includes(cause.code);
      if (rejected) { pendingRef.current = null; setPending(null); setStep(coverageRejected ? 0 : 1); if (coverageRejected) { setCoverage(null); setAreaApproved(false); } clearSlots(); }
      setError(cause instanceof Error ? cause.message : t('Unable to confirm appointment.'));
    } finally { sending.current = false; setBusy(false); }
  };
  const comboSelect = (label: string, value: string, rows: Combination[], key: 'location_id' | 'service_id' | 'practitioner_id' | 'duration_option_id', name: (row: Combination) => string, change: (value: string) => void) => <TextField required fullWidth select label={label} value={value} onChange={event => change(event.target.value)}>{unique(rows, key).map(row => <MenuItem key={row[key]} value={String(row[key])}>{name(row)}</MenuItem>)}</TextField>;
  return <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 } }}>
    <Typography variant="h5" mb={2}>{t('New appointment')}</Typography>
    <Stepper activeStep={step} alternativeLabel sx={{ mb: 3 }}>{['Client and care', 'Available time', 'Review and confirm'].map(label => <Step key={label}><StepLabel>{t(label)}</StepLabel></Step>)}</Stepper>
    {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
    {loading ? <CircularProgress aria-label={t('Loading booking options')} /> : options.length === 0 ? <Alert severity="info">{t('No booking combinations are configured. Check active services, durations, practitioners, and location assignments in administration.')}</Alert> : <>
      {step === 0 && <Stack spacing={3}>
        {!client && <Grid container spacing={2}>
          <Grid size={{ xs: 12, sm: 4 }}><TextField fullWidth type="date" label={t('Birthdate (optional)')} value={clientBirthdate} InputLabelProps={{ shrink: true }} onChange={event=>{setClient(null);setClientBirthdate(event.target.value);}} helperText={t('Use an exact birthdate to narrow the search first.')} /></Grid>
          <Grid size={{ xs: 12, sm: 8 }}><TextField fullWidth label={t('Find an active client')} value={clientQuery} inputProps={{ maxLength: 190 }} onChange={event => { setClient(null); setClientQuery(event.target.value); }} helperText={t(clientBirthdate ? 'Optionally enter at least 2 characters to narrow the birthdate matches.' : clientQuery.trim().length < 2 ? 'Enter at least 2 characters from the client’s name, email, or phone.' : 'Matching active clients appear automatically.')} /></Grid>
        </Grid>}
        {clientBusy && <Stack direction="row" spacing={1} alignItems="center" role="status"><CircularProgress size={20} /><Typography>{t('Searching active clients…')}</Typography></Stack>}
        {clientError && <Alert severity="error">{clientError}</Alert>}
        {!client && clientSearched && clients.length === 0 && <Alert severity="info">{t(canAddClients ? 'No active clients matched. Search again before adding a new client to avoid duplicates.' : 'No active clients matched. Try a name, email, or phone number, or ask clinic staff to add the client.')}</Alert>}
        {!client && clients.length > 0 && <Stack spacing={1} role="list" aria-label={t('Matching active clients')}>
          {clients.map(item => <Box key={item.id} role="listitem"><ButtonBase aria-label={t('Select {{name}}', { name: item.display_name })} onClick={() => setClient(item)} sx={{ width: '100%', p: 2, border: '1px solid', borderColor: 'divider', borderRadius: 1, textAlign: 'left' }}><Stack width="100%" direction={{ xs: 'column', sm: 'row' }} spacing={2} justifyContent="space-between" alignItems={{ sm: 'center' }}>
            <Box><Typography fontWeight={700}>{item.display_name}</Typography><Typography variant="body2">{item.email}</Typography><Typography variant="body2" color="text.secondary">{item.phone || t('No phone number on file')}</Typography></Box>
            <Typography color="primary" fontWeight={700}>{t('Select')}</Typography>
          </Stack></ButtonBase></Box>)}
          {clientMore && <Alert severity="info">{t('Showing the first 25 matches. Continue typing to narrow the results.')}</Alert>}
        </Stack>}
        {!client && canAddClients && <Box>
          <Button variant="outlined" onClick={() => { setAddingClient(value => !value); setNewClientError(''); setDuplicateClients([]); }}>{t(addingClient ? 'Cancel new client' : 'Add new client')}</Button>
          {addingClient && <Stack component="form" onSubmit={event => void createClient(event)} spacing={2} sx={{ mt: 2, p: 2, border: '1px solid', borderColor: 'divider', borderRadius: 1 }}>
            <Alert severity="info">{t('Search for an existing client before creating a record. Saving a record does not link a sign-in account.')}</Alert>
            <Grid container spacing={2}>
              <Grid size={{ xs: 12, sm: 6 }}><TextField fullWidth required label={t('First name')} value={newClient.given_name} inputProps={{ maxLength: 100 }} disabled={newClientBusy} onChange={event => setNewClient(value => ({ ...value, given_name: event.target.value }))} /></Grid>
              <Grid size={{ xs: 12, sm: 6 }}><TextField fullWidth required label={t('Last name')} value={newClient.family_name} inputProps={{ maxLength: 100 }} disabled={newClientBusy} onChange={event => setNewClient(value => ({ ...value, family_name: event.target.value }))} /></Grid>
              <Grid size={{ xs: 12, sm: 6 }}><TextField fullWidth required type="email" label={t('Email')} value={newClient.email} inputProps={{ maxLength: 190 }} disabled={newClientBusy} onChange={event => setNewClient(value => ({ ...value, email: event.target.value }))} /></Grid>
              <Grid size={{ xs: 12, sm: 6 }}><TextField fullWidth type="tel" label={t('Phone')} value={newClient.phone} inputProps={{ maxLength: 40 }} disabled={newClientBusy} onChange={event => setNewClient(value => ({ ...value, phone: event.target.value }))} /></Grid>
              <Grid size={{ xs: 12, sm: 6 }}><TextField fullWidth type="date" label={t('Date of birth (optional)')} value={newClient.date_of_birth} InputLabelProps={{ shrink: true }} disabled={newClientBusy} onChange={event => setNewClient(value => ({ ...value, date_of_birth: event.target.value }))} /></Grid>
            </Grid>
            {newClientError && <Alert severity="error">{newClientError}</Alert>}
            {duplicateClients.length > 0 && <Alert severity="warning">{t('Possible duplicate client. Select an existing match from search or ask clinic staff to review before creating another record.')}{duplicateClients.map(candidate => <Typography key={candidate.id} variant="body2">{candidate.display_name} — {candidate.email}</Typography>)}</Alert>}
            <Button type="submit" variant="contained" disabled={newClientBusy}>{t(newClientBusy ? 'Saving…' : 'Save new client')}</Button>
          </Stack>}
        </Box>}
        {client && <Paper variant="outlined" sx={{ p: 2, borderColor: 'primary.main', borderWidth: 2 }}><Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} justifyContent="space-between" alignItems={{ sm: 'center' }}>
          <Box><Typography variant="overline" color="primary">{t('Selected client')}</Typography><Typography fontWeight={700}>{client.display_name}</Typography><Typography variant="body2">{client.email}</Typography><Typography variant="body2" color="text.secondary">{client.phone || t('No phone number on file')}</Typography></Box>
          <Button onClick={() => { setClient(null); setClientQuery(''); setClientBirthdate(''); setClients([]); setDestination(emptyDestination()); setClientAddressState('idle'); setCoverage(null); setAreaApproved(false); }}>{t('Change client')}</Button>
        </Stack>{createdClientId === Number(client.id) && <Stack spacing={1} mt={2}>
          <Typography variant="body2">{t('This new client can be booked now. A portal invitation does not grant access to records until identity review is approved.')}</Typography>
          <Button variant="outlined" disabled={invitationBusy} onClick={() => void sendInvitation()}>{t(invitationBusy ? 'Sending invitation…' : 'Send portal invitation email')}</Button>
          {invitationStatus && <Alert severity={invitationLink ? 'warning' : 'info'}>{invitationStatus}</Alert>}
          {invitationLink && <><TextField fullWidth label={t('Private invitation link — shown only now')} value={invitationLink} slotProps={{ input: { readOnly: true } }} /><Button onClick={() => void navigator.clipboard.writeText(invitationLink)}>{t('Copy invitation link')}</Button></>}
        </Stack>}</Paper>}
        <TextField select label={t('Visit type')} value={mode} onChange={event=>{setMode(event.target.value as 'clinic'|'mobile');setLocation('');setService('');setPractitioner('');setDuration('');setCoverage(null);setAreaApproved(false);clearSlots();}}><MenuItem value="mobile">{t('On-Site (client location)')}</MenuItem><MenuItem value="clinic">{t('In clinic')}</MenuItem></TextField>
        {eligibleOptions.length===0&&<Alert severity="info">{t('No services are configured for this visit type. Enable it under Service assignments and choose a base location.')}</Alert>}
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, sm: 6 }}>{comboSelect(t('Base location / service area'), location, eligibleOptions, 'location_id', row => row.location_name, value => { setLocation(value); setService(''); setPractitioner(''); setDuration(''); setDate(''); setCoverage(null); setAreaApproved(false); clearSlots(); })}</Grid>
          <Grid size={{ xs: 12, sm: 6 }}>{comboSelect(t('Service'), service, locationRows, 'service_id', row => row.service_name, value => { setService(value); setPractitioner(''); setDuration(''); setCoverage(null); setAreaApproved(false); clearSlots(); })}</Grid>
          <Grid size={{ xs: 12, sm: 6 }}>{practitionerLocked
            ? <TextField fullWidth label={t('Practitioner')} value={assignedPractitioner?.practitioner_name ?? ''} InputProps={{ readOnly: true }} helperText={t('Appointments booked in your practitioner workspace are assigned to you.')} />
            : comboSelect(t('Practitioner'), practitioner, serviceRows, 'practitioner_id', row => row.practitioner_name, value => { setPractitioner(value); setDuration(''); setCoverage(null); setAreaApproved(false); clearSlots(); })}</Grid>
          <Grid size={{ xs: 12, sm: 6 }}>{comboSelect(t('Duration'), duration, practitionerRows, 'duration_option_id', row => t('{{minutes}} minutes — {{price}}',{minutes:row.duration_minutes,price:money(Number(row.base_price_cents))}), value => { setDuration(value); clearSlots(); })}</Grid>
        </Grid>
        {mode==='mobile'&&<Stack spacing={2}><Typography variant="h6">{t('Visit address')}</Typography>
          {clientAddressState==='loading'&&<Stack direction="row" spacing={1} alignItems="center" role="status"><CircularProgress size={20}/><Typography>{t('Loading saved client address…')}</Typography></Stack>}
          {clientAddressState==='saved'&&<Alert severity="info">{t('The saved client address is loaded. You can replace it using Google address suggestions. Changes apply only to this appointment.')}</Alert>}
          {clientAddressState==='custom'&&<Alert severity="info">{t('Visit address changes apply only to this appointment and do not update the client profile.')}</Alert>}
          {clientAddressState==='missing'&&<Alert severity="info">{t('This client has no saved service address. Find an address with Google or enter it manually.')}</Alert>}
          {clientAddressState==='error'&&<Alert severity="warning">{t('The saved client address could not be loaded. Find an address with Google or enter it manually.')}</Alert>}
          <AddressEntry required showInstructions disabled={coverageBusy||clientAddressState==='loading'} value={destination} onChange={value=>{setDestination({...value,instructions:value.instructions??''});setClientAddressState('custom');setCoverage(null);setAreaApproved(false);}}/><Alert severity="info">{t('Google validates the address and calculates driving distance from the selected base location. The address must be within the configured On-Site service area.')}</Alert>{areaApproved&&<Alert severity="success">{t('Clinic staff approved this address for the selected On-Site service. No new distance check is needed.')}</Alert>}<Button variant="outlined" disabled={!selected||!addressComplete||coverageBusy||clientAddressState==='loading'} onClick={()=>void validateCoverage()}>{t(coverageBusy?'Validating address…':'Validate address and coverage')}</Button>{coverage&&<Alert severity="success">{t('Address confirmed: {{distance}} km driving distance ({{radius}} km limit).',{distance:coverage.distance_km,radius:coverage.radius_km})}</Alert>}{coverage&&client&&canApproveOnsiteArea&&!areaApproved&&<Button variant="outlined" disabled={approvalBusy} onClick={()=>void approveCoverage()}>{t(approvalBusy?'Saving approval…':'Approve this address for future On-Site bookings')}</Button>}{areaApproved&&canApproveOnsiteArea&&<Button variant="text" color="warning" disabled={approvalBusy} onClick={()=>void revokeCoverage()}>{t(approvalBusy?'Removing approval…':'Remove On-Site approval')}</Button>}</Stack>}
        <Button variant="contained" disabled={!client || !selected || !addressReady} onClick={() => { setDate(date || today(timezone)); setStep(1); }}>{t('Find a time')}</Button>
      </Stack>}
      {step === 1 && <Stack spacing={2}>
        <Typography>{t('Availability in {{timezone}}. Choose a day to see current openings.',{timezone})}</Typography>
        <Stack component="form" direction="row" gap={2} onSubmit={findSlots}><TextField required type="date" label={t('Appointment date')} value={date} disabled={busy} InputLabelProps={{ shrink: true }} inputProps={{ min: today(timezone) }} onChange={event => { setDate(event.target.value); clearSlots(); }} /><Button type="submit" variant="outlined" disabled={busy || !date}>{t(busy ? 'Searching…' : 'Find times')}</Button></Stack>
        {searched && slots.length === 0 && <Alert severity="info">{t('No bookable times on this day. Try another day or check working hours and room assignments.')}</Alert>}
        <Stack direction="row" flexWrap="wrap" gap={1}>{slots.map(item => <Button key={item.starts_at} variant={slot?.starts_at === item.starts_at ? 'contained' : 'outlined'} onClick={() => { setSlot(item); setRoom(item.available_room_ids.length === 1 ? String(item.available_room_ids[0]) : ''); }}>{displayTime(item.starts_at, timezone, i18n.resolvedLanguage)}</Button>)}</Stack>
        {slot && needsRoom && <TextField select required fullWidth label={t('Available room')} value={room} onChange={event => setRoom(event.target.value)}>{slot.available_room_ids.map(roomId => <MenuItem key={roomId} value={String(roomId)}>{rooms.find(item => Number(item.id) === Number(roomId))?.name ?? `Room ${roomId}`}</MenuItem>)}</TextField>}
        <Button variant="contained" disabled={!slot || busy || (needsRoom && !room)} onClick={() => setStep(2)}>{t('Review appointment')}</Button>
      </Stack>}
      {step === 2 && selected && slot && client && <Stack spacing={2}>
        <Typography variant="h6">{client.display_name}</Typography><Typography>{selected.service_name} · {t('{{minutes}} minutes',{minutes:selected.duration_minutes})} · {selected.practitioner_name}</Typography>
        <Typography>{displayTime(slot.starts_at, timezone, i18n.resolvedLanguage)} – {displayTime(slot.ends_at, timezone, i18n.resolvedLanguage)} ({timezone})</Typography><Typography>{selected.location_name}{room ? ` · ${rooms.find(item => String(item.id) === room)?.name ?? t('Room {{number}}', { number: room })}` : ''}</Typography>
        <Typography>{t(mode==='mobile'?'On-Site (client location)':'In clinic')}</Typography>{mode==='mobile'&&<><Typography>{addressText(destination, t('Address unavailable'))}</Typography>{coverage&&<Typography>{t('{{distance}} km driving distance within a {{radius}} km service area.',{distance:coverage.distance_km,radius:coverage.radius_km})}</Typography>}<Typography>{t('Travel reserved: {{minutes}} minutes before and after',{minutes:selected.travel_buffer_minutes})}</Typography></>}
        <Typography>{t('Treatment: {{treatment}} · On-Site surcharge: {{mobile}} · Subtotal: {{subtotal}} CAD',{treatment:money(Number(selected.base_price_cents)),mobile:money(mobileFee),subtotal:money(Number(selected.base_price_cents)+mobileFee)})}</Typography><Alert severity="info">{t('Prices shown are before applicable taxes. Tax calculation and invoicing are not yet enabled.')}</Alert>
        <Divider /><Typography color="text.secondary">{t('Availability is checked again when you confirm. A confirmation email will be sent to the client.')}</Typography>
        {pending && !busy && <Alert severity="warning">{t('Confirmation could not be verified. Retry this same request to safely retrieve or complete it. Check the appointment list before starting a different booking.')}</Alert>}
        <Button variant="contained" disabled={busy} onClick={() => void confirm()}>{t(busy ? 'Confirming…' : pending ? 'Retry confirmation' : 'Confirm appointment')}</Button>
      </Stack>}
    </>}
    <Stack direction="row" justifyContent="space-between" mt={3}><Button disabled={busy || Boolean(pending)} onClick={cancel}>{t('Cancel')}</Button>{step > 0 && <Button disabled={busy || Boolean(pending)} onClick={() => setStep(value => value - 1)}>{t('Back')}</Button>}</Stack>
  </Paper>;
}

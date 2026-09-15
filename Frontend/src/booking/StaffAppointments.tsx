import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { Alert, Box, Button, Chip, CircularProgress, Divider, Grid, MenuItem, Paper, Stack, Step, StepLabel, Stepper, TextField, Typography } from '@mui/material';
import { CalendarPlus, RefreshCw, Search } from 'lucide-react';
import { useStaffAuth } from '../auth/AuthProvider';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type Client = { id: number; display_name: string; email: string };
type Combination = { location_id: number; location_name: string; timezone: string; service_id: number; service_name: string; requires_room: number; practitioner_id: number; practitioner_name: string; duration_option_id: number; duration_minutes: number };
type Room = { id: number; name: string; location_id: number };
type Slot = { duration_option_id: number; starts_at: string; ends_at: string; available_room_ids: number[] };
type Appointment = { id: number; client_name: string; service_name: string; practitioner_name: string; location_name: string; timezone: string; room_name: string | null; starts_at: string; ends_at: string; status: string };
type Payload = { client_id: number; location_id: number; service_id: number; practitioner_id: number; duration_option_id: number; starts_at: string; room_id?: number; idempotency_key: string };
class RequestError extends Error { constructor(message: string, readonly status: number, readonly code: string) { super(message); } }
function displayTime(value: string, zone: string, database = false) {
  return new Date(database ? `${value.replace(' ', 'T')}Z` : value).toLocaleString(undefined, { timeZone: zone, dateStyle: 'medium', timeStyle: 'short' });
}
function today(zone: string) { return new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()); }
function unique(rows: Combination[], key: 'location_id' | 'service_id' | 'practitioner_id' | 'duration_option_id') {
  return [...new Map(rows.map(row => [String(row[key]), row])).values()];
}

export function StaffAppointments({ canBook }: { canBook: boolean }) {
  const { getAccessToken } = useStaffAuth();
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [view, setView] = useState('upcoming');
  const [page, setPage] = useState(1);
  const [refresh, setRefresh] = useState(0);
  const [listBusy, setListBusy] = useState(true);
  const [listError, setListError] = useState('');
  const [notice, setNotice] = useState('');
  const [creating, setCreating] = useState(false);
  const request = useCallback(async (path: string, init: RequestInit = {}) => {
    const token = await getAccessToken();
    const response = await fetch(`${api}${path}`, { ...init, headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', ...init.headers } });
    const text = await response.text();
    let body;
    try { body = JSON.parse(text); } catch { throw new RequestError(`The server returned an unreadable response (HTTP ${response.status}). Try again or contact the administrator.`, response.status, 'invalid_response'); }
    if (!response.ok) throw new RequestError(body?.error?.message ?? `Request failed (HTTP ${response.status}).`, response.status, body?.error?.code ?? 'request_failed');
    return body.data;
  }, [getAccessToken]);
  useEffect(() => {
    const controller = new AbortController(); setListBusy(true); setListError('');
    void request(`/appointments?view=${view}&page=${page}`, { signal: controller.signal })
      .then(data => { if (!controller.signal.aborted) setAppointments(data); })
      .catch(error => { if (!controller.signal.aborted) setListError(error.message); })
      .finally(() => { if (!controller.signal.aborted) setListBusy(false); });
    return () => controller.abort();
  }, [request, view, page, refresh]);
  return <Stack spacing={3}>
    <Stack direction={{ xs: 'column', sm: 'row' }} gap={2} justifyContent="space-between">
      <Box><Typography variant="h5">Appointments</Typography><Typography color="text.secondary">Times are shown in each clinic location’s timezone.</Typography></Box>
      {canBook && !creating && <Button variant="contained" startIcon={<CalendarPlus size={18} />} onClick={() => { setCreating(true); setNotice(''); }}>Book appointment</Button>}
    </Stack>
    {notice && <Alert severity="success" onClose={() => setNotice('')}>{notice}</Alert>}
    {creating && <BookingForm request={request} cancel={() => setCreating(false)} complete={id => { setCreating(false); setNotice(`Appointment #${id} confirmed. Confirmation email is queued; delivery is not yet enabled.`); setView('upcoming'); setPage(1); setRefresh(value => value + 1); }} />}
    <Paper variant="outlined" sx={{ p: 3 }}>
      <Stack direction="row" gap={2} justifyContent="space-between" mb={2}>
        <TextField select size="small" label="Show" value={view} onChange={event => { setView(event.target.value); setPage(1); }} sx={{ minWidth: 170 }}><MenuItem value="upcoming">Upcoming</MenuItem><MenuItem value="past">Past</MenuItem><MenuItem value="all">All appointments</MenuItem></TextField>
        <Button startIcon={<RefreshCw size={16} />} disabled={listBusy} onClick={() => setRefresh(value => value + 1)}>Refresh</Button>
      </Stack>
      {listError && <Alert severity="error">{listError}</Alert>}
      {listBusy ? <CircularProgress aria-label="Loading appointments" /> : !listError && <Stack spacing={2}>
        {appointments.length === 0 && <Typography color="text.secondary">No appointments in this view.</Typography>}
        {appointments.map(item => <Box key={item.id} sx={{ p: 2, border: '1px solid', borderColor: 'divider', borderRadius: 2 }}>
          <Stack direction="row" flexWrap="wrap" gap={1} alignItems="center"><Typography fontWeight={700}>{item.client_name} · {item.service_name}</Typography><Chip size="small" label={item.status.replaceAll('_', ' ')} variant="outlined" /></Stack>
          <Typography>{displayTime(item.starts_at, item.timezone, true)} – {displayTime(item.ends_at, item.timezone, true)}</Typography>
          <Typography color="text.secondary">{item.practitioner_name} · {item.location_name}{item.room_name ? ` · ${item.room_name}` : ''} · #{item.id}</Typography>
        </Box>)}
        <Stack direction="row" justifyContent="space-between" alignItems="center"><Button disabled={page === 1} onClick={() => setPage(value => value - 1)}>Previous</Button><Typography>Page {page}</Typography><Button disabled={appointments.length < 50} onClick={() => setPage(value => value + 1)}>Next</Button></Stack>
      </Stack>}
    </Paper>
  </Stack>;
}

type FormProps = { request: (path: string, init?: RequestInit) => Promise<any>; cancel: () => void; complete: (id: number) => void };
function BookingForm({ request, cancel, complete }: FormProps) {
  const [options, setOptions] = useState<Combination[]>([]);
  const [rooms, setRooms] = useState<Room[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [step, setStep] = useState(0);
  const [clientQuery, setClientQuery] = useState('');
  const [clients, setClients] = useState<Client[]>([]);
  const [client, setClient] = useState<Client | null>(null);
  const [clientBusy, setClientBusy] = useState(false);
  const [clientMore, setClientMore] = useState(false);
  const [clientError, setClientError] = useState('');
  const clientSearch = useRef<AbortController | null>(null);
  const [location, setLocation] = useState('');
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
  const selected = options.find(row => String(row.location_id) === location && String(row.service_id) === service && String(row.practitioner_id) === practitioner && String(row.duration_option_id) === duration);
  const timezone = options.find(row => String(row.location_id) === location)?.timezone ?? 'America/Toronto';
  const locationRows = options.filter(row => String(row.location_id) === location);
  const serviceRows = locationRows.filter(row => String(row.service_id) === service);
  const practitionerRows = serviceRows.filter(row => String(row.practitioner_id) === practitioner);
  useEffect(() => {
    const controller = new AbortController();
    void request('/booking-options', { signal: controller.signal }).then(data => { if (!controller.signal.aborted) { setOptions(data.combinations); setRooms(data.rooms); } })
      .catch(cause => { if (!controller.signal.aborted) setError(cause.message); }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [request]);
  useEffect(() => () => clientSearch.current?.abort(), []);
  useEffect(() => {
    if (!pending) return;
    const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); };
    window.addEventListener('beforeunload', warn); return () => window.removeEventListener('beforeunload', warn);
  }, [pending]);
  const searchClients = async (event: FormEvent) => {
    event.preventDefault(); clientSearch.current?.abort(); const controller = new AbortController(); clientSearch.current = controller;
    setClientBusy(true); setClientError('');
    try { const data = await request(`/clients?status=active&q=${encodeURIComponent(clientQuery)}`, { signal: controller.signal }); if (!controller.signal.aborted) { setClients(data.items); setClientMore(data.has_more); } }
    catch (cause) { if (!controller.signal.aborted) setClientError(cause instanceof Error ? cause.message : 'Unable to search clients.'); }
    finally { if (!controller.signal.aborted) setClientBusy(false); }
  };
  const clearSlots = () => { setSlot(null); setRoom(''); setSlots([]); setSearched(false); setError(''); };
  const findSlots = async (event: FormEvent) => {
    event.preventDefault(); if (!selected) return; setBusy(true); clearSlots();
    try {
      const data = await request(`/availability?location_id=${location}&service_id=${service}&practitioner_id=${practitioner}&date_from=${date}&date_to=${date}`);
      setSlots(data.availability.filter((item: Slot) => Number(item.duration_option_id) === Number(duration))); setSearched(true);
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Unable to load times.'); }
    finally { setBusy(false); }
  };
  const confirm = async () => {
    if (sending.current || !client || !selected || !slot) return;
    sending.current = true; setBusy(true); setError('');
    const payload = pendingRef.current ?? { client_id: Number(client.id), location_id: Number(location), service_id: Number(service), practitioner_id: Number(practitioner), duration_option_id: Number(duration), starts_at: slot.starts_at, ...(Number(selected.requires_room) ? { room_id: Number(room) } : {}), idempotency_key: crypto.randomUUID() };
    pendingRef.current = payload; setPending(payload);
    try { const result = await request('/appointments', { method: 'POST', body: JSON.stringify(payload) }); pendingRef.current = null; setPending(null); complete(result.id); }
    catch (cause) {
      const rejected = cause instanceof RequestError && cause.status >= 400 && cause.status < 500 && cause.code !== 'invalid_response';
      if (rejected) { pendingRef.current = null; setPending(null); setStep(1); clearSlots(); }
      setError(cause instanceof Error ? cause.message : 'Unable to confirm appointment.');
    } finally { sending.current = false; setBusy(false); }
  };
  const comboSelect = (label: string, value: string, rows: Combination[], key: 'location_id' | 'service_id' | 'practitioner_id' | 'duration_option_id', name: (row: Combination) => string, change: (value: string) => void) => <TextField required fullWidth select label={label} value={value} onChange={event => change(event.target.value)}>{unique(rows, key).map(row => <MenuItem key={row[key]} value={String(row[key])}>{name(row)}</MenuItem>)}</TextField>;
  return <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 } }}>
    <Typography variant="h5" mb={2}>New appointment</Typography>
    <Stepper activeStep={step} alternativeLabel sx={{ mb: 3 }}>{['Client and care', 'Available time', 'Review and confirm'].map(label => <Step key={label}><StepLabel>{label}</StepLabel></Step>)}</Stepper>
    {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
    {loading ? <CircularProgress aria-label="Loading booking options" /> : options.length === 0 ? <Alert severity="info">No booking combinations are configured. Check active services, durations, practitioners, and location assignments in administration.</Alert> : <>
      {step === 0 && <Stack spacing={3}>
        <Box component="form" onSubmit={searchClients}><Stack direction="row" gap={1}><TextField fullWidth label="Find an active client" value={clientQuery} inputProps={{ maxLength: 190 }} onChange={event => setClientQuery(event.target.value)} /><Button type="submit" disabled={clientBusy} startIcon={<Search size={16} />}>Search</Button></Stack></Box>
        {clientError && <Alert severity="error">{clientError}</Alert>}
        <TextField select fullWidth label="Client" value={client ? String(client.id) : ''} onChange={event => setClient(clients.find(item => String(item.id) === event.target.value) ?? null)} helperText={clientMore ? 'Showing the first 25 matches. Refine your search to find the client.' : 'Search above, then select a client. Add new clients from the Clients page.'}>
          {(client && !clients.some(item => item.id === client.id) ? [client, ...clients] : clients).map(item => <MenuItem value={String(item.id)} key={item.id}>{item.display_name} · {item.email}</MenuItem>)}
        </TextField>
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, sm: 6 }}>{comboSelect('Location', location, options, 'location_id', row => row.location_name, value => { setLocation(value); setService(''); setPractitioner(''); setDuration(''); setDate(''); clearSlots(); })}</Grid>
          <Grid size={{ xs: 12, sm: 6 }}>{comboSelect('Service', service, locationRows, 'service_id', row => row.service_name, value => { setService(value); setPractitioner(''); setDuration(''); clearSlots(); })}</Grid>
          <Grid size={{ xs: 12, sm: 6 }}>{comboSelect('Practitioner', practitioner, serviceRows, 'practitioner_id', row => row.practitioner_name, value => { setPractitioner(value); setDuration(''); clearSlots(); })}</Grid>
          <Grid size={{ xs: 12, sm: 6 }}>{comboSelect('Duration', duration, practitionerRows, 'duration_option_id', row => `${row.duration_minutes} minutes`, value => { setDuration(value); clearSlots(); })}</Grid>
        </Grid>
        <Button variant="contained" disabled={!client || !selected} onClick={() => { setDate(date || today(timezone)); setStep(1); }}>Find a time</Button>
      </Stack>}
      {step === 1 && <Stack spacing={2}>
        <Typography>Availability in {timezone}. Choose a day to see current openings.</Typography>
        <Stack component="form" direction="row" gap={2} onSubmit={findSlots}><TextField required type="date" label="Appointment date" value={date} disabled={busy} InputLabelProps={{ shrink: true }} inputProps={{ min: today(timezone) }} onChange={event => { setDate(event.target.value); clearSlots(); }} /><Button type="submit" variant="outlined" disabled={busy || !date}>{busy ? 'Searching…' : 'Find times'}</Button></Stack>
        {searched && slots.length === 0 && <Alert severity="info">No bookable times on this day. Try another day or check working hours and room assignments.</Alert>}
        <Stack direction="row" flexWrap="wrap" gap={1}>{slots.map(item => <Button key={item.starts_at} variant={slot?.starts_at === item.starts_at ? 'contained' : 'outlined'} onClick={() => { setSlot(item); setRoom(item.available_room_ids.length === 1 ? String(item.available_room_ids[0]) : ''); }}>{displayTime(item.starts_at, timezone)}</Button>)}</Stack>
        {slot && Number(selected?.requires_room) > 0 && <TextField select required fullWidth label="Available room" value={room} onChange={event => setRoom(event.target.value)}>{slot.available_room_ids.map(roomId => <MenuItem key={roomId} value={String(roomId)}>{rooms.find(item => Number(item.id) === Number(roomId))?.name ?? `Room ${roomId}`}</MenuItem>)}</TextField>}
        <Button variant="contained" disabled={!slot || busy || (Boolean(Number(selected?.requires_room)) && !room)} onClick={() => setStep(2)}>Review appointment</Button>
      </Stack>}
      {step === 2 && selected && slot && client && <Stack spacing={2}>
        <Typography variant="h6">{client.display_name}</Typography><Typography>{selected.service_name} · {selected.duration_minutes} minutes · {selected.practitioner_name}</Typography>
        <Typography>{displayTime(slot.starts_at, timezone)} – {displayTime(slot.ends_at, timezone)} ({timezone})</Typography><Typography>{selected.location_name}{room ? ` · ${rooms.find(item => String(item.id) === room)?.name ?? `Room ${room}`}` : ''}</Typography>
        <Divider /><Typography color="text.secondary">Availability is checked again when you confirm. Email delivery is not enabled yet; arrange confirmation directly with the client.</Typography>
        {pending && !busy && <Alert severity="warning">Confirmation could not be verified. Retry this same request to safely retrieve or complete it. Check the appointment list before starting a different booking.</Alert>}
        <Button variant="contained" disabled={busy} onClick={() => void confirm()}>{busy ? 'Confirming…' : pending ? 'Retry confirmation' : 'Confirm appointment'}</Button>
      </Stack>}
    </>}
    <Stack direction="row" justifyContent="space-between" mt={3}><Button disabled={busy || Boolean(pending)} onClick={cancel}>Cancel</Button>{step > 0 && <Button disabled={busy || Boolean(pending)} onClick={() => setStep(value => value - 1)}>Back</Button>}</Stack>
  </Paper>;
}

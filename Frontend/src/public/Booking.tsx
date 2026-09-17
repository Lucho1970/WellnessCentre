import { useEffect, useState } from 'react';
import { Alert, Box, Button, CircularProgress, Container, Grid, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { apiRequest } from '../shared/api';
import { portalLink } from '../shared/urls';

type Location = { id: number; name: string; timezone?: string };
type Service = { id: number; name: string; description: string | null; price_cents: number; durations: { id: number; minutes: number; price_cents: number }[] };
type Practitioner = { id: number; display_name: string; discipline: string; credentials: string | null };
type Slot = { duration_option_id: number; starts_at: string; ends_at: string };
type Availability = { timezone: string; availability: Slot[] };
const message = (cause: unknown) => cause instanceof Error ? cause.message : 'Unable to load online booking.';

export function Booking() {
  const [mode,setMode]=useState('mobile');
  const [locations, setLocations] = useState<Location[]>([]), [services, setServices] = useState<Service[]>([]), [practitioners, setPractitioners] = useState<Practitioner[]>([]);
  const [locationId, setLocationId] = useState(''), [serviceId, setServiceId] = useState(''), [practitionerId, setPractitionerId] = useState('');
  const [availability, setAvailability] = useState<Availability>({ timezone: 'America/Toronto', availability: [] });
  const [slot, setSlot] = useState<Slot | null>(null), [loading, setLoading] = useState(true), [practitionerBusy, setPractitionerBusy] = useState(false), [slotBusy, setSlotBusy] = useState(false);
  const [error, setError] = useState(''), [practitionerError, setPractitionerError] = useState(''), [slotError, setSlotError] = useState(''), [retry, setRetry] = useState(0);
  const service = services.find(item => item.id === Number(serviceId));

  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError('');
    void Promise.all([apiRequest<Location[]>('/locations', { signal: controller.signal }), apiRequest<Service[]>('/services', { signal: controller.signal })])
      .then(([nextLocations, nextServices]) => { if (!controller.signal.aborted) { setLocations(nextLocations); setServices(nextServices); setLocationId(String(nextLocations[0]?.id ?? '')); setServiceId(String(nextServices[0]?.id ?? '')); } })
      .catch(cause => { if (!controller.signal.aborted) setError(message(cause)); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [retry]);
  useEffect(() => {
    const controller = new AbortController(); setPractitioners([]); setPractitionerId(''); setSlot(null); setPractitionerError('');
    if (!serviceId) return () => controller.abort();
    setPractitionerBusy(true);
    void apiRequest<Practitioner[]>(`/practitioners?service_id=${serviceId}`, { signal: controller.signal })
      .then(data => { if (!controller.signal.aborted) { setPractitioners(data); setPractitionerId(String(data[0]?.id ?? '')); } })
      .catch(cause => { if (!controller.signal.aborted) setPractitionerError(message(cause)); })
      .finally(() => { if (!controller.signal.aborted) setPractitionerBusy(false); });
    return () => controller.abort();
  }, [serviceId, retry]);
  useEffect(() => {
    const controller = new AbortController(); setSlot(null); setAvailability(previous => ({ ...previous, availability: [] })); setSlotError('');
    if (!locationId || !serviceId || !practitionerId) { setSlotBusy(false); return () => controller.abort(); }
    setSlotBusy(true);
    const timezone = locations.find(item => String(item.id) === locationId)?.timezone || 'America/Toronto';
    const date = (value: Date) => new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(value);
    const query = new URLSearchParams({ delivery_mode: mode, location_id: locationId, service_id: serviceId, practitioner_id: practitionerId, date_from: date(new Date()), date_to: date(new Date(Date.now() + 6 * 86400000)) });
    void apiRequest<Availability>(`/availability?${query}`, { signal: controller.signal })
      .then(data => { if (!controller.signal.aborted) setAvailability(data); })
      .catch(cause => { if (!controller.signal.aborted) setSlotError(message(cause)); })
      .finally(() => { if (!controller.signal.aborted) setSlotBusy(false); });
    return () => controller.abort();
  }, [locationId, serviceId, practitionerId, locations, retry, mode]);

  const handoff = new URL(portalLink('client/book'));
  if (slot) handoff.search = new URLSearchParams({ delivery_mode: mode, location_id: locationId, service_id: serviceId, practitioner_id: practitionerId, duration_option_id: String(slot.duration_option_id), starts_at: slot.starts_at }).toString();
  return <Container maxWidth="lg" sx={{ py: { xs: 4, md: 7 } }}>
    <Typography variant="overline" color="primary">Book online</Typography><Typography variant="h3" component="h1">Find a time that fits your life.</Typography>
    <Alert severity="info" sx={{ my: 3 }}>Availability browsing is open. Client sign-in and online confirmation are not available yet; contact the clinic to book. Selecting a time does not reserve it.</Alert>
    {loading && <CircularProgress aria-label="Loading booking options" />}
    {error && <Alert severity="error" action={<Button color="inherit" onClick={() => setRetry(value => value + 1)}>Retry</Button>}>{error}</Alert>}
    {!loading && !error && (!services.length || !locations.length) && <Alert severity="info">Online services and locations have not been configured yet.</Alert>}
    {!loading && !error && services.length > 0 && locations.length > 0 && <Grid container spacing={3}>
      <Grid size={{ xs: 12, md: 5 }}><Paper variant="outlined" sx={{ p: 3 }}><Stack spacing={3}>
        <Typography variant="h5" component="h2">Choose care</Typography>
        <TextField select label="Visit type" value={mode} onChange={event=>{setSlot(null);setMode(event.target.value);}}><MenuItem value="mobile">At client location</MenuItem><MenuItem value="clinic">In clinic</MenuItem></TextField>
        <TextField select label="Base location / service area" value={locationId} onChange={event => { setSlot(null); setLocationId(event.target.value); }}>{locations.map(item => <MenuItem key={item.id} value={String(item.id)}>{item.name}</MenuItem>)}</TextField>
        <TextField select label="Service" value={serviceId} onChange={event => { setSlot(null); setPractitionerId(''); setServiceId(event.target.value); }}>{services.map(item => <MenuItem key={item.id} value={String(item.id)}>{item.name}</MenuItem>)}</TextField>
        {service && <Box><Typography color="text.secondary">{service.description}</Typography><Typography mt={1}>Treatment from ${(Number(service.price_cents) / 100).toFixed(2)} before taxes</Typography><Typography variant="body2">{mode==='mobile'?'Mobile surcharge may apply; staff will confirm coverage, travel time and price. ':''}{service.durations.map(duration => `${duration.minutes} min`).join(' / ')}</Typography></Box>}
      </Stack></Paper></Grid>
      <Grid size={{ xs: 12, md: 7 }}><Paper variant="outlined" sx={{ p: 3 }}><Stack spacing={2}>
        <Typography variant="h5" component="h2">Choose a time</Typography>
        <TextField select label="Practitioner" value={practitionerId} disabled={practitionerBusy || !practitioners.length} onChange={event => { setSlot(null); setPractitionerId(event.target.value); }}>{practitioners.map(item => <MenuItem key={item.id} value={String(item.id)}>{item.display_name} · {item.credentials || item.discipline}</MenuItem>)}</TextField>
        {(practitionerBusy || slotBusy) && <CircularProgress size={24} aria-label="Loading available times" />}
        {(practitionerError || slotError) && <Alert severity="error" action={<Button color="inherit" onClick={() => setRetry(value => value + 1)}>Retry</Button>}>{practitionerError || slotError}</Alert>}
        {!practitionerBusy && !practitionerError && !practitioners.length && <Alert severity="info">No practitioner is assigned to this service yet.</Alert>}
        {!slotBusy && !practitionerBusy && practitionerId && !slotError && !availability.availability.length && <Alert severity="info">No available times were found in the next seven days.</Alert>}
        {!slotBusy && <><Typography variant="body2" color="text.secondary">Times shown in {availability.timezone}. Showing up to 24 available options.</Typography>
          <Grid container spacing={1}>{availability.availability.slice(0, 24).map(time => <Grid size={{ xs: 12, sm: 6 }} key={`${time.duration_option_id}-${time.starts_at}`}>
            <Button fullWidth aria-pressed={slot === time} variant={slot === time ? 'contained' : 'outlined'} onClick={() => setSlot(time)}>
              {new Date(time.starts_at).toLocaleString(undefined, { timeZone: availability.timezone, month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })} · {Math.round((Date.parse(time.ends_at) - Date.parse(time.starts_at)) / 60000)} min
            </Button></Grid>)}</Grid></>}
        <Button href={slot ? handoff.href : undefined} disabled={!slot || slotBusy || practitionerBusy} variant="contained">View client booking information</Button>
      </Stack></Paper></Grid>
    </Grid>}
  </Container>;
}

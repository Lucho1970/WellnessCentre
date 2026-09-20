import { useEffect, useState } from 'react';
import { Alert, Box, Button, CircularProgress, Container, Grid, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { apiRequest } from '../shared/api';
import { portalLink } from '../shared/urls';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router-dom';
import { formatCad, formatDateTime } from '../i18n/format';

type Location = { id: number; name: string; timezone?: string };
type Service = { id: number; name: string; description: string | null; price_cents: number; durations: { id: number; minutes: number; price_cents: number }[] };
type Practitioner = { id: number; display_name: string; discipline: string; credentials: string | null };
type Slot = { duration_option_id: number; starts_at: string; ends_at: string };
type Availability = { timezone: string; availability: Slot[] };
const dateInZone = (timezone: string) => new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
export function Booking() {
  const { t, i18n } = useTranslation();
  const [searchParams] = useSearchParams();
  const requestedPractitionerId = /^\d+$/.test(searchParams.get('practitioner_id') ?? '') ? searchParams.get('practitioner_id')! : '';
  const message = (cause: unknown) => cause instanceof Error ? cause.message : t('Unable to load online booking.');
  const money = (cents: number) => formatCad(cents, i18n.resolvedLanguage);
  const [mode,setMode]=useState('mobile');
  const [locations, setLocations] = useState<Location[]>([]), [services, setServices] = useState<Service[]>([]), [practitioners, setPractitioners] = useState<Practitioner[]>([]);
  const [locationId, setLocationId] = useState(''), [serviceId, setServiceId] = useState(''), [practitionerId, setPractitionerId] = useState('');
  const [appointmentDate, setAppointmentDate] = useState('');
  const [availability, setAvailability] = useState<Availability>({ timezone: 'America/Toronto', availability: [] });
  const [slot, setSlot] = useState<Slot | null>(null), [loading, setLoading] = useState(true), [practitionerBusy, setPractitionerBusy] = useState(false), [slotBusy, setSlotBusy] = useState(false);
  const [error, setError] = useState(''), [practitionerError, setPractitionerError] = useState(''), [slotError, setSlotError] = useState(''), [retry, setRetry] = useState(0);
  const service = services.find(item => item.id === Number(serviceId));
  const durationOption = (id: number) => service?.durations.find(option => Number(option.id) === Number(id));
  const selectedTimezone = locations.find(item => String(item.id) === locationId)?.timezone || 'America/Toronto';

  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError('');
    const servicePath = requestedPractitionerId ? `/services?practitioner_id=${requestedPractitionerId}` : '/services';
    void Promise.all([apiRequest<Location[]>('/locations', { signal: controller.signal }), apiRequest<Service[]>(servicePath, { signal: controller.signal })])
      .then(([nextLocations, nextServices]) => { if (!controller.signal.aborted) { setLocations(nextLocations); setServices(nextServices); setLocationId(String(nextLocations[0]?.id ?? '')); setServiceId(String(nextServices[0]?.id ?? '')); } })
      .catch(cause => { if (!controller.signal.aborted) setError(message(cause)); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [retry, requestedPractitionerId]);
  useEffect(() => {
    const controller = new AbortController(); setPractitioners([]); setPractitionerId(''); setSlot(null); setPractitionerError('');
    if (!serviceId) return () => controller.abort();
    setPractitionerBusy(true);
    void apiRequest<Practitioner[]>(`/practitioners?service_id=${serviceId}`, { signal: controller.signal })
      .then(data => { if (!controller.signal.aborted) { setPractitioners(data); const requested = data.find(item => String(item.id) === requestedPractitionerId); setPractitionerId(String(requested?.id ?? data[0]?.id ?? '')); } })
      .catch(cause => { if (!controller.signal.aborted) setPractitionerError(message(cause)); })
      .finally(() => { if (!controller.signal.aborted) setPractitionerBusy(false); });
    return () => controller.abort();
  }, [serviceId, retry, requestedPractitionerId]);
  useEffect(() => {
    if (locationId) setAppointmentDate(current => current || dateInZone(selectedTimezone));
  }, [locationId, selectedTimezone]);
  useEffect(() => {
    const controller = new AbortController(); setSlot(null); setAvailability(previous => ({ ...previous, availability: [] })); setSlotError('');
    if (!locationId || !serviceId || !practitionerId || !appointmentDate) { setSlotBusy(false); return () => controller.abort(); }
    setSlotBusy(true);
    const query = new URLSearchParams({ delivery_mode: mode, location_id: locationId, service_id: serviceId, practitioner_id: practitionerId, date_from: appointmentDate, date_to: appointmentDate });
    void apiRequest<Availability>(`/availability?${query}`, { signal: controller.signal })
      .then(data => { if (!controller.signal.aborted) setAvailability(data); })
      .catch(cause => { if (!controller.signal.aborted) setSlotError(message(cause)); })
      .finally(() => { if (!controller.signal.aborted) setSlotBusy(false); });
    return () => controller.abort();
  }, [locationId, serviceId, practitionerId, appointmentDate, retry, mode]);

  const handoff = new URL(portalLink('client/book'));
  if (slot) handoff.search = new URLSearchParams({ delivery_mode: mode, location_id: locationId, service_id: serviceId, practitioner_id: practitionerId, duration_option_id: String(slot.duration_option_id), starts_at: slot.starts_at }).toString();
  return <Container maxWidth="lg" sx={{ py: { xs: 4, md: 7 } }}>
    <Typography variant="overline" color="primary">{t('Book online')}</Typography><Typography variant="h3" component="h1">{t('Find a time that fits your life.')}</Typography>
    <Alert severity="info" sx={{ my: 3 }}>{t('Browse available times, then sign in to review and confirm. Selecting a time does not reserve it.')}</Alert>
    {loading && <CircularProgress aria-label={t('Loading booking options')} />}
    {error && <Alert severity="error" action={<Button color="inherit" onClick={() => setRetry(value => value + 1)}>{t('Retry')}</Button>}>{error}</Alert>}
    {!loading && !error && (!services.length || !locations.length) && <Alert severity="info">{t('Online services and locations have not been configured yet.')}</Alert>}
    {!loading && !error && services.length > 0 && locations.length > 0 && <Grid container spacing={3}>
      <Grid size={{ xs: 12, md: 5 }}><Paper variant="outlined" sx={{ p: 3 }}><Stack spacing={3}>
        <Typography variant="h5" component="h2">{t('Choose care')}</Typography>
        <TextField select label={t('Visit type')} value={mode} onChange={event=>{setSlot(null);setMode(event.target.value);}}><MenuItem value="mobile">{t('On-Site (client location)')}</MenuItem><MenuItem value="clinic">{t('In clinic')}</MenuItem></TextField>
        <TextField select label={t('Base location / service area')} value={locationId} onChange={event => { setSlot(null); setLocationId(event.target.value); }}>{locations.map(item => <MenuItem key={item.id} value={String(item.id)}>{item.name}</MenuItem>)}</TextField>
        <TextField select label={t('Service')} value={serviceId} onChange={event => { setSlot(null); setPractitionerId(''); setServiceId(event.target.value); }}>{services.map(item => <MenuItem key={item.id} value={String(item.id)}>{item.name}</MenuItem>)}</TextField>
        {service && <Box><Typography color="text.secondary">{service.description}</Typography><Typography mt={1}>{t('Treatment options before taxes')}</Typography><Typography variant="body2">{service.durations.map(option => t('{{minutes}} min — {{price}}', { minutes: option.minutes, price: money(Number(option.price_cents)) })).join(' · ')}</Typography>{mode === 'mobile' && <Typography variant="body2">{t('A separate On-Site surcharge may apply; staff will confirm coverage and travel time.')}</Typography>}</Box>}
      </Stack></Paper></Grid>
      <Grid size={{ xs: 12, md: 7 }}><Paper variant="outlined" sx={{ p: 3 }}><Stack spacing={2}>
        <Typography variant="h5" component="h2">{t('Choose a time')}</Typography>
        <TextField select label={t('Practitioner')} value={practitionerId} disabled={practitionerBusy || !practitioners.length} onChange={event => { setSlot(null); setPractitionerId(event.target.value); }}>{practitioners.map(item => <MenuItem key={item.id} value={String(item.id)}>{item.display_name} · {item.credentials || item.discipline}</MenuItem>)}</TextField>
        <TextField type="date" label={t('Appointment date')} value={appointmentDate} disabled={practitionerBusy || !practitionerId} InputLabelProps={{ shrink: true }} inputProps={{ min: dateInZone(selectedTimezone) }} onChange={event => { setSlot(null); setAppointmentDate(event.target.value); }} />
        {(practitionerBusy || slotBusy) && <CircularProgress size={24} aria-label={t('Loading available times')} />}
        {(practitionerError || slotError) && <Alert severity="error" action={<Button color="inherit" onClick={() => setRetry(value => value + 1)}>{t('Retry')}</Button>}>{practitionerError || slotError}</Alert>}
        {!practitionerBusy && !practitionerError && !practitioners.length && <Alert severity="info">{t('No practitioner is assigned to this service yet.')}</Alert>}
        {!slotBusy && !practitionerBusy && practitionerId && appointmentDate && !slotError && !availability.availability.length && <Alert severity="info">{t('No available times were found on this date.')}</Alert>}
        {!slotBusy && appointmentDate && <><Typography variant="body2" color="text.secondary">{t('Times shown in {{timezone}}. Choose an available start time.', { timezone: availability.timezone })}</Typography>
          <Grid container spacing={1}>{availability.availability.map(time => <Grid size={{ xs: 12, sm: 6 }} key={`${time.duration_option_id}-${time.starts_at}`}>
            <Button fullWidth aria-pressed={slot === time} variant={slot === time ? 'contained' : 'outlined'} onClick={() => setSlot(time)}>
              {formatDateTime(time.starts_at, i18n.resolvedLanguage, { timeZone: availability.timezone, hour: 'numeric', minute: '2-digit' })} · {t('{{minutes}} min — {{price}}', { minutes: Math.round((Date.parse(time.ends_at) - Date.parse(time.starts_at)) / 60000), price: durationOption(time.duration_option_id) ? money(Number(durationOption(time.duration_option_id)!.price_cents)) : '' }).replace(/ — $/, '')}
            </Button></Grid>)}</Grid></>}
        <Button href={slot ? handoff.href : undefined} disabled={!slot || slotBusy || practitionerBusy} variant="contained">{t('View client booking information')}</Button>
      </Stack></Paper></Grid>
    </Grid>}
  </Container>;
}

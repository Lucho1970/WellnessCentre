import { Alert, TextField, withFormValidation, useFormValidation } from '../shared/FormValidation';
import { useEffect, useMemo, useState } from 'react';
import { Box, Button, CircularProgress, Container, Grid, MenuItem, Paper, Stack, Typography } from '@mui/material';
import { apiRequest } from '../shared/api';
import { portalLink, publicLink } from '../shared/urls';
import { useTranslation } from 'react-i18next';
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { formatCad, formatDateTime } from '../i18n/format';
import { AvailabilityDateCalendar } from '../public/AvailabilityDateCalendar';
import { serviceBookingPath, type PublicPractitioner } from './catalogue';
import { ShareBookingLink } from './ShareBookingLink';
import { PractitionerNameHover } from '../shared/PractitionerPersonCard';

type Location = { id: number; name: string; timezone?: string };
type Service = { id: number; slug: string; name: string; description: string | null; price_cents: number; durations: { id: number; minutes: number; price_cents: number }[] };
type Practitioner = { id: number; display_name: string; discipline: string; credentials: string | null };
type Slot = { duration_option_id: number; starts_at: string; ends_at: string };
type Availability = { availability: Slot[] };
const dateInZone = (timezone: string) => new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
const slotDateInZone = (value: string, timezone: string) => new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(value));
function GuestBookingForm() {
  const formValidation = useFormValidation();
  const { t, i18n } = useTranslation();
  const location = useLocation();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const requestedPractitionerId = /^\d+$/.test(searchParams.get('practitioner_id') ?? '') ? searchParams.get('practitioner_id')! : '';
  const bookingSlug = location.pathname.match(/^\/services\/([a-z0-9-]+)\/book$/)?.[1] ?? '';
  const requestedServiceSlug = bookingSlug || (/^[a-z0-9-]+$/.test(searchParams.get('service') ?? '') ? searchParams.get('service')! : '');
  const requestedDuration = /^\d+$/.test(searchParams.get('duration') ?? '') ? Number(searchParams.get('duration')) : 0;
  const message = (cause: unknown) => cause instanceof Error ? cause.message : t('Unable to load online booking.');
  const money = (cents: number) => formatCad(cents, i18n.resolvedLanguage);
  const [mode,setMode]=useState('mobile');
  const [locations, setLocations] = useState<Location[]>([]), [services, setServices] = useState<Service[]>([]), [practitioners, setPractitioners] = useState<Practitioner[]>([]);
  const [publicPractitioners, setPublicPractitioners] = useState<PublicPractitioner[]>([]), [preferredPractitionerId, setPreferredPractitionerId] = useState(requestedPractitionerId);
  const [locationId, setLocationId] = useState(''), [serviceId, setServiceId] = useState(''), [practitionerId, setPractitionerId] = useState(''), [durationOptionId, setDurationOptionId] = useState('');
  const [appointmentDate, setAppointmentDate] = useState('');
  const [availability, setAvailability] = useState<Availability>({ availability: [] });
  const [slot, setSlot] = useState<Slot | null>(null), [loading, setLoading] = useState(true), [practitionerBusy, setPractitionerBusy] = useState(false), [slotBusy, setSlotBusy] = useState(false);
  const [error, setError] = useState(''), [practitionerError, setPractitionerError] = useState(''), [slotError, setSlotError] = useState(''), [retry, setRetry] = useState(0);
  const service = services.find(item => item.id === Number(serviceId));
  const durations = useMemo(() => [...(service?.durations ?? [])].sort((a, b) => a.minutes - b.minutes || a.id - b.id), [service]);
  const selectedDuration = durations.find(option => String(option.id) === durationOptionId);
  const hasDuration = Boolean(selectedDuration);
  const selectedTimezone = locations.find(item => String(item.id) === locationId)?.timezone || 'America/Toronto';
  const selectedDaySlots = useMemo(() => durationOptionId ? availability.availability.filter(item => String(item.duration_option_id) === durationOptionId && slotDateInZone(item.starts_at, selectedTimezone) === appointmentDate) : [], [availability, appointmentDate, durationOptionId, selectedTimezone]);

  useEffect(() => {
    const requested = durations.find(option => Number(option.minutes) === requestedDuration);
    setDurationOptionId(requested ? String(requested.id) : durations.length === 1 ? String(durations[0].id) : '');
    setSlot(null);
  }, [durations, requestedDuration]);

  useEffect(() => {
    const controller = new AbortController(); setLoading(true); (formValidation.clear(), setError(''));
    const servicePath = preferredPractitionerId ? `/services?practitioner_id=${preferredPractitionerId}` : '/services';
    void Promise.all([apiRequest<Location[]>('/locations', { signal: controller.signal }), apiRequest<Service[]>(servicePath, { signal: controller.signal }), apiRequest<PublicPractitioner[]>('/public/practitioners', { signal: controller.signal })])
      .then(([nextLocations, nextServices, nextPublicPractitioners]) => { if (!controller.signal.aborted) { const requestedService=nextServices.find(item=>item.slug===requestedServiceSlug);setLocations(nextLocations); setServices(nextServices); setPublicPractitioners(nextPublicPractitioners.filter(item => item.booking_practitioner_id)); setLocationId(String(nextLocations[0]?.id ?? '')); setServiceId(String(requestedServiceSlug ? requestedService?.id ?? '' : nextServices[0]?.id ?? '')); if (requestedServiceSlug && !requestedService) setError(t('This treatment is not available for online booking.')); } })
      .catch(cause => { formValidation.capture(cause); if (!controller.signal.aborted) setError(message(cause)); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [retry, preferredPractitionerId, requestedServiceSlug, t]);
  useEffect(() => {
    const controller = new AbortController(); setPractitioners([]); setPractitionerId(''); setSlot(null); (formValidation.clear(), setPractitionerError(''));
    if (!serviceId) return () => controller.abort();
    setPractitionerBusy(true);
    void apiRequest<Practitioner[]>(`/practitioners?service_id=${serviceId}`, { signal: controller.signal })
      .then(data => { if (!controller.signal.aborted) { setPractitioners(data); const requested = data.find(item => String(item.id) === preferredPractitionerId); setPractitionerId(String(requested?.id ?? data[0]?.id ?? '')); } })
      .catch(cause => { formValidation.capture(cause); if (!controller.signal.aborted) setPractitionerError(message(cause)); })
      .finally(() => { if (!controller.signal.aborted) setPractitionerBusy(false); });
    return () => controller.abort();
  }, [serviceId, retry, preferredPractitionerId]);
  useEffect(() => {
    if (locationId) setAppointmentDate(current => current || dateInZone(selectedTimezone));
  }, [locationId, selectedTimezone]);
  useEffect(() => {
    const controller = new AbortController(); setSlot(null); setAvailability(previous => ({ ...previous, availability: [] })); (formValidation.clear(), setSlotError(''));
    if (!locationId || !serviceId || !practitionerId || !appointmentDate || !hasDuration) { setSlotBusy(false); return () => controller.abort(); }
    setSlotBusy(true);
    const query = new URLSearchParams({ delivery_mode: mode, location_id: locationId, service_id: serviceId, practitioner_id: practitionerId, date_from: appointmentDate, date_to: appointmentDate });
    void apiRequest<Availability>(`/availability?${query}`, { signal: controller.signal })
      .then(data => { if (!controller.signal.aborted) setAvailability(data); })
      .catch(cause => { formValidation.capture(cause); if (!controller.signal.aborted) setSlotError(message(cause)); })
      .finally(() => { if (!controller.signal.aborted) setSlotBusy(false); });
    return () => controller.abort();
  }, [locationId, serviceId, practitionerId, appointmentDate, retry, mode, hasDuration]);

  const handoff = new URL(portalLink('book'));
  if (slot) for (const [key, value] of Object.entries({ delivery_mode: mode, location_id: locationId, service_id: serviceId, practitioner_id: practitionerId, duration_option_id: String(slot.duration_option_id), starts_at: slot.starts_at })) handoff.searchParams.set(key, value);
  const selectDuration = (id: string) => {
    setSlot(null);
    setDurationOptionId(id);
    if (!bookingSlug) return;
    const params = new URLSearchParams(location.search);
    const option = durations.find(item => String(item.id) === id);
    if (option) params.set('duration', String(option.minutes));
    else params.delete('duration');
    void navigate(`${location.pathname}${params.size ? `?${params}` : ''}`, { replace: true });
  };
  return <Container maxWidth="lg" sx={{ py: { xs: 4, md: 7 } }}>
    <Button component={Link} to="/" size="small">{t('Back to treatments')}</Button>
    <Typography variant="overline" color="primary" display="block" mt={2}>{t('Book online')}</Typography><Typography variant="h3" component="h1">{t('Find a time that fits your life.')}</Typography>
    {bookingSlug && service && <Box sx={{ mt: 2 }}><Typography variant="h6">{service.name}{selectedDuration ? ` · ${t('{{minutes}} minutes', { minutes: selectedDuration.minutes })}` : ''}</Typography><ShareBookingLink path={serviceBookingPath(bookingSlug, selectedDuration?.minutes, requestedPractitionerId ? Number(requestedPractitionerId) : undefined)} title={service.name}/></Box>}
    <Alert severity="info" sx={{ my: 3 }}>{t('Browse available times, then sign in to review and confirm. Selecting a time does not reserve it.')}</Alert>
    {loading && <CircularProgress aria-label={t('Loading booking options')} />}
    {error && <Alert severity="error" action={<Button color="inherit" onClick={() => setRetry(value => value + 1)}>{t('Retry')}</Button>}>{error}</Alert>}
    {!loading && !error && (!services.length || !locations.length) && <Alert severity="info">{t('Online services and locations have not been configured yet.')}</Alert>}
    {!loading && !error && services.length > 0 && <Stack spacing={2} mb={3}>
      {!bookingSlug && <><Typography variant="h5" component="h2">{t('Our services')}</Typography>
      <Stack direction="row" gap={1} flexWrap="wrap">{services.map(item => <Button key={item.id} variant={serviceId === String(item.id) ? 'contained' : 'outlined'} onClick={() => { setSlot(null); setServiceId(String(item.id)); setDurationOptionId(''); }}>{item.name}</Button>)}</Stack></>}
      {!bookingSlug && publicPractitioners.length > 0 && <><Typography variant="h5" component="h2">{t('Our practitioners')}</Typography><Stack direction="row" gap={1} flexWrap="wrap">{publicPractitioners.map(item => <PractitionerNameHover key={item.booking_practitioner_id} person={item}><Button variant={preferredPractitionerId === String(item.booking_practitioner_id) ? 'contained' : 'outlined'} onClick={() => { setSlot(null); setServiceId(''); setPractitionerId(''); setDurationOptionId(''); setPreferredPractitionerId(String(item.booking_practitioner_id)); }}>{item.booking_name || item.public_name}</Button></PractitionerNameHover>)}</Stack></>}
    </Stack>}
    {!loading && !error && services.length > 0 && locations.length > 0 && <Grid container spacing={3}>
      <Grid size={{ xs: 12, md: 5 }}><Paper variant="outlined" sx={{ p: 3 }}><Stack spacing={3}>
        <Typography variant="h5" component="h2">{t('Choose care')}</Typography>
        <TextField name="delivery_mode" select label={t('Visit type')} value={mode} onChange={event=>{setSlot(null);setMode(event.target.value);}}><MenuItem value="mobile">{t('On-Site (client location)')}</MenuItem><MenuItem value="clinic">{t('In clinic')}</MenuItem></TextField>
        <TextField name="location_id" select label={t('Base location / service area')} value={locationId} onChange={event => { setSlot(null); setLocationId(event.target.value); }}>{locations.map(item => <MenuItem key={item.id} value={String(item.id)}>{item.name}</MenuItem>)}</TextField>
        {!bookingSlug && <TextField name="preferredPractitionerId" select label={t('Start with a practitioner (optional)')} value={preferredPractitionerId} helperText={t('Choose a practitioner first to see only the services they offer.')} onChange={event => { setSlot(null); setServiceId(''); setPractitionerId(''); setDurationOptionId(''); setPreferredPractitionerId(event.target.value); }}><MenuItem value="">{t('Any practitioner')}</MenuItem>{publicPractitioners.map(item => <MenuItem key={item.booking_practitioner_id} value={String(item.booking_practitioner_id)}>{item.booking_name || item.public_name}</MenuItem>)}</TextField>}
        <TextField name="service_id" select label={t('Service')} value={serviceId} disabled={Boolean(bookingSlug)} onChange={event => { setSlot(null); setPractitionerId(''); setDurationOptionId(''); setServiceId(event.target.value); }}>{services.map(item => <MenuItem key={item.id} value={String(item.id)}>{item.name}</MenuItem>)}</TextField>
        {service && <><TextField name="durationOptionId" select label={t('Appointment length')} value={durationOptionId} disabled={!durations.length} helperText={t('Prices shown before taxes.')} onChange={event => selectDuration(event.target.value)}>
          {durations.length > 1 && <MenuItem value="" disabled>{t('Select a length')}</MenuItem>}
          {durations.map(option => <MenuItem key={option.id} value={String(option.id)}>{t('{{minutes}} min — {{price}}', { minutes: option.minutes, price: money(Number(option.price_cents)) })}</MenuItem>)}
        </TextField><Box><Typography color="text.secondary">{service.description}</Typography>{mode === 'mobile' && <Typography variant="body2" mt={1}>{t('A separate On-Site surcharge may apply; staff will confirm coverage and travel time.')}</Typography>}</Box></>}
      </Stack></Paper></Grid>
      <Grid size={{ xs: 12, md: 7 }}><Paper variant="outlined" sx={{ p: 3 }}><Stack spacing={2}>
        <Typography variant="h5" component="h2">{t('Choose a time')}</Typography>
        <TextField name="practitioner_id" select label={t('Practitioner')} value={practitionerId} disabled={practitionerBusy || !practitioners.length} onChange={event => { setSlot(null); setPractitionerId(event.target.value); }}>{practitioners.map(item => <MenuItem key={item.id} value={String(item.id)}>{item.display_name} · {item.credentials || item.discipline}</MenuItem>)}</TextField>
        {service && !durations.length && <Alert severity="info">{t('No appointment lengths are configured for this service.')}</Alert>}
        {durations.length > 1 && !durationOptionId && <Alert severity="info">{t('Choose an appointment length before selecting a time.')}</Alert>}
        <Typography variant="body2" color="text.secondary">{t('Select a date on the calendar to load its available times. You can also enter a date below.')}</Typography>
        <AvailabilityDateCalendar selectedDate={appointmentDate} minDate={dateInZone(selectedTimezone)} disabled={practitionerBusy || !practitionerId || !durationOptionId} onDateChange={date => { setSlot(null); setAppointmentDate(date); }} />
        <TextField name="appointmentDate" type="date" label={t('Appointment date')} value={appointmentDate} disabled={practitionerBusy || !practitionerId || !durationOptionId} InputLabelProps={{ shrink: true }} inputProps={{ min: dateInZone(selectedTimezone) }} onChange={event => { setSlot(null); setAppointmentDate(event.target.value); }} />
        {(practitionerBusy || slotBusy) && <CircularProgress size={24} aria-label={t('Loading available times')} />}
        {(practitionerError || slotError) && <Alert severity="error" action={<Button color="inherit" onClick={() => setRetry(value => value + 1)}>{t('Retry')}</Button>}>{practitionerError || slotError}</Alert>}
        {!practitionerBusy && !practitionerError && !practitioners.length && <Alert severity="info">{t('No practitioner is assigned to this service yet.')}</Alert>}
        {!slotBusy && !practitionerBusy && practitionerId && selectedDuration && appointmentDate && !slotError && !selectedDaySlots.length && <Alert severity="info">{t('No available times were found on this date.')}</Alert>}
        {!slotBusy && selectedDuration && appointmentDate && <><Typography variant="body2" color="text.secondary">{t('Available times for {{minutes}} minutes, shown in {{timezone}}.', { minutes: selectedDuration.minutes, timezone: selectedTimezone })}</Typography>
          <Grid container spacing={1}>{selectedDaySlots.map(time => <Grid size={{ xs: 12, sm: 6 }} key={`${time.duration_option_id}-${time.starts_at}`}>
            <Button fullWidth aria-pressed={slot === time} variant={slot === time ? 'contained' : 'outlined'} onClick={() => setSlot(time)}>
              {formatDateTime(time.starts_at, i18n.resolvedLanguage, { timeZone: selectedTimezone, hour: 'numeric', minute: '2-digit' })}
            </Button></Grid>)}</Grid></>}
        {service?.slug && <Button href={publicLink(`services/${encodeURIComponent(service.slug)}`)} size="small">{t('Read the full service description')}</Button>}
        {publicPractitioners.find(item => String(item.booking_practitioner_id) === practitionerId)?.slug && <Button href={publicLink(`practitioners/${encodeURIComponent(publicPractitioners.find(item => String(item.booking_practitioner_id) === practitionerId)!.slug)}`)} size="small">{t('Read the full practitioner profile')}</Button>}
        <Button href={slot ? handoff.href : undefined} disabled={!slot || !selectedDuration || slotBusy || practitionerBusy} variant="contained">{t('Book this time')}</Button>
      </Stack></Paper></Grid>
    </Grid>}
  </Container>;
}
export const GuestBooking = withFormValidation(GuestBookingForm);

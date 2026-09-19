import { useEffect, useState } from 'react';
import { Alert, Box, Button, Checkbox, Chip, Divider, FormControlLabel, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { Pencil, Plus, Save, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiErrorMessage } from '../shared/api';
import { useUnsavedChanges } from '../shared/UnsavedChanges';
import { formatCad } from '../i18n/format';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type Named = { id: number; name: string };
type Practitioner = { practitioner_id: number; display_name: string };
type Link = { practitioner_id: number; service_id: number; active: number; offers_mobile: number; offers_clinic: number; mobile_radius_km: number | null; travel_buffer_minutes: number; mobile_fee_cents: number };
type LocationLink = { service_id: number; location_id: number; active: number };

const named = (item: Named): Named => ({ ...item, id: Number(item.id) });
const practitioner = (item: Practitioner): Practitioner => ({ ...item, practitioner_id: Number(item.practitioner_id) });
const assignment = (item: Link): Link => ({
  ...item,
  practitioner_id: Number(item.practitioner_id),
  service_id: Number(item.service_id),
  active: Number(item.active),
  offers_mobile: Number(item.offers_mobile),
  offers_clinic: Number(item.offers_clinic),
  mobile_radius_km: item.mobile_radius_km === null ? null : Number(item.mobile_radius_km),
  travel_buffer_minutes: Number(item.travel_buffer_minutes),
  mobile_fee_cents: Number(item.mobile_fee_cents),
});
const locationAssignment = (item: LocationLink): LocationLink => ({
  ...item,
  service_id: Number(item.service_id),
  location_id: Number(item.location_id),
  active: Number(item.active),
});

export function ServiceAssignments({ initialServiceId, lockService = false, onDirtyChange }: { initialServiceId?: number; lockService?: boolean; onDirtyChange?: (dirty: boolean) => void }) {
  const { t, i18n } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [services, setServices] = useState<Named[]>([]);
  const [locations, setLocations] = useState<Named[]>([]);
  const [people, setPeople] = useState<Practitioner[]>([]);
  const [links, setLinks] = useState<Link[]>([]);
  const [locationLinks, setLocationLinks] = useState<LocationLink[]>([]);
  const [service, setService] = useState('');
  const [selectedLocations, setSelectedLocations] = useState<number[]>([]);
  const [selectedPeople, setSelectedPeople] = useState<Link[]>([]);
  const [busy, setBusy] = useState(true);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [editing, setEditing] = useState(false);
  const savedLocations = locationLinks.filter((item) => item.service_id === Number(service) && Boolean(Number(item.active))).map((item) => item.location_id).sort((a, b) => a - b);
  const savedPeople = links.filter((item) => item.service_id === Number(service) && Boolean(Number(item.active))).sort((a, b) => a.practitioner_id - b.practitioner_id);
  const currentPeople = [...selectedPeople].sort((a, b) => a.practitioner_id - b.practitioner_id);
  const dirty = editing && (
    JSON.stringify([...selectedLocations].sort((a, b) => a - b)) !== JSON.stringify(savedLocations) ||
    JSON.stringify(currentPeople) !== JSON.stringify(savedPeople));
  useUnsavedChanges(dirty);
  useEffect(() => { onDirtyChange?.(dirty); }, [dirty, onDirtyChange]);

  useEffect(() => {
    void (async () => {
      try {
        const token = await getAccessToken();
        const headers = { Authorization: `Bearer ${token}` };
        const responses = await Promise.all([
          fetch(`${api}/admin/services`, { headers }),
          fetch(`${api}/admin/locations`, { headers }),
          fetch(`${api}/admin/practitioners`, { headers }),
          fetch(`${api}/admin/service-assignments`, { headers }),
        ]);
        const bodies = await Promise.all(responses.map((response) => response.json()));
        if (responses.some((response) => !response.ok)) throw new Error(t('Unable to load assignments.'));
        const loadedServices = bodies[0].data.map(named);
        const loadedLocations = bodies[1].data.map(named);
        const loadedPeople = bodies[2].data.map(practitioner);
        const loadedLinks = bodies[3].data.practitioners.map(assignment);
        const loadedLocationLinks = bodies[3].data.locations.map(locationAssignment);
        setServices(loadedServices);
        setLocations(loadedLocations);
        setPeople(loadedPeople);
        setLinks(loadedLinks);
        setLocationLinks(loadedLocationLinks);
        const requestedServiceId = Number(initialServiceId ?? 0);
        const selectedServiceId = Number(loadedServices.some((item: Named) => item.id === requestedServiceId) ? requestedServiceId : loadedServices[0]?.id ?? 0);
        setService(selectedServiceId ? String(selectedServiceId) : '');
        setSelectedPeople(loadedLinks.filter((item: Link) => item.service_id === selectedServiceId && Boolean(item.active)));
        setSelectedLocations(loadedLocationLinks.filter((item: LocationLink) => item.service_id === selectedServiceId && Boolean(item.active)).map((item: LocationLink) => item.location_id));
      } catch (cause) {
        setError(cause instanceof Error ? cause.message : t('Unable to load assignments.'));
      } finally {
        setBusy(false);
      }
    })();
  }, [getAccessToken, initialServiceId, t]);

  useEffect(() => {
    const id = Number(service);
    setSelectedPeople(links.filter((item) => item.service_id === id && Boolean(Number(item.active))));
    setSelectedLocations(locationLinks.filter((item) => item.service_id === id && Boolean(Number(item.active))).map((item) => item.location_id));
  }, [service, links, locationLinks]);

  const togglePerson = (id: number, selected: boolean) => setSelectedPeople((current) => selected
    ? [...current, { practitioner_id: id, service_id: Number(service), active: 1, offers_mobile: 1, offers_clinic: 0, mobile_radius_km: null, travel_buffer_minutes: 0, mobile_fee_cents: 0 }]
    : current.filter((item) => item.practitioner_id !== id));

  const update = (id: number, key: keyof Link, value: number | null) => setSelectedPeople((current) => current.map((item) => item.practitioner_id === id ? { ...item, [key]: value } : item));
  const resetSelections = () => {
    setSelectedPeople(links.filter((item) => item.service_id === Number(service) && Boolean(Number(item.active))));
    setSelectedLocations(locationLinks.filter((item) => item.service_id === Number(service) && Boolean(Number(item.active))).map((item) => item.location_id));
  };
  const cancelEdit = () => { resetSelections(); setEditing(false); setError(''); };

  const save = async () => {
    setBusy(true);
    setError('');
    try {
      const token = await getAccessToken();
      const response = await fetch(`${api}/admin/services/${service}/assignments`, {
        method: 'PUT',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ location_ids: selectedLocations, practitioners: selectedPeople }),
      });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to save assignments.')));
      setLinks((current) => [...current.filter((link) => link.service_id !== Number(service)), ...selectedPeople]);
      setLocationLinks((current) => [...current.filter((link) => link.service_id !== Number(service)), ...selectedLocations.map((location_id) => ({ service_id: Number(service), location_id, active: 1 }))]);
      setMessage(t('Service assignments saved.'));
      setEditing(false);
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : t('Unable to save assignments.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Paper variant="outlined" sx={{ p: 3 }}>
      <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" gap={2} mb={2}>
        <Box><Typography variant="h5">{t('Current assignments')}</Typography><Typography color="text.secondary">{t('These locations and practitioners determine where and how this service can be booked.')}</Typography></Box>
        {!editing && <Button variant="contained" startIcon={(savedLocations.length || savedPeople.length) ? <Pencil size={17}/> : <Plus size={17}/>} onClick={() => { setMessage(''); setEditing(true); }}>{t((savedLocations.length || savedPeople.length) ? 'Edit assignments' : 'Add assignment')}</Button>}
      </Stack>
      <TextField select fullWidth label={t('Service')} value={service} disabled={lockService} onChange={(event) => setService(event.target.value)}>
        {services.map((item) => <MenuItem key={item.id} value={String(item.id)}>{item.name}</MenuItem>)}
      </TextField>
      {!editing ? <AssignmentSummary locations={locations} people={people} locationIds={savedLocations} links={savedPeople} money={(cents) => formatCad(cents, i18n.resolvedLanguage)} /> : <>
        <Alert severity="info" sx={{ mt: 2 }}>{t('Choose a base location and practitioner. For a mobile-only practice, enable Mobile visits and disable Clinic visits. The base location supplies working hours and timezone; no room is needed.')}</Alert>
        <Typography fontWeight={700} mt={3}>{t('Base locations / service areas')}</Typography>
        <Stack>{locations.map((item) => <FormControlLabel key={item.id} control={<Checkbox checked={selectedLocations.includes(item.id)} onChange={(event) => setSelectedLocations((current) => event.target.checked ? [...current, item.id] : current.filter((id) => id !== item.id))} />} label={item.name} />)}</Stack>
        <Typography fontWeight={700} mt={2}>{t('Practitioners')}</Typography>
        <Stack spacing={1}>{people.map((person) => {
          const link = selectedPeople.find((item) => item.practitioner_id === person.practitioner_id);
          return <Paper variant="outlined" key={person.practitioner_id} sx={{ p: 1.5 }}><FormControlLabel control={<Checkbox checked={Boolean(link)} onChange={(event) => togglePerson(person.practitioner_id, event.target.checked)} />} label={person.display_name}/>{link && <Stack direction={{ xs: 'column', md: 'row' }} spacing={1} ml={4}><FormControlLabel control={<Checkbox checked={Boolean(Number(link.offers_clinic))} onChange={(event) => update(person.practitioner_id, 'offers_clinic', event.target.checked ? 1 : 0)} />} label={t('Clinic visits')}/><FormControlLabel control={<Checkbox checked={Boolean(Number(link.offers_mobile))} onChange={(event) => update(person.practitioner_id, 'offers_mobile', event.target.checked ? 1 : 0)} />} label={t('Mobile visits')}/>{Boolean(Number(link.offers_mobile)) && <><TextField required size="small" type="number" label={t('Driving coverage radius (km)')} value={link.mobile_radius_km ?? ''} inputProps={{ min: 1, max: 500, step: 1 }} onChange={(event) => update(person.practitioner_id, 'mobile_radius_km', event.target.value === '' ? null : Number(event.target.value))}/><TextField size="small" type="number" label={t('Travel minutes each way')} value={link.travel_buffer_minutes} inputProps={{ step: 15 }} onChange={(event) => update(person.practitioner_id, 'travel_buffer_minutes', Number(event.target.value))}/><TextField size="small" type="number" label={t('Mobile fee CAD')} value={link.mobile_fee_cents / 100} onChange={(event) => update(person.practitioner_id, 'mobile_fee_cents', Math.round(Number(event.target.value) * 100))}/></>}</Stack>}</Paper>;
        })}</Stack>
      </>}
      {error && <Alert severity="error" sx={{ mt: 2 }}>{error}</Alert>}
      {message && <Alert severity="success" sx={{ mt: 2 }}>{message}</Alert>}
      {editing && <Stack direction="row" gap={1} justifyContent="flex-end" mt={2}><Button startIcon={<X size={17}/>} disabled={busy} onClick={cancelEdit}>{t('Cancel')}</Button><Button variant="contained" startIcon={<Save size={17}/>} disabled={busy || !service} onClick={save}>{t('Save assignments')}</Button></Stack>}
    </Paper>
  );
}

function AssignmentSummary({ locations, people, locationIds, links, money }: { locations: Named[]; people: Practitioner[]; locationIds: number[]; links: Link[]; money: (cents: number) => string }) {
  const { t } = useTranslation();
  if (!locationIds.length && !links.length) return <Alert severity="info" sx={{ mt: 2 }}>{t('This service has no current assignments and cannot be booked.')}</Alert>;
  return <Stack spacing={2.5} mt={3}>
    <Box><Typography fontWeight={700}>{t('Base locations / service areas')}</Typography><Stack direction="row" flexWrap="wrap" gap={1} mt={1}>{locationIds.map(id => <Chip key={id} label={locations.find(location => location.id === id)?.name ?? t('Unknown location')}/>)}</Stack>{!locationIds.length && <Typography color="text.secondary" mt={1}>{t('No base locations assigned.')}</Typography>}</Box>
    <Divider/>
    <Box><Typography fontWeight={700} mb={1}>{t('Practitioners')}</Typography><Stack spacing={1}>{links.map(link => {
      const name = people.find(person => person.practitioner_id === link.practitioner_id)?.display_name ?? t('Unknown practitioner');
      const modes = [Boolean(Number(link.offers_clinic)) ? t('Clinic visits') : '', Boolean(Number(link.offers_mobile)) ? t('Mobile visits') : ''].filter(Boolean);
      return <Paper key={link.practitioner_id} variant="outlined" sx={{ p: 2 }}><Typography fontWeight={700}>{name}</Typography><Stack direction="row" flexWrap="wrap" gap={1} my={1}>{modes.map(mode => <Chip key={mode} size="small" color="primary" variant="outlined" label={mode}/>)}</Stack>{Boolean(Number(link.offers_mobile)) && <Typography variant="body2" color="text.secondary">{t('{{radius}} km radius · {{minutes}} min travel each way · {{fee}} mobile fee', { radius: link.mobile_radius_km ?? t('Not set'), minutes: link.travel_buffer_minutes, fee: money(Number(link.mobile_fee_cents)) })}</Typography>}</Paper>;
    })}{!links.length && <Typography color="text.secondary">{t('No practitioners assigned.')}</Typography>}</Stack></Box>
  </Stack>;
}

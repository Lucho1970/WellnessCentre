import { useEffect, useState } from 'react';
import { Alert, Button, Checkbox, FormControlLabel, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { Save } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type Named = { id: number; name: string };
type Practitioner = { practitioner_id: number; display_name: string };
type Link = { practitioner_id: number; service_id: number; active: number; offers_mobile: number; offers_clinic: number; mobile_radius_km: number | null; travel_buffer_minutes: number; mobile_fee_cents: number };

export function ServiceAssignments({ catalogueVersion = 0 }: { catalogueVersion?: number }) {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [services, setServices] = useState<Named[]>([]);
  const [locations, setLocations] = useState<Named[]>([]);
  const [people, setPeople] = useState<Practitioner[]>([]);
  const [links, setLinks] = useState<Link[]>([]);
  const [locationLinks, setLocationLinks] = useState<{ service_id: number; location_id: number; active: number }[]>([]);
  const [service, setService] = useState('');
  const [selectedLocations, setSelectedLocations] = useState<number[]>([]);
  const [selectedPeople, setSelectedPeople] = useState<Link[]>([]);
  const [busy, setBusy] = useState(true);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

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
        setServices(bodies[0].data);
        setLocations(bodies[1].data);
        setPeople(bodies[2].data);
        setLinks(bodies[3].data.practitioners);
        setLocationLinks(bodies[3].data.locations);
        setService(String(bodies[0].data[0]?.id ?? ''));
      } catch (cause) {
        setError(cause instanceof Error ? cause.message : t('Unable to load assignments.'));
      } finally {
        setBusy(false);
      }
    })();
  }, [getAccessToken, t]);

  useEffect(() => {
    if (!catalogueVersion) return;
    let cancelled = false;
    void (async () => {
      try {
        const token = await getAccessToken();
        const response = await fetch(`${api}/admin/services`, { headers: { Authorization: `Bearer ${token}` } });
        const body = await response.json();
        if (!response.ok) throw new Error(t('Unable to refresh services. Please reload the page.'));
        if (!cancelled) {
          setServices(body.data);
          setService((current) => current || String(body.data[0]?.id ?? ''));
        }
      } catch (cause) {
        if (!cancelled) setError(cause instanceof Error ? cause.message : t('Unable to refresh services.'));
      }
    })();
    return () => { cancelled = true; };
  }, [catalogueVersion, getAccessToken, t]);

  useEffect(() => {
    const id = Number(service);
    setSelectedPeople(links.filter((item) => item.service_id === id && Boolean(Number(item.active))));
    setSelectedLocations(locationLinks.filter((item) => item.service_id === id && Boolean(Number(item.active))).map((item) => item.location_id));
  }, [service, links, locationLinks]);

  const togglePerson = (id: number, selected: boolean) => setSelectedPeople((current) => selected
    ? [...current, { practitioner_id: id, service_id: Number(service), active: 1, offers_mobile: 1, offers_clinic: 0, mobile_radius_km: null, travel_buffer_minutes: 0, mobile_fee_cents: 0 }]
    : current.filter((item) => item.practitioner_id !== id));

  const update = (id: number, key: keyof Link, value: number | null) => setSelectedPeople((current) => current.map((item) => item.practitioner_id === id ? { ...item, [key]: value } : item));

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
      if (!response.ok) throw new Error(body?.error?.message ?? t('Unable to save assignments.'));
      setLinks((current) => [...current.filter((link) => link.service_id !== Number(service)), ...selectedPeople]);
      setLocationLinks((current) => [...current.filter((link) => link.service_id !== Number(service)), ...selectedLocations.map((location_id) => ({ service_id: Number(service), location_id, active: 1 }))]);
      setMessage(t('Service assignments saved.'));
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : t('Unable to save assignments.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Paper variant="outlined" sx={{ p: 3 }}>
      <Typography variant="h5">{t('Service assignments')}</Typography>
      <Typography color="text.secondary" mb={2}>{t('Choose a base location and practitioner. For a mobile-only practice, enable Mobile visits and disable Clinic visits. The base location supplies working hours and timezone; no room is needed.')}</Typography>
      <TextField select fullWidth label={t('Service')} value={service} onChange={(event) => setService(event.target.value)}>
        {services.map((item) => <MenuItem key={item.id} value={String(item.id)}>{item.name}</MenuItem>)}
      </TextField>
      <Typography fontWeight={700} mt={3}>{t('Base locations / service areas')}</Typography>
      <Stack>
        {locations.map((item) => <FormControlLabel key={item.id} control={<Checkbox checked={selectedLocations.includes(item.id)} onChange={(event) => setSelectedLocations((current) => event.target.checked ? [...current, item.id] : current.filter((id) => id !== item.id))} />} label={item.name} />)}
      </Stack>
      <Typography fontWeight={700} mt={2}>{t('Practitioners')}</Typography>
      <Stack spacing={1}>
        {people.map((person) => {
          const link = selectedPeople.find((item) => item.practitioner_id === person.practitioner_id);
          return (
            <Paper variant="outlined" key={person.practitioner_id} sx={{ p: 1.5 }}>
              <FormControlLabel control={<Checkbox checked={Boolean(link)} onChange={(event) => togglePerson(person.practitioner_id, event.target.checked)} />} label={person.display_name} />
              {link && (
                <Stack direction={{ xs: 'column', md: 'row' }} spacing={1} ml={4}>
                  <FormControlLabel control={<Checkbox checked={Boolean(Number(link.offers_clinic))} onChange={(event) => update(person.practitioner_id, 'offers_clinic', event.target.checked ? 1 : 0)} />} label={t('Clinic visits')} />
                  <FormControlLabel control={<Checkbox checked={Boolean(Number(link.offers_mobile))} onChange={(event) => update(person.practitioner_id, 'offers_mobile', event.target.checked ? 1 : 0)} />} label={t('Mobile visits')} />
                  {Boolean(Number(link.offers_mobile)) && <>
                    <TextField size="small" type="number" label={t('Coverage radius km (staff verified)')} value={link.mobile_radius_km ?? ''} onChange={(event) => update(person.practitioner_id, 'mobile_radius_km', event.target.value === '' ? null : Number(event.target.value))} />
                    <TextField size="small" type="number" label={t('Travel minutes each way')} value={link.travel_buffer_minutes} inputProps={{ step: 15 }} onChange={(event) => update(person.practitioner_id, 'travel_buffer_minutes', Number(event.target.value))} />
                    <TextField size="small" type="number" label={t('Mobile fee CAD')} value={link.mobile_fee_cents / 100} onChange={(event) => update(person.practitioner_id, 'mobile_fee_cents', Math.round(Number(event.target.value) * 100))} />
                  </>}
                </Stack>
              )}
            </Paper>
          );
        })}
      </Stack>
      {error && <Alert severity="error" sx={{ mt: 2 }}>{error}</Alert>}
      {message && <Alert severity="success" sx={{ mt: 2 }}>{message}</Alert>}
      <Button variant="contained" startIcon={<Save size={17} />} disabled={busy || !service} onClick={save} sx={{ mt: 2 }}>{t('Save assignments')}</Button>
    </Paper>
  );
}

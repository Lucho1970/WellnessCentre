import { useEffect, useState, type FormEvent } from 'react';
import { Alert, Box, Button, Checkbox, FormControlLabel, Paper, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { AddressEntry, type AddressValue } from '../shared/AddressEntry';
import { apiErrorMessage } from '../shared/api';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
const empty = (): AddressValue => ({ address_line1: '', address_line2: '', city: '', province: '', postal_code: '', country: 'Canada' });
type Settings = { home_address: AddressValue | null; work_address: AddressValue | null; work_same_as_home: boolean; version: number };

export function PractitionerWorkLocation() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [home, setHome] = useState<AddressValue>(empty);
  const [work, setWork] = useState<AddressValue>(empty);
  const [same, setSame] = useState(false);
  const [version, setVersion] = useState(0);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const apply = (settings: Settings) => {
    setHome(settings.home_address ?? empty()); setWork(settings.work_address ?? empty());
    setSame(settings.work_same_as_home); setVersion(settings.version);
  };
  const request = async (body?: Settings): Promise<Settings> => {
    const token = await getAccessToken();
    const response = await fetch(`${api}/profile/work-location`, {
      method: body ? 'PUT' : 'GET', headers: { Authorization: `Bearer ${token}`, ...(body ? { 'Content-Type': 'application/json' } : {}) },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const result = await response.json();
    if (!response.ok) throw new Error(apiErrorMessage(result, response.status, t('Unable to load or save your work location.')));
    return result.data;
  };
  const load = async () => {
    setLoading(true); setError(''); setMessage('');
    try { apply(await request()); } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to load or save your work location.')); }
    finally { setLoading(false); }
  };
  useEffect(() => { void load(); }, []);
  const save = async (event: FormEvent) => {
    event.preventDefault(); setBusy(true); setError(''); setMessage('');
    const homeEntered = [home.address_line1, home.address_line2, home.city, home.province, home.postal_code].some(value => value.trim());
    try {
      apply(await request({ home_address: same || homeEntered ? home : null, work_address: same ? null : work, work_same_as_home: same, version }));
      setMessage(t('Work location saved. Previous coverage checks must be renewed.'));
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to load or save your work location.')); }
    finally { setBusy(false); }
  };
  return <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 } }}>
    <Typography variant="h5" mb={2}>{t('Private work location')}</Typography>
    <Stack component="form" onSubmit={event => void save(event)} spacing={3}>
      <Alert severity="info">{t('Choose where your workday normally starts. These addresses are private and are not shown to clients. Google processes the starting address to calculate On-Site coverage.')}</Alert>
      {loading ? <Typography>{t('Loading…')}</Typography> : <>
        <Box component="section" aria-label={t('Home address')}>
          <Typography variant="h6" mb={2}>{t('Home address')}</Typography>
          <Typography color="text.secondary" mb={2}>{t('Optional unless your work location is the same as home.')}</Typography>
          <AddressEntry value={home} onChange={value => { setHome(value); setMessage(''); }} required={same} disabled={busy} />
        </Box>
        <Box component="section" aria-label={t('Work location')}>
          <Typography variant="h6">{t('Work location')}</Typography>
          <FormControlLabel control={<Checkbox checked={same} disabled={busy} onChange={event => { setSame(event.target.checked); setMessage(''); }} />} label={t('Same as home address')} />
          {same ? <Alert severity="info">{t('Your saved home address will be used. Updating it also updates your work location.')}</Alert>
            : <AddressEntry value={work} onChange={value => { setWork(value); setMessage(''); }} required disabled={busy} />}
        </Box>
      </>}
      {error && <Alert severity="error">{error}</Alert>}
      {message && <Alert severity="success">{message}</Alert>}
      <Stack direction="row" spacing={2}><Button type="submit" variant="contained" disabled={loading || busy}>{t(busy ? 'Saving…' : 'Save work location')}</Button><Button disabled={loading || busy} onClick={() => void load()}>{t('Reload saved location')}</Button></Stack>
    </Stack>
  </Paper>;
}

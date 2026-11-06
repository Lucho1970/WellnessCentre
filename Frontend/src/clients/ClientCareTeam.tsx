import { useCallback, useEffect, useState } from 'react';
import { Alert, Button, Dialog, DialogActions, DialogContent, DialogTitle, Paper, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { sessionFetch } from '../auth/serverSession';
import { ApiError, apiBaseUrl, normalizeNumericIds } from '../shared/api';

type Practitioner = { practitioner_id: number; display_name: string; assigned: number | string | boolean };
const isAssigned = (value: Practitioner | null) => value !== null && (value.assigned === true || Number(value.assigned) === 1);
export function ClientCareTeam({ clientId }: { clientId: number }) {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [items, setItems] = useState<Practitioner[]>([]);
  const [pending, setPending] = useState<Practitioner | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const load = useCallback(async () => {
    setBusy(true); setError('');
    try {
      const token = await getAccessToken();
      const response = await sessionFetch(`${apiBaseUrl}/clients/${clientId}/care-team`, { headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json();
      if (!response.ok) throw new ApiError(body, response.status, t('Unable to load care team.'));
      const result = normalizeNumericIds<{items: Practitioner[]}>(body.data);
      if (!Array.isArray(result?.items)) throw new Error(t('Unable to load care team.'));
      setItems(result.items);
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to load care team.')); }
    finally { setBusy(false); }
  }, [clientId, getAccessToken, t]);
  useEffect(() => { void load(); }, [load]);
  const save = async () => {
    if (!pending) return;
    setBusy(true); setError(''); setNotice('');
    try {
      const token = await getAccessToken();
      const assigned = !isAssigned(pending);
      const response = await sessionFetch(`${apiBaseUrl}/clients/${clientId}/care-team/${pending.practitioner_id}`, {
        method: 'PUT', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ assigned }),
      });
      const body = await response.json();
      if (!response.ok) throw new ApiError(body, response.status, t('Unable to change care team.'));
      setPending(null); setNotice(t(assigned ? 'Care assignment started.' : 'Care assignment ended.'));
      await load();
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to change care team.')); }
    finally { setBusy(false); }
  };
  return <Stack spacing={1.5}>
    <Typography variant="h6">{t('Current care team')}</Typography>
    <Typography color="text.secondary">{t('Only assigned practitioners may enter clinic notes. A note shared with the care team is visible to other currently assigned practitioners, subject to patient restrictions.')}</Typography>
    <Button disabled={busy} onClick={() => void load()} sx={{ alignSelf: 'flex-start' }}>{t('Refresh care team')}</Button>
    {error && <Alert severity="error">{error}</Alert>}{notice && <Alert severity="success">{notice}</Alert>}
    {items.map(item => <Paper key={item.practitioner_id} variant="outlined" sx={{ p: 1.5 }}><Stack direction="row" alignItems="center" justifyContent="space-between" gap={1}>
      <Typography>{item.display_name} · {t(isAssigned(item) ? 'Assigned' : 'Not assigned')}</Typography>
      <Button disabled={busy} onClick={() => setPending(item)}>{t(isAssigned(item) ? 'End care assignment' : 'Assign care')}</Button>
    </Stack></Paper>)}
    {!busy && items.length===0 && <Typography>{t('No active practitioners available.')}</Typography>}
    <Dialog open={pending!==null} onClose={() => { if(!busy)setPending(null); }} aria-labelledby="care-assignment-title">
      <DialogTitle id="care-assignment-title">{t(isAssigned(pending) ? 'End care assignment' : 'Assign care')}</DialogTitle>
      <DialogContent><Typography>{pending?.display_name}</Typography><Typography color="text.secondary">{t(isAssigned(pending) ? 'This will end routine note access for this client. Existing notes remain in the clinic record.' : 'Confirm this practitioner is currently involved in this client’s care before granting access.')}</Typography>{error && <Alert severity="error">{error}</Alert>}</DialogContent>
      <DialogActions><Button disabled={busy} onClick={() => setPending(null)}>{t('Cancel')}</Button><Button disabled={busy} variant="contained" onClick={() => void save()}>{t('Confirm care change')}</Button></DialogActions>
    </Dialog>
  </Stack>;
}

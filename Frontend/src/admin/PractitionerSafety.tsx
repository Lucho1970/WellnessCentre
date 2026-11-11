import { useCallback, useEffect, useState } from 'react';
import { Alert, Button, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, MenuItem, Paper, Stack, Switch, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { sessionFetch } from '../auth/serverSession';
import { ApiError, normalizeNumericIds } from '../shared/api';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type Practitioner = { practitioner_id: number; user_id: number; display_name: string; status: string; access_held: number | string | boolean; reason_code: string | null };
const isHeld = (value: Practitioner | null) => value !== null && (value.access_held === true || Number(value.access_held) === 1);
const reasons = ['security_concern', 'departure_review', 'other'] as const;

export function PractitionerSafety({ canRestore }: { canRestore: boolean }) {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [items, setItems] = useState<Practitioner[]>([]);
  const [pending, setPending] = useState<Practitioner | null>(null);
  const [reason, setReason] = useState<(typeof reasons)[number]>('security_concern');
  const [resolution, setResolution] = useState<'' | 'active' | 'inactive'>('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const load = useCallback(async () => {
    setBusy(true); setError('');
    try {
      const token = await getAccessToken();
      const response = await sessionFetch(`${api}/admin/practitioner-emergency-access`, { headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json();
      if (!response.ok) throw new ApiError(body, response.status, t('Unable to load practitioners.'));
      setItems(normalizeNumericIds<Practitioner[]>(body.data));
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to load practitioners.')); }
    finally { setBusy(false); }
  }, [getAccessToken, t]);
  useEffect(() => { void load(); }, [load]);
  const save = async () => {
    if (!pending) return;
    if (isHeld(pending) && resolution === '') return;
    setBusy(true); setError(''); setNotice('');
    try {
      const token = await getAccessToken();
      const hold = !isHeld(pending);
      const response = await sessionFetch(`${api}/admin/practitioners/${pending.practitioner_id}/emergency-access`, {
        method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify(hold ? { hold, reason_code: reason } : { hold, resolution }),
      });
      const body = await response.json();
      if (!response.ok) throw new ApiError(body, response.status, t('Unable to change practitioner access.'));
      setPending(null); setNotice(t(hold ? 'Practitioner access stopped.' : resolution === 'inactive' ? 'Hold resolved; account remains inactive.' : 'Practitioner access restored.'));
      await load();
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to change practitioner access.')); }
    finally { setBusy(false); }
  };
  return <Stack spacing={2}>
    <Alert severity="info">{t('An access hold immediately blocks sign-in and protected requests. It does not cancel bookings or complete a practitioner departure. Clinic administrators and authorized office users can stop access; only a Super Admin can restore it.')}</Alert>
    {error && <Alert severity="error" action={<Button color="inherit" onClick={() => void load()}>{t('Retry')}</Button>}>{error}</Alert>}
    {notice && <Alert severity="success" onClose={() => setNotice('')}>{notice}</Alert>}
    <Button onClick={() => void load()} disabled={busy} sx={{ alignSelf: 'flex-start' }}>{t('Refresh access status')}</Button>
    {items.map(item => {
      const held = isHeld(item);
      const available = held ? canRestore && item.status === 'locked' : item.status === 'active';
      return <Paper key={item.practitioner_id} variant="outlined" sx={{ p: 2 }}><Stack direction={{ xs: 'column', sm: 'row' }} gap={1} alignItems={{ sm: 'center' }} justifyContent="space-between">
        <Stack><Typography fontWeight={700}>{item.display_name}</Typography><Typography variant="body2" color="text.secondary">{t(held ? 'Emergency hold active' : item.status === 'active' ? 'Access active' : 'Account inactive or locked')}</Typography></Stack>
        <FormControlLabel label={`${t('Block practitioner access')}: ${item.display_name}`} control={<Switch checked={held} disabled={busy || !available} onChange={() => { setReason('security_concern'); setResolution(''); setPending(item); }} />} />
      </Stack></Paper>;
    })}
    {!busy && items.length === 0 && <Typography>{t('No practitioner accounts found.')}</Typography>}
    <Dialog open={pending !== null} onClose={() => { if (!busy) setPending(null); }} fullWidth maxWidth="sm" aria-labelledby="access-hold-title">
      <DialogTitle id="access-hold-title">{t(isHeld(pending) ? 'Resolve practitioner access hold' : 'Stop practitioner access')}</DialogTitle>
      <DialogContent><Stack spacing={2} sx={{ pt: 1 }}>
        <Typography>{pending?.display_name}</Typography>
        <Typography>{t(isHeld(pending) ? 'Choose whether to restore access or leave the account inactive. Keeping it inactive does not complete the departure process for bookings, records and patient follow-up.' : 'This blocks the account immediately. Use the separate departure process for bookings, records and patient follow-up.')}</Typography>
        {isHeld(pending) && <TextField select label={t('Resolve hold to')} value={resolution} onChange={event => setResolution(event.target.value as '' | 'active' | 'inactive')}>
          <MenuItem value="">{t('Choose an outcome')}</MenuItem>
          <MenuItem value="active">{t('Restore active access')}</MenuItem>
          <MenuItem value="inactive">{t('Keep account inactive')}</MenuItem>
        </TextField>}
        {!isHeld(pending) && <TextField select label={t('Reason category')} value={reason} onChange={event => setReason(event.target.value as (typeof reasons)[number])} helperText={t('Do not enter patient details here.')}>
          {reasons.map(value => <MenuItem key={value} value={value}>{t(value)}</MenuItem>)}
        </TextField>}
        {error && <Alert severity="error">{error}</Alert>}
      </Stack></DialogContent>
      <DialogActions><Button onClick={() => setPending(null)} disabled={busy}>{t('Cancel')}</Button><Button variant="contained" color={isHeld(pending) ? 'primary' : 'error'} onClick={() => void save()} disabled={busy || (isHeld(pending) && resolution === '')}>{t(isHeld(pending) ? 'Resolve hold' : 'Stop access now')}</Button></DialogActions>
    </Dialog>
  </Stack>;
}

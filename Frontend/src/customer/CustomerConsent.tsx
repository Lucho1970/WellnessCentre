import { useEffect, useState } from 'react';
import { Alert, Button, CircularProgress, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { customerFetch } from './session';

type Directive = { scope: string; target_staff_id: number; restricted: boolean; version: number };
type Consent = { items: Directive[]; staff: { id: number; display_name: string }[] };

export function CustomerConsent({ onLockedChange }: { onLockedChange: (locked: boolean) => void }) {
  const { t } = useTranslation();
  const [data, setData] = useState<Consent | null>(null), [error, setError] = useState('');
  const [busy, setBusy] = useState(false), [target, setTarget] = useState(0), [reload, setReload] = useState(0);
  useEffect(() => {
    let active = true; setData(null); setError('');
    void customerFetch('/consent').then(result => { if (active) setData(result); })
      .catch(cause => { if (active) setError(cause.message); });
    return () => { active = false; };
  }, [reload]);
  const current = (scope: string, staff: number) => data?.items.find(item => item.scope === scope && item.target_staff_id === staff);
  const save = async (scope: string, staff: number) => {
    const previous = current(scope, staff); setBusy(true); onLockedChange(true); setError('');
    try {
      setData(await customerFetch('/consent', { method: 'PUT', body: JSON.stringify({ scope, target_staff_id: staff, restricted: !previous?.restricted, version: previous?.version ?? 0 }) }));
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to save.')); setData(null); }
    finally { setBusy(false); onLockedChange(false); }
  };
  const access = current('staff_access', target), communications = current('patient_communications', 0);
  return <Stack spacing={2}>
    <Typography variant="h6">{t('Privacy instructions')}</Typography>
    <Alert severity="info">{t('You can restrict staff access and stop appointment messages. Restrictions may interrupt booking and care. Your records are retained and you can still view your own information. Contact the clinic to discuss a narrower restriction or an urgent care need.')}</Alert>
    {error && <Alert severity="error">{error}<Button disabled={busy} onClick={() => setReload(value => value + 1)}>{t('Reload')}</Button></Alert>}
    {!data && !error && <CircularProgress aria-label={t('Loading privacy instructions')} />}
    {data && <>
      <TextField select label={t('Staff access restriction')} value={target} disabled={busy} onChange={event => setTarget(Number(event.target.value))}>
        <MenuItem value={0}>{t('All clinic staff')}</MenuItem>
        {data.staff.map(staff => <MenuItem key={staff.id} value={staff.id}>{staff.display_name}</MenuItem>)}
      </TextField>
      <Typography>{access?.restricted ? t('Access restricted') : t('No restriction for this selection')}</Typography>
      {target !== 0 && current('staff_access', 0)?.restricted && <Alert severity="warning">{t('The restriction for all clinic staff also applies. Restoring one staff member does not clear it.')}</Alert>}
      <Button disabled={busy} variant="outlined" onClick={() => void save('staff_access', target)}>{t(access?.restricted ? 'Restore staff access permission' : 'Withdraw staff access permission')}</Button>
      <Typography>{communications?.restricted ? t('Appointment messages stopped') : t('Appointment messages permitted')}</Typography>
      <Button disabled={busy} variant="outlined" onClick={() => void save('patient_communications', 0)}>{t(communications?.restricted ? 'Permit future appointment messages' : 'Stop appointment messages')}</Button>
      <Typography color="text.secondary">{t('Restoring permission does not grant a staff role or resend canceled messages. Messages already sent cannot be recalled.')}</Typography>
    </>}
  </Stack>;
}

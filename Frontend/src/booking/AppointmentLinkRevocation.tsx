import { withFormValidation, useFormValidation } from '../shared/FormValidation';
import { useState } from 'react';
import { Alert, Button, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';

function AppointmentLinkRevocationForm({ appointmentId, request }: { appointmentId: number; request: (path: string, init?: RequestInit) => Promise<unknown> }) {
  const formValidation = useFormValidation();
  const { t } = useTranslation();
  const [confirming, setConfirming] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState(''), [done, setDone] = useState(false);
  const revoke = async () => {
    setBusy(true); (formValidation.clear(), setError(''));
    try { await request(`/appointments/${appointmentId}/action-links/revoke`, { method: 'POST', body: '{}' }); setDone(true); setConfirming(false); }
    catch (cause) { formValidation.capture(cause); setError(cause instanceof Error ? cause.message : t('Unable to revoke appointment links.')); }
    finally { setBusy(false); }
  };
  return <Stack spacing={1}>
    <Typography variant="h6">{t('Appointment email links')}</Typography>
    <Typography variant="body2">{t('Revoke existing appointment email links if a message was shared by mistake. Clients can still manage their own appointments after sign-in. Future messages may include new links.')}</Typography>
    {error && <Alert severity="error">{error}</Alert>}{done && <Alert severity="success">{t('Existing appointment email links were revoked.')}</Alert>}
    {confirming ? <Stack direction="row" gap={1}><Button disabled={busy} onClick={() => setConfirming(false)}>{t('Back')}</Button><Button color="warning" disabled={busy} onClick={() => void revoke()}>{t('Confirm link revocation')}</Button></Stack> : <Button sx={{ alignSelf: 'flex-start' }} onClick={() => { setDone(false); setConfirming(true); }}>{t('Revoke existing email links')}</Button>}
  </Stack>;
}
export const AppointmentLinkRevocation = withFormValidation(AppointmentLinkRevocationForm);

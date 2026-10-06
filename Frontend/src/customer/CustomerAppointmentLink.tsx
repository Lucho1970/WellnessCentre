import { useEffect, useState } from 'react';
import { Alert, Button, CircularProgress, Stack } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { customerFetch } from './session';
import { clearAppointmentLink, pendingAppointmentLink } from './appointmentLink';
import { CustomerAppointmentManager, type CustomerAppointment } from './CustomerAppointmentManager';

export function CustomerAppointmentLink({ close, complete, onLockedChange }: { onLockedChange?: (locked: boolean) => void; close: () => void; complete: (message: string) => void }) {
  const { t } = useTranslation();
  const [token] = useState(pendingAppointmentLink);
  const [appointment, setAppointment] = useState<CustomerAppointment | null>(null);
  const [busy, setBusy] = useState(true), [error, setError] = useState(''), [attempt, setAttempt] = useState(0);
  useEffect(() => {
    const controller = new AbortController(); setBusy(true); setError('');
    if (!token || token === 'invalid') { setBusy(false); setError(t('This appointment link is invalid. Open My appointments or contact the clinic.')); return; }
    void customerFetch('/appointment-links/resolve', { method: 'POST', body: JSON.stringify({ token }), signal: controller.signal }).then(data => {
      if (controller.signal.aborted) return;
      const item = data?.appointment;
      if (!item || !Number.isSafeInteger(Number(item.id)) || Number(item.id) < 1 || !Number.isSafeInteger(Number(item.version)) || Number(item.version) < 1 || !['requested','confirmed','rescheduled'].includes(item.status)
        || !['service','practitioner','location','timezone','starts_at','ends_at'].every(key => typeof item[key] === 'string')
        || !['clinic','mobile'].includes(item.delivery_mode) || !Number.isSafeInteger(Number(item.duration_option_id)) || Number(item.duration_option_id) < 1
        || Number.isNaN(Date.parse(`${item.starts_at.replace(' ', 'T')}Z`)) || Number.isNaN(Date.parse(`${item.ends_at.replace(' ', 'T')}Z`))) throw new Error(t('The appointment link response is invalid.'));
      try { new Intl.DateTimeFormat('en', { timeZone: item.timezone }); }
      catch { throw new Error(t('The appointment link response is invalid.')); }
      setAppointment(item); clearAppointmentLink();
    }).catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : t('Unable to open the appointment link.')); })
      .finally(() => { if (!controller.signal.aborted) setBusy(false); });
    return () => controller.abort();
  }, [token, attempt, t]);
  const leave = () => { clearAppointmentLink(); close(); };
  if (appointment) return <CustomerAppointmentManager onLockedChange={onLockedChange} appointment={appointment} close={leave} complete={message => { clearAppointmentLink(); complete(message); }}/>;
  return <Stack spacing={2}>
    <Alert severity="info">{t('Opening this link does not change your appointment. Review the booking before confirming cancellation or rescheduling.')}</Alert>
    {busy && <CircularProgress aria-label={t('Opening appointment link')}/>}
    {error && <Alert severity="error">{error}</Alert>}
    <Stack direction="row" gap={1} flexWrap="wrap">{error && token && token !== 'invalid' && <Button onClick={() => setAttempt(value => value + 1)}>{t('Retry')}</Button>}<Button onClick={leave}>{t('Back to My appointments')}</Button></Stack>
  </Stack>;
}

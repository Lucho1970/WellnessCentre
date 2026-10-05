import { useState } from 'react';
import { Alert, Button, Divider, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';

type AppointmentOutcome = {
  id: number;
  status: string;
  version: number;
  starts_at: string;
  ends_at: string;
};
type Props = {
  appointment: AppointmentOutcome;
  request: (path: string, init?: RequestInit) => Promise<unknown>;
  onChanged: (notice: string) => void;
};
const utcMillis = (value: string) => new Date(`${value.replace(' ', 'T')}Z`).getTime();

export function PractitionerVisitOutcome({ appointment, request, onChanged }: Props) {
  const { t } = useTranslation();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const active = appointment.status === 'confirmed' || appointment.status === 'rescheduled';
  const started = utcMillis(appointment.starts_at) <= Date.now();
  const correctionAvailable = ['completed', 'no_show'].includes(appointment.status) && Date.now() <= utcMillis(appointment.ends_at) + 2 * 86400000;
  if ((!active || !started) && !correctionAvailable) return null;

  const update = async (outcome: 'completed' | 'no_show' | 'reopen') => {
    const question = outcome === 'completed' ? 'Mark this appointment completed?' : outcome === 'no_show' ? 'Mark this appointment as a no-show?' : 'Undo this appointment outcome?';
    if (!window.confirm(t(question))) return;
    setBusy(true); setError('');
    try {
      await request(`/practitioner/today/${appointment.id}/outcome`, { method: 'POST', body: JSON.stringify({ outcome, version: appointment.version }) });
      onChanged(t(outcome === 'reopen' ? 'Appointment #{{id}} outcome restored.' : outcome === 'no_show' ? 'Appointment #{{id}} marked no-show.' : 'Appointment #{{id}} marked completed.', { id: appointment.id }));
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : t('Unable to update the appointment.'));
    } finally { setBusy(false); }
  };
  return <Stack spacing={1.5}>
    <Divider />
    <Typography variant="h6">{t('Visit outcome')}</Typography>
    {active && <Stack direction="row" gap={1} flexWrap="wrap">
      <Button variant="outlined" disabled={busy} onClick={() => void update('completed')}>{t('Mark completed')}</Button>
      <Button color="warning" disabled={busy} onClick={() => void update('no_show')}>{t('Mark no-show')}</Button>
    </Stack>}
    {correctionAvailable && <Button size="small" sx={{ alignSelf: 'flex-start' }} disabled={busy} onClick={() => void update('reopen')}>{t('Undo outcome')}</Button>}
    {error && <Alert severity="error">{error}</Alert>}
  </Stack>;
}

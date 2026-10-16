import { useEffect, useState } from 'react';
import { Alert, Button, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { formatCad } from '../i18n/format';

type Context = { version: number; fee_cents: number; assessed_fee_cents: number; waived: boolean; reason: string | null; can_waive: boolean };
export function OnsiteFeeWaiver({ appointmentId, request, changed, onBusyChange }: {
  appointmentId: number; request: (path: string, init?: RequestInit) => Promise<any>;
  changed: (version: number) => void; onBusyChange: (busy: boolean) => void;
}) {
  const { t, i18n } = useTranslation();
  const [data, setData] = useState<Context | null>(null);
  const [reason, setReason] = useState(''), [busy, setBusy] = useState(false), [error, setError] = useState('');
  useEffect(() => { let active = true; void request(`/appointments/${appointmentId}/travel-fee`).then(value => { if (active) setData(value); }).catch(cause => { if (active) setError(cause.message); }); return () => { active = false; }; }, [appointmentId, request]);
  const waive = async () => {
    if (!data || busy) return;
    setBusy(true); onBusyChange(true); setError('');
    try {
      const value = await request(`/appointments/${appointmentId}/travel-fee/waive`, { method: 'POST', body: JSON.stringify({ version: data.version, reason }) });
      setData(value); setReason(''); changed(value.version);
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to waive travel fee.')); }
    finally { setBusy(false); onBusyChange(false); }
  };
  if (!data && !error) return null;
  return <Stack spacing={2}>{error && <Alert severity="error">{error}</Alert>}{data && (data.can_waive || data.waived) && <>
    <Typography variant="h6">{t('Session travel fee')}</Typography>
    <Typography>{t('Travel fee for this session: {{fee}} CAD', { fee: formatCad(data.fee_cents, i18n.resolvedLanguage) })}</Typography>
    {data.waived ? <Alert severity="success">{t('Travel fee waived for this session.')} {data.reason}</Alert> : <>
      <TextField label={t('Reason for waiving travel fee')} value={reason} disabled={busy} inputProps={{ maxLength: 300 }} onChange={e => setReason(e.target.value)} />
      <Button disabled={busy || !reason.trim()} onClick={() => void waive()}>{t('Waive travel fee for this session')}</Button>
      <Typography variant="body2">{t('This removes only this session’s travel fee before payment. Other sessions keep their saved fees.')}</Typography>
    </>}
  </>}</Stack>;
}

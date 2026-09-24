import { useEffect, useState } from 'react';
import { Alert, Box, Button, Checkbox, FormControlLabel, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiErrorMessage } from '../shared/api';
import { useUnsavedChanges } from '../shared/UnsavedChanges';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type Preferences = {
  work_email: string;
  email_enabled: boolean;
  email_destination: 'work' | 'personal' | 'both';
  personal_email: string | null;
  personal_email_verified: boolean;
  mobile_phone: string | null;
  sms_requested: boolean;
  sms_delivery_active: boolean;
};

export function StaffNotificationSettings() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [saved, setSaved] = useState<Preferences>();
  const [form, setForm] = useState<Preferences>();
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const editable = (value?: Preferences) => value && [value.email_enabled, value.email_destination, value.personal_email ?? '', value.mobile_phone ?? '', value.sms_requested];
  useUnsavedChanges(Boolean(form && saved && JSON.stringify(editable(form)) !== JSON.stringify(editable(saved))));

  const request = async (path: string, method = 'GET', body?: unknown) => {
    const token = await getAccessToken();
    const response = await fetch(`${api}/profile/notifications${path}`, {
      method,
      headers: { Authorization: `Bearer ${token}`, ...(body ? { 'Content-Type': 'application/json' } : {}) },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const payload = await response.json();
    if (!response.ok) throw new Error(apiErrorMessage(payload, response.status, t('Unable to update notification preferences.')));
    return payload.data;
  };
  useEffect(() => {
    void request('').then((data: Preferences) => { setSaved(data); setForm(data); }).catch(e => setError(e instanceof Error ? e.message : t('Unable to load notification preferences.')));
  }, []);
  const perform = async (action: () => Promise<void>) => {
    setBusy(true); setError(''); setMessage('');
    try { await action(); } catch (e) { setError(e instanceof Error ? e.message : t('Unable to update notification preferences.')); }
    finally { setBusy(false); }
  };
  if (!form || !saved) return <Paper variant="outlined" sx={{ p: 3, mt: 3 }}><Typography variant="h6">{t('Appointment notifications')}</Typography>{error && <Alert severity="error" sx={{ mt: 2 }}>{error}</Alert>}</Paper>;
  const personalChanged = (form.personal_email ?? '').trim().toLowerCase() !== (saved.personal_email ?? '').trim().toLowerCase();
  return <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 }, mt: 3 }}>
    <Typography variant="h5">{t('Appointment notifications')}</Typography>
    <Typography color="text.secondary" sx={{ mt: 1, mb: 2 }}>{t('These settings do not change your Microsoft sign-in address. Only assigned practitioners receive booking notices.')}</Typography>
    <Stack spacing={2}>
      <TextField label={t('Microsoft sign-in email')} value={form.work_email} fullWidth disabled />
      <TextField label={t('Personal notification email')} type="email" value={form.personal_email ?? ''} fullWidth disabled={busy} onChange={e => setForm({ ...form, personal_email: e.target.value, personal_email_verified: false, email_destination: 'work' })} helperText={personalChanged ? t('Save this address before requesting a verification code.') : form.personal_email_verified ? t('Verified') : form.personal_email ? t('Not verified') : t('Optional')} />
      {!personalChanged && form.personal_email && !form.personal_email_verified && <Box>
        <Button disabled={busy} onClick={() => void perform(async () => { await request('/send-code', 'POST'); setMessage(t('Verification code sent to your personal email.')); })}>{t('Send verification code')}</Button>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} sx={{ mt: 1 }}>
          <TextField label={t('Eight-digit code')} inputProps={{ inputMode: 'numeric', maxLength: 8 }} value={code} onChange={e => setCode(e.target.value.replace(/\D/g, '').slice(0, 8))} disabled={busy} />
          <Button disabled={busy || code.length !== 8} onClick={() => void perform(async () => { const data = await request('/verify', 'POST', { code }) as Preferences; setSaved(data); setForm(data); setCode(''); setMessage(t('Personal email verified.')); })}>{t('Verify email')}</Button>
        </Stack>
      </Box>}
      <FormControlLabel control={<Checkbox checked={form.email_enabled} disabled={busy} onChange={e => setForm({ ...form, email_enabled: e.target.checked })} />} label={t('Send appointment notices by email')} />
      <TextField select label={t('Send email to')} value={form.email_destination} onChange={e => setForm({ ...form, email_destination: e.target.value as Preferences['email_destination'] })} disabled={busy || !form.email_enabled} fullWidth>
        <MenuItem value="work">{t('Work email')}</MenuItem>
        <MenuItem value="personal" disabled={!form.personal_email_verified || personalChanged}>{t('Verified personal email')}</MenuItem>
        <MenuItem value="both" disabled={!form.personal_email_verified || personalChanged}>{t('Both email addresses')}</MenuItem>
      </TextField>
      <TextField label={t('Mobile number for SMS')} type="tel" value={form.mobile_phone ?? ''} fullWidth disabled={busy} onChange={e => setForm({ ...form, mobile_phone: e.target.value })} helperText={t('Canadian or US mobile number. This number is not shown to clients.')} />
      <FormControlLabel control={<Checkbox checked={form.sms_requested} disabled={busy} onChange={e => setForm({ ...form, sms_requested: e.target.checked })} />} label={t('Request SMS appointment notices')} />
      <Alert severity="info">{form.sms_delivery_active ? t('SMS notices are active for staff who requested them.') : t('SMS delivery is not active yet. Selecting it records your preference but sends no texts until the clinic enables an approved provider.')}</Alert>
      {message && <Alert severity="success">{message}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}
      <Box><Button variant="contained" disabled={busy} onClick={() => void perform(async () => { const data = await request('', 'PUT', { email_enabled: form.email_enabled, email_destination: form.email_destination, personal_email: form.personal_email ?? '', mobile_phone: form.mobile_phone ?? '', sms_requested: form.sms_requested }) as Preferences; setSaved(data); setForm(data); setMessage(t('Notification preferences saved.')); })}>{t('Save notification preferences')}</Button></Box>
    </Stack>
  </Paper>;
}

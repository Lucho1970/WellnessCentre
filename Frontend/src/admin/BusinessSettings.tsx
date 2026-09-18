import { useEffect, useState, type FormEvent } from 'react';
import { Alert, Button, Paper, Stack, TextField, Typography } from '@mui/material';
import { Save } from 'lucide-react';
import { useStaffAuth } from '../auth/AuthProvider';
import { useClinicConfig, type ClinicConfig } from '../config/ClinicConfigProvider';
import { useTranslation } from 'react-i18next';
import { apiErrorMessage } from '../shared/api';

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';

export function BusinessSettings() {
  const { t } = useTranslation();
  const { config, refresh } = useClinicConfig();
  const { getAccessToken } = useStaffAuth();
  const [form, setForm] = useState<ClinicConfig>(config);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(false);

  useEffect(() => { setForm(config); }, [config]);

  const setField = (field: keyof ClinicConfig, value: string) => setForm(current => ({ ...current, [field]: value }));
  const submit = async (event: FormEvent) => {
    event.preventDefault();setSaving(true);setError('');setSaved(false);
    try {
      const token = await getAccessToken();
      const response = await fetch(`${apiBaseUrl}/admin/clinic`, { method: 'PATCH', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify(form) });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to save business settings.')));
      await refresh();setSaved(true);
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to save business settings.')); }
    finally { setSaving(false); }
  };

  return <Paper component="form" onSubmit={submit} variant="outlined" sx={{ p: { xs: 2, md: 3 } }}>
    <Typography variant="h5">{t('Clinic identity')}</Typography>
    <Typography color="text.secondary" mb={3}>{t('These values update public branding and clinic contact information without rebuilding the site.')}</Typography>
    <Stack spacing={2} maxWidth={680}>
      <TextField required label={t('Operating name')} value={form.name} onChange={event => setField('name', event.target.value)} inputProps={{ maxLength: 160 }} />
      <TextField label={t('Legal business name')} value={form.legal_name ?? ''} onChange={event => setField('legal_name', event.target.value)} inputProps={{ maxLength: 190 }} />
      <TextField type="email" label={t('Business email')} value={form.email ?? ''} onChange={event => setField('email', event.target.value)} inputProps={{ maxLength: 190 }} />
      <TextField label={t('Business phone')} value={form.phone ?? ''} onChange={event => setField('phone', event.target.value)} inputProps={{ maxLength: 40 }} />
      {error && <Alert severity="error">{error}</Alert>}
      {saved && <Alert severity="success">{t('Business settings saved.')}</Alert>}
      <Button type="submit" variant="contained" startIcon={<Save size={18}/>} disabled={saving} sx={{ alignSelf: 'flex-start' }}>{t(saving ? 'Saving…' : 'Save settings')}</Button>
    </Stack>
  </Paper>;
}

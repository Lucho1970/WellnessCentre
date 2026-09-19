import { useEffect, useState } from 'react';
import { Alert, Button, Grid, Paper, Stack, TextField, Typography } from '@mui/material';
import { Plus, Save } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiErrorMessage } from '../shared/api';
import { useUnsavedForm } from '../shared/UnsavedChanges';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';

type Settings = {
  default_lead_time_minutes: number;
  default_booking_horizon_days: number;
  default_cancellation_window_minutes: number;
};

const settingLabels: Record<keyof Settings, string> = {
  default_lead_time_minutes: 'Default lead time minutes',
  default_booking_horizon_days: 'Default booking horizon days',
  default_cancellation_window_minutes: 'Default cancellation window minutes',
};

export function CatalogueSettings() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [categories, setCategories] = useState<{ id: number; name: string }[]>([]);
  const [taxes, setTaxes] = useState<{ id: number; code: string; name: string; rate_basis_points: number }[]>([]);
  const [settings, setSettings] = useState<Settings>({
    default_lead_time_minutes: 60,
    default_booking_horizon_days: 90,
    default_cancellation_window_minutes: 1440,
  });
  const [category, setCategory] = useState('');
  const [tax, setTax] = useState({ code: 'HST', name: 'Harmonized Sales Tax', rate: '13' });
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const settingsGuard = useUnsavedForm();
  const categoryGuard = useUnsavedForm();
  const taxGuard = useUnsavedForm();

  const request = async (path: string, method = 'GET', body?: unknown) => {
    const token = await getAccessToken();
    const response = await fetch(`${api}${path}`, {
      method,
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
    });
    const responseBody = await response.json();
    if (!response.ok) throw new Error(apiErrorMessage(responseBody, response.status, t('Unable to save settings.')));
    return responseBody.data;
  };

  const load = async () => {
    try {
      const data = await request('/admin/catalogue-settings');
      setCategories(data.categories);
      setTaxes(data.taxes);
      if (data.settings) setSettings(data.settings);
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : t('Unable to load settings.'));
    }
  };

  useEffect(() => { void load(); }, []);

  const done = async (action: () => Promise<unknown>, text: string, markClean: () => void) => {
    setError('');
    try {
      await action();
      markClean();
      await load();
      setMessage(text);
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : t('Unable to save settings.'));
    }
  };

  return (
    <Stack spacing={3} mt={3}>
      <Paper variant="outlined" sx={{ p: 3 }} onChange={settingsGuard.markDirty}>
        <Typography variant="h5">{t('Booking defaults')}</Typography>
        <Grid container spacing={2} mt={0.5}>
          {(Object.keys(settingLabels) as (keyof Settings)[]).map((key) => (
            <Grid size={{ xs: 12, md: 4 }} key={key}>
              <TextField fullWidth type="number" label={t(settingLabels[key])} value={settings[key]} onChange={(event) => setSettings((current) => ({ ...current, [key]: Number(event.target.value) }))} />
            </Grid>
          ))}
        </Grid>
        <Button variant="contained" startIcon={<Save size={17} />} sx={{ mt: 2 }} onClick={() => done(() => request('/admin/booking-settings', 'PATCH', settings), t('Booking defaults saved.'), settingsGuard.markClean)}>
          {t('Save defaults')}
        </Button>
      </Paper>

      <Grid container spacing={3}>
        <Grid size={{ xs: 12, md: 6 }}>
          <Paper variant="outlined" sx={{ p: 3, height: '100%' }}>
            <Typography variant="h5">{t('Service categories')}</Typography>
            <Stack direction="row" spacing={1} my={2} onChange={categoryGuard.markDirty}>
              <TextField fullWidth label={t('Category name')} value={category} onChange={(event) => setCategory(event.target.value)} />
              <Button startIcon={<Plus size={16} />} onClick={() => done(() => request('/admin/service-categories', 'POST', { name: category }), t('Category added.'), categoryGuard.markClean)}>{t('Add')}</Button>
            </Stack>
            {categories.map((item) => <Typography key={item.id} sx={{ py: 0.5 }}>{item.name}</Typography>)}
          </Paper>
        </Grid>

        <Grid size={{ xs: 12, md: 6 }}>
          <Paper variant="outlined" sx={{ p: 3, height: '100%' }}>
            <Typography variant="h5">{t('Taxes')}</Typography>
            <Stack spacing={1} my={2} onChange={taxGuard.markDirty}>
              <TextField label={t('Code')} value={tax.code} onChange={(event) => setTax((current) => ({ ...current, code: event.target.value }))} />
              <TextField label={t('Name')} value={tax.name} onChange={(event) => setTax((current) => ({ ...current, name: event.target.value }))} />
              <TextField type="number" label={t('Rate %')} value={tax.rate} onChange={(event) => setTax((current) => ({ ...current, rate: event.target.value }))} />
              <Button startIcon={<Plus size={16} />} onClick={() => done(() => request('/admin/taxes', 'POST', { code: tax.code, name: tax.name, rate_basis_points: Math.round(Number(tax.rate) * 100) }), t('Tax added.'), taxGuard.markClean)}>{t('Add tax')}</Button>
            </Stack>
            {taxes.map((item) => <Typography key={item.id}>{item.name} ({(item.rate_basis_points / 100).toFixed(2)}%)</Typography>)}
          </Paper>
        </Grid>
      </Grid>

      {error && <Alert severity="error">{error}</Alert>}
      {message && <Alert severity="success">{message}</Alert>}
    </Stack>
  );
}

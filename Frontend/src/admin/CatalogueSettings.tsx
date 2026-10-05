import { useEffect, useState } from 'react';
import { Alert, Button, Grid, Paper, Stack, TextField, Typography } from '@mui/material';
import { Plus, Save } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiErrorMessage, normalizeNumericIds } from '../shared/api';
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
  const [categories, setCategories] = useState<{ id: number; name: string; name_fr: string | null; description: string | null; description_fr: string | null }[]>([]);
  const [taxes, setTaxes] = useState<{ id: number; code: string; name: string; rate_basis_points: number }[]>([]);
  const [settings, setSettings] = useState<Settings>({
    default_lead_time_minutes: 60,
    default_booking_horizon_days: 90,
    default_cancellation_window_minutes: 1440,
  });
  const [category, setCategory] = useState({ name: '', name_fr: '', description: '', description_fr: '' });
  const [editingCategoryId, setEditingCategoryId] = useState<number | null>(null);
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
    return normalizeNumericIds(responseBody.data);
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
      return true;
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : t('Unable to save settings.'));
      return false;
    }
  };
  const saveCategory = async () => {
    const saved=await done(() => request(editingCategoryId ? `/admin/service-categories/${editingCategoryId}` : '/admin/service-categories', editingCategoryId ? 'PATCH' : 'POST', category), t(editingCategoryId ? 'Category updated.' : 'Category added.'), categoryGuard.markClean);
    if (saved) { setCategory({ name: '', name_fr: '', description: '', description_fr: '' });setEditingCategoryId(null); }
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
            <Stack spacing={1} my={2} onChange={categoryGuard.markDirty}>
              <TextField label={t('Category name (English)')} value={category.name} onChange={(event) => setCategory(current => ({...current,name:event.target.value}))} inputProps={{maxLength:120}} />
              <TextField label={t('Category name (French)')} value={category.name_fr} onChange={(event) => setCategory(current => ({...current,name_fr:event.target.value}))} inputProps={{maxLength:120}} />
              <TextField label={t('Category description (English)')} multiline minRows={2} value={category.description} onChange={(event) => setCategory(current => ({...current,description:event.target.value}))} inputProps={{maxLength:500}} />
              <TextField label={t('Category description (French)')} multiline minRows={2} value={category.description_fr} onChange={(event) => setCategory(current => ({...current,description_fr:event.target.value}))} inputProps={{maxLength:500}} />
              <Stack direction="row" gap={1}><Button startIcon={editingCategoryId ? <Save size={16}/> : <Plus size={16}/>} onClick={() => void saveCategory()} disabled={!category.name.trim()}>{t(editingCategoryId ? 'Save category' : 'Add')}</Button>{editingCategoryId&&<Button onClick={()=>{setEditingCategoryId(null);setCategory({name:'',name_fr:'',description:'',description_fr:''});categoryGuard.markClean();}}>{t('Cancel')}</Button>}</Stack>
            </Stack>
            {categories.map((item) => <Stack key={item.id} direction="row" alignItems="center" justifyContent="space-between" gap={1} sx={{py:0.5}}><Typography>{item.name}{item.name_fr ? ` / ${item.name_fr}` : ''}</Typography><Button size="small" onClick={()=>{setEditingCategoryId(item.id);setCategory({name:item.name,name_fr:item.name_fr??'',description:item.description??'',description_fr:item.description_fr??''});}}>{t('Edit')}</Button></Stack>)}
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

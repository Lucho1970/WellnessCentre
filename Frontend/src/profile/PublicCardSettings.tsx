import { useEffect, useState } from 'react';
import { Alert, Button, Checkbox, FormControlLabel, Paper, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiErrorMessage } from '../shared/api';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type CardForm = { public_name: string; booking_name: string; summary: string; summary_fr: string; public_website_url: string; public_contact_email: string; public_contact_phone: string; public_contact_sms: boolean };
type CardResponse = CardForm & { slug: string; published: boolean };

export function PublicCardSettings() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [form, setForm] = useState<CardForm | null>(null);
  const [slug, setSlug] = useState('');
  const [published, setPublished] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(false);
  useEffect(() => {
    let active = true;
    void (async () => {
      try {
        const token = await getAccessToken();
        const response = await fetch(`${api}/profile/public-card`, { headers: { Authorization: `Bearer ${token}` } });
        const body = await response.json();
        if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to load your public card.')));
        if (!active) return;
        const data = body.data as CardResponse;
        setForm({ public_name: data.public_name, booking_name: data.booking_name, summary: data.summary ?? '', summary_fr: data.summary_fr ?? '', public_website_url: data.public_website_url ?? '', public_contact_email: data.public_contact_email ?? '', public_contact_phone: data.public_contact_phone ?? '', public_contact_sms: Boolean(data.public_contact_sms) });
        setSlug(data.slug); setPublished(Boolean(data.published));
      } catch (cause) { if (active) setError(cause instanceof Error ? cause.message : t('Unable to load your public card.')); }
    })();
    return () => { active = false; };
  }, [getAccessToken, t]);
  const set = <K extends keyof CardForm>(key: K, value: CardForm[K]) => setForm(current => current ? { ...current, [key]: value } : null);
  const save = async () => {
    if (!form) return;
    setBusy(true); setError(''); setSaved(false);
    try {
      const token = await getAccessToken();
      const response = await fetch(`${api}/profile/public-card`, { method: 'PUT', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify(form) });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t('Unable to save your public card.')));
      setSaved(true);
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to save your public card.')); }
    finally { setBusy(false); }
  };
  return <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 } }}><Stack spacing={2}>
    <Typography variant="h5">{t('Public practitioner card')}</Typography>
    <Typography color="text.secondary">{t('Your preferred public name and biography can differ from your sign-in account. Contact fields below are public only if you enter them; your private notification details stay private.')}</Typography>
    {error && <Alert severity="warning">{error}</Alert>}{saved && <Alert severity="success">{t('Public card saved.')}</Alert>}
    {form && <>
      {!published && <Alert severity="info">{t('Your card is not published yet. Ask a clinic administrator to publish your team profile.')}</Alert>}
      <TextField required label={t('Preferred public name')} value={form.public_name} inputProps={{ maxLength: 150 }} onChange={event => set('public_name', event.target.value)}/>
      <TextField required label={t('Short booking name')} value={form.booking_name} inputProps={{ maxLength: 100 }} onChange={event => set('booking_name', event.target.value)}/>
      <TextField multiline minRows={3} label={t('Biography (English)')} value={form.summary} inputProps={{ maxLength: 1000 }} onChange={event => set('summary', event.target.value)}/>
      <TextField multiline minRows={3} label={t('Biography (French)')} value={form.summary_fr} inputProps={{ maxLength: 1000 }} onChange={event => set('summary_fr', event.target.value)}/>
      <Alert severity="info">{t('Only enter contact details you want everyone to see. Leave them blank to hide contact actions.')}</Alert>
      <TextField type="url" label={t('Website or social page URL')} value={form.public_website_url} inputProps={{ maxLength: 2048 }} helperText={t('Optional public link to your website or social page. Include https://.')} onChange={event => set('public_website_url', event.target.value)}/>
      <TextField type="email" label={t('Public contact email')} value={form.public_contact_email} onChange={event => set('public_contact_email', event.target.value)}/>
      <TextField label={t('Public contact phone')} value={form.public_contact_phone} helperText={t('Use international format, for example +12892975234.')} onChange={event => set('public_contact_phone', event.target.value)}/>
      <FormControlLabel control={<Checkbox checked={form.public_contact_sms} disabled={!form.public_contact_phone} onChange={event => set('public_contact_sms', event.target.checked)}/>} label={t('Offer a Text action for this public phone number')}/>
      <Stack direction="row" gap={2} alignItems="center"><Button variant="contained" disabled={busy || !form.public_name.trim() || !form.booking_name.trim()} onClick={() => void save()}>{t('Save public card')}</Button>{published && <Button href={`/practitioners/${encodeURIComponent(slug)}`} target="_blank" rel="noopener">{t('View public profile')}</Button>}</Stack>
    </>}
  </Stack></Paper>;
}

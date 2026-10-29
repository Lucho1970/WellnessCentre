import { ApiError } from '../shared/api';
import { Alert, TextField, withFormValidation, useFormValidation } from '../shared/FormValidation';
import { useEffect, useRef, useState, type ChangeEvent, type FormEvent } from 'react';
import { Box, Button, MenuItem, Paper, Stack, Typography } from '@mui/material';
import { ImagePlus, Save, Trash2 } from 'lucide-react';
import { useStaffAuth } from '../auth/AuthProvider';
import { useClinicConfig, type ClinicConfig } from '../config/ClinicConfigProvider';
import { useTranslation } from 'react-i18next';
import { useUnsavedForm } from '../shared/UnsavedChanges';
import { wellnexaPalette, wellnexaPortalPreset } from '../shared/wellnexaTheme';

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type BrandAssetType = 'logo' | 'favicon';

async function normalizeBrandImage(file: File, type: BrandAssetType, invalid: string, tooLarge: string, unavailable: string) {
  if (!file.type.startsWith('image/')) throw new Error(invalid);
  if (file.size > 5_000_000) throw new Error(tooLarge);
  const bitmap = await createImageBitmap(file), canvas = document.createElement('canvas'), context = canvas.getContext('2d');
  if (!context) { bitmap.close(); throw new Error(unavailable); }
  if (type === 'favicon') {
    const size = 128, crop = Math.min(bitmap.width, bitmap.height);
    canvas.width = size; canvas.height = size;
    context.drawImage(bitmap, (bitmap.width - crop) / 2, (bitmap.height - crop) / 2, crop, crop, 0, 0, size, size);
    bitmap.close();
    return { mime_type: 'image/png', image_base64: canvas.toDataURL('image/png').split(',')[1] };
  }
  const scale = Math.min(800 / bitmap.width, 240 / bitmap.height, 1);
  canvas.width = Math.max(1, Math.round(bitmap.width * scale)); canvas.height = Math.max(1, Math.round(bitmap.height * scale));
  context.drawImage(bitmap, 0, 0, canvas.width, canvas.height); bitmap.close();
  return { mime_type: 'image/webp', image_base64: canvas.toDataURL('image/webp', .88).split(',')[1] };
}

function BusinessSettingsForm() {
  const formValidation = useFormValidation();
  const { t } = useTranslation();
  const { config, refresh } = useClinicConfig();
  const { getAccessToken } = useStaffAuth();
  const [form, setForm] = useState<ClinicConfig>({ ...config, name: '', legal_name: null, email: null, phone: null });
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(false);
  const [assetMessage, setAssetMessage] = useState('');
  const [assetVersions, setAssetVersions] = useState<{ logo: string | null; favicon: string | null }>({ logo: null, favicon: null });
  const [themePrimary, setThemePrimary] = useState('#176b62');
  const [themeSecondary, setThemeSecondary] = useState('#d8754c');
  const [themeFont, setThemeFont] = useState<ClinicConfig['theme_font_family']>('Inter');
  const [themeMessage, setThemeMessage] = useState('');
  const [welcome, setWelcome] = useState({ welcome_title_en: '', welcome_title_fr: '', welcome_body_en: '', welcome_body_fr: '' });
  const [welcomeMessage, setWelcomeMessage] = useState('');
  const [welcomeError, setWelcomeError] = useState('');
  const { markDirty, markClean } = useUnsavedForm();
  const welcomeGuard = useUnsavedForm();
  const themeGuard = useUnsavedForm();
  const [loaded, setLoaded] = useState(false);
  const [pending, setPending] = useState(false);
  const [accessError, setAccessError] = useState('');
  const generation = useRef(0);
  type Section = 'profile' | 'theme' | 'welcome' | 'assets';
  const clearAccess = () => {
    setLoaded(false); setForm(current => ({ ...current, name: '', legal_name: null, email: null, phone: null }));
    setAssetVersions({ logo: null, favicon: null }); setThemePrimary('#176b62'); setThemeSecondary('#d8754c'); setThemeFont('Inter');
    setWelcome({ welcome_title_en: '', welcome_title_fr: '', welcome_body_en: '', welcome_body_fr: '' });
    setSaved(false); setAssetMessage(''); setThemeMessage(''); setWelcomeMessage(''); setError(''); setWelcomeError('');
    markClean(); welcomeGuard.markClean(); themeGuard.markClean(); formValidation.clear();
  };
  const request = async (path: string, method = 'GET', payload?: unknown) => {
    const token = await getAccessToken();
    const response = await fetch(`${apiBaseUrl}${path}`, { method, headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: payload === undefined ? undefined : JSON.stringify(payload) });
    const body = await response.json();
    if (!response.ok) throw new ApiError(body, response.status, t('Unable to save business settings.'));
    return body.data;
  };
  const load = async (section?: Section) => {
    const current = ++generation.current; setPending(true); setLoaded(false); setAccessError('');
    try {
      await request('/admin/clinic/branding');
      const fresh = await refresh();
      if (current !== generation.current) return false;
      if (!fresh) throw new Error(t('Unable to load settings.'));
      if (!section || section === 'profile') setForm(fresh);
      if (!section || section === 'theme') { setThemePrimary(fresh.theme_primary_color || '#176b62'); setThemeSecondary(fresh.theme_secondary_color || '#d8754c'); setThemeFont(fresh.theme_font_family || 'Inter'); }
      if (!section || section === 'welcome') setWelcome({ welcome_title_en: fresh.welcome_title_en ?? '', welcome_title_fr: fresh.welcome_title_fr ?? '', welcome_body_en: fresh.welcome_body_en ?? '', welcome_body_fr: fresh.welcome_body_fr ?? '' });
      setAssetVersions({ logo: fresh.logo_version, favicon: fresh.favicon_version }); setLoaded(true); return true;
    } catch (cause) {
      if (current !== generation.current) return false;
      clearAccess(); setAccessError(cause instanceof Error ? cause.message : t('Unable to load settings.')); return false;
    } finally { if (current === generation.current) setPending(false); }
  };
  useEffect(() => { void load(); return () => { generation.current++; }; }, []);
  const perform = async (section: Section, path: string, method: string, payload: unknown, success: () => void, clean?: () => void) => {
    const current = ++generation.current; setPending(true); formValidation.clear(); setError(''); setWelcomeError('');
    setSaved(false); setAssetMessage(''); setThemeMessage(''); setWelcomeMessage('');
    try {
      await request(path, method, payload);
      if (current !== generation.current) return;
      clean?.(); if (!await load(section)) return; success();
    } catch (cause) {
      if (current !== generation.current) return;
      const message = cause instanceof Error ? cause.message : t('Unable to save business settings.');
      if (cause instanceof ApiError && [401,403,404].includes(cause.status)) { clearAccess(); setAccessError(message); }
      else { formValidation.capture(cause); if (section === 'welcome') setWelcomeError(message); else setError(message); }
    } finally { if (current === generation.current) setPending(false); }
  };
  const setField = (field: keyof ClinicConfig, value: string) => setForm(current => ({ ...current, [field]: value }));
  const submit = async (event: FormEvent) => {
    event.preventDefault();
    await perform('profile', '/admin/clinic', 'PATCH', { name: form.name, legal_name: form.legal_name, email: form.email, phone: form.phone }, () => setSaved(true), markClean);
  };
  const uploadAsset = async (type: BrandAssetType, event: ChangeEvent<HTMLInputElement>) => {
    const input = event.target, file = input.files?.[0]; if (!file) return;
    const current = ++generation.current; setPending(true); setError(''); setAssetMessage('');
    try {
      const payload = await normalizeBrandImage(file, type, t('Choose an image file.'), t('Choose an image smaller than 5 MB.'), t('Image processing is unavailable.'));
      if (current !== generation.current) return;
      await perform('assets', `/admin/clinic/branding/${type}`, 'PUT', payload, () => setAssetMessage(t(type === 'logo' ? 'Business logo updated.' : 'Favicon updated.')));
    } catch (cause) { if (current === generation.current) { formValidation.capture(cause); setError(cause instanceof Error ? cause.message : t('Unable to upload brand image.')); } }
    finally { input.value = ''; if (current === generation.current) setPending(false); }
  };
  const removeAsset = async (type: BrandAssetType) => perform('assets', `/admin/clinic/branding/${type}`, 'DELETE', undefined, () => setAssetMessage(t(type === 'logo' ? 'Business logo removed.' : 'Favicon removed.')));
  const saveTheme = async (event: FormEvent) => { event.preventDefault(); await perform('theme', '/admin/clinic/portal-theme', 'PUT', { primary_color: themePrimary, secondary_color: themeSecondary, font_family: themeFont }, () => setThemeMessage(t('Portal theme saved.')), themeGuard.markClean); };
  const saveWelcome = async (event: FormEvent) => { event.preventDefault(); await perform('welcome', '/admin/clinic/portal-welcome', 'PUT', welcome, () => setWelcomeMessage(t('Portal welcome message saved.')), welcomeGuard.markClean); };

  return <Stack spacing={3}>
    {accessError && <Alert severity="error" action={<Button disabled={pending} onClick={() => void load()}>{t('Retry')}</Button>}>{accessError}</Alert>}
    <Stack component="fieldset" disabled={!loaded || pending} spacing={3} sx={{ border: 0, m: 0, p: 0, minWidth: 0 }}><Paper component="form" onSubmit={submit} onChange={markDirty} variant="outlined" sx={{ p: { xs: 2, md: 3 } }}>
    <Typography variant="h5">{t('Clinic identity')}</Typography>
    <Typography color="text.secondary" mb={3}>{t('These values update public branding and clinic contact information without rebuilding the site.')}</Typography>
    <Stack spacing={2} maxWidth={680}>
      <TextField name="name" required label={t('Operating name')} value={form.name} onChange={event => setField('name', event.target.value)} inputProps={{ maxLength: 160 }} />
      <TextField name="legal_name" label={t('Legal business name')} value={form.legal_name ?? ''} onChange={event => setField('legal_name', event.target.value)} inputProps={{ maxLength: 190 }} />
      <TextField name="email" type="email" label={t('Business email')} value={form.email ?? ''} onChange={event => setField('email', event.target.value)} inputProps={{ maxLength: 190 }} />
      <TextField name="phone" label={t('Business phone')} value={form.phone ?? ''} onChange={event => setField('phone', event.target.value)} inputProps={{ maxLength: 40 }} />
      {error && <Alert severity="error">{error}</Alert>}
      {saved && <Alert severity="success">{t('Business settings saved.')}</Alert>}
      <Button type="submit" variant="contained" startIcon={<Save size={18}/>} disabled={pending} sx={{ alignSelf: 'flex-start' }}>{t(pending ? 'Saving…' : 'Save settings')}</Button>
    </Stack>
  </Paper><Paper variant="outlined" sx={{ p: { xs: 2, md: 3 } }}>
    <Typography variant="h5">{t('Brand images')}</Typography><Typography color="text.secondary" mb={3}>{t('Upload a wide business logo and a separate simple square favicon. Both update without rebuilding the site.')}</Typography>
    <Stack spacing={3} maxWidth={760}>
      <BrandAssetEditor type="logo" title={t('Business logo')} description={t('Use a transparent PNG or another image up to 5 MB. It will be resized to fit the site header.')} version={assetVersions.logo} busy={!loaded || pending} upload={uploadAsset} remove={removeAsset}/>
      <BrandAssetEditor type="favicon" title={t('Browser favicon')} description={t('Use a simple square image that remains recognizable at small sizes. It will be cropped to a square.')} version={assetVersions.favicon} busy={!loaded || pending} upload={uploadAsset} remove={removeAsset}/>
      {assetMessage&&<Alert severity="success">{assetMessage}</Alert>}
    </Stack>
  </Paper><Paper component="form" onSubmit={saveWelcome} onChange={welcomeGuard.markDirty} variant="outlined" sx={{ p: { xs: 2, md: 3 } }}><Typography variant="h5">{t('Portal welcome message')}</Typography><Typography color="text.secondary" mb={2}>{t('Introduce your clinic to visitors before they explore treatments. Provide both languages so the portal can switch cleanly.')}</Typography><Stack spacing={2} maxWidth={760}>
    <TextField name="welcome_title_en" required label={t('Welcome title (English)')} value={welcome.welcome_title_en} onChange={event=>setWelcome(current=>({...current,welcome_title_en:event.target.value}))} inputProps={{maxLength:160}}/>
    <TextField name="welcome_body_en" required label={t('Welcome message (English)')} multiline minRows={3} value={welcome.welcome_body_en} onChange={event=>setWelcome(current=>({...current,welcome_body_en:event.target.value}))} inputProps={{maxLength:3000}}/>
    <TextField name="welcome_title_fr" required label={t('Welcome title (French)')} value={welcome.welcome_title_fr} onChange={event=>setWelcome(current=>({...current,welcome_title_fr:event.target.value}))} inputProps={{maxLength:160}}/>
    <TextField name="welcome_body_fr" required label={t('Welcome message (French)')} multiline minRows={3} value={welcome.welcome_body_fr} onChange={event=>setWelcome(current=>({...current,welcome_body_fr:event.target.value}))} inputProps={{maxLength:3000}}/>
    {welcomeError&&<Alert severity="error">{welcomeError}</Alert>}{welcomeMessage&&<Alert severity="success">{welcomeMessage}</Alert>}
    <Button type="submit" variant="contained" disabled={pending} sx={{alignSelf:'flex-start'}}>{t('Save welcome message')}</Button>
  </Stack></Paper><Paper component="form" onSubmit={saveTheme} onChange={themeGuard.markDirty} variant="outlined" sx={{ p: { xs: 2, md: 3 } }}><Typography variant="h5">{t('Portal appearance')}</Typography><Typography color="text.secondary" mb={2}>{t('Match the booking portal to the clinic website. Changes apply to guest, client, and staff portal pages.')}</Typography><Stack spacing={2} maxWidth={500}><Button disabled={pending} variant="outlined" onClick={() => { setThemePrimary(wellnexaPortalPreset.primary_color); setThemeSecondary(wellnexaPortalPreset.secondary_color); setThemeFont(wellnexaPortalPreset.font_family); themeGuard.markDirty(); setThemeMessage('' ); }}>{t('Use Wellnexa palette')}</Button><Stack direction="row" gap={1} aria-label={t('Wellnexa colour palette')}>{Object.entries(wellnexaPalette).map(([name, colour]) => <Box key={name} title={`${name}: ${colour}`} sx={{ width: 30, height: 30, borderRadius: 1, bgcolor: colour, border: '1px solid', borderColor: 'divider' }}/>)}</Stack><Typography variant="body2">{t('Choose the preset, then save to apply the full Wellnexa palette to this clinic portal.')}</Typography><TextField name="primary_color" type="color" label={t('Primary colour')} value={themePrimary} onChange={event=>setThemePrimary(event.target.value)} slotProps={{ inputLabel: { shrink: true } }}/><TextField name="secondary_color" type="color" label={t('Accent colour')} value={themeSecondary} onChange={event=>setThemeSecondary(event.target.value)} slotProps={{ inputLabel: { shrink: true } }}/><TextField name="font_family" select label={t('Portal font')} value={themeFont} onChange={event=>setThemeFont(event.target.value as ClinicConfig['theme_font_family'])}><MenuItem value="Inter">Inter</MenuItem><MenuItem value="Arial">Arial</MenuItem><MenuItem value="Georgia">Georgia</MenuItem></TextField>{themeMessage&&<Alert severity="success">{themeMessage}</Alert>}<Button type="submit" disabled={pending} variant="contained" sx={{ alignSelf: 'flex-start' }}>{t('Save portal appearance')}</Button></Stack></Paper></Stack></Stack>;
}
export const BusinessSettings = withFormValidation(BusinessSettingsForm);


function BrandAssetEditor({type,title,description,version,busy,upload,remove}:{type:BrandAssetType;title:string;description:string;version:string|null;busy:boolean;upload:(type:BrandAssetType,event:ChangeEvent<HTMLInputElement>)=>void;remove:(type:BrandAssetType)=>void}){
  const {t}=useTranslation();const source=version?`${apiBaseUrl}/brand/${type}?v=${encodeURIComponent(version)}`:'';
  return <Stack direction={{xs:'column',sm:'row'}} spacing={2.5} alignItems={{sm:'center'}}><Box sx={{width:type==='logo'?220:96,height:96,border:'1px solid',borderColor:'divider',borderRadius:2,display:'grid',placeItems:'center',bgcolor:'background.default',p:1,flexShrink:0}}>{source?<Box component="img" src={source} alt={type==='logo'?t('Current business logo'):t('Current favicon')} sx={{maxWidth:'100%',maxHeight:'100%',objectFit:'contain'}}/>:<Typography variant="body2" color="text.secondary">{t('Default icon')}</Typography>}</Box><Stack spacing={1} flex={1}><Box><Typography fontWeight={750}>{title}</Typography><Typography variant="body2" color="text.secondary">{description}</Typography></Box><Stack direction="row" gap={1} flexWrap="wrap"><Button component="label" variant="outlined" startIcon={<ImagePlus size={17}/>} disabled={busy}>{t(version?'Replace image':'Choose image')}<input disabled={busy} hidden type="file" accept="image/png,image/jpeg,image/webp" onChange={event=>upload(type,event)}/></Button>{version&&<Button color="error" startIcon={<Trash2 size={17}/>} disabled={busy} onClick={()=>remove(type)}>{t('Remove')}</Button>}</Stack></Stack></Stack>;
}

import { ApiError } from '../shared/api';
import { Alert, TextField, withFormValidation, useFormValidation } from '../shared/FormValidation';
import { useEffect, useState, type ChangeEvent, type FormEvent } from 'react';
import { Box, Button, MenuItem, Paper, Stack, Typography } from '@mui/material';
import { ImagePlus, Save, Trash2 } from 'lucide-react';
import { useStaffAuth } from '../auth/AuthProvider';
import { useClinicConfig, type ClinicConfig } from '../config/ClinicConfigProvider';
import { useTranslation } from 'react-i18next';
import { apiErrorMessage } from '../shared/api';
import { useUnsavedForm } from '../shared/UnsavedChanges';

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
  const [form, setForm] = useState<ClinicConfig>(config);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(false);
  const [assetBusy, setAssetBusy] = useState<BrandAssetType | null>(null);
  const [assetMessage, setAssetMessage] = useState('');
  const [themePrimary, setThemePrimary] = useState(config.theme_primary_color || '#176b62');
  const [themeSecondary, setThemeSecondary] = useState(config.theme_secondary_color || '#d8754c');
  const [themeFont, setThemeFont] = useState<ClinicConfig['theme_font_family']>(config.theme_font_family || 'Inter');
  const [themeBusy, setThemeBusy] = useState(false);
  const [themeMessage, setThemeMessage] = useState('');
  const [welcome, setWelcome] = useState({ welcome_title_en: config.welcome_title_en ?? '', welcome_title_fr: config.welcome_title_fr ?? '', welcome_body_en: config.welcome_body_en ?? '', welcome_body_fr: config.welcome_body_fr ?? '' });
  const [welcomeBusy, setWelcomeBusy] = useState(false);
  const [welcomeMessage, setWelcomeMessage] = useState('');
  const [welcomeError, setWelcomeError] = useState('');
  const { markDirty, markClean } = useUnsavedForm();
  const welcomeGuard = useUnsavedForm();

  useEffect(() => { setForm(config); }, [config]);
  useEffect(() => { setThemePrimary(config.theme_primary_color || '#176b62'); setThemeSecondary(config.theme_secondary_color || '#d8754c'); setThemeFont(config.theme_font_family || 'Inter'); }, [config]);
  useEffect(() => { setWelcome({ welcome_title_en: config.welcome_title_en ?? '', welcome_title_fr: config.welcome_title_fr ?? '', welcome_body_en: config.welcome_body_en ?? '', welcome_body_fr: config.welcome_body_fr ?? '' }); }, [config]);

  const setField = (field: keyof ClinicConfig, value: string) => setForm(current => ({ ...current, [field]: value }));
  const submit = async (event: FormEvent) => {
    event.preventDefault();setSaving(true);(formValidation.clear(), setError(''));setSaved(false);
    try {
      const token = await getAccessToken();
      const response = await fetch(`${apiBaseUrl}/admin/clinic`, { method: 'PATCH', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify(form) });
      const body = await response.json();
      if (!response.ok) throw new ApiError(body, response.status, t('Unable to save business settings.'));
      markClean(); await refresh();setSaved(true);
    } catch (cause) { formValidation.capture(cause); setError(cause instanceof Error ? cause.message : t('Unable to save business settings.')); }
    finally { setSaving(false); }
  };

  const uploadAsset = async (type: BrandAssetType, event: ChangeEvent<HTMLInputElement>) => {
    const file=event.target.files?.[0]; if(!file)return; setAssetBusy(type);(formValidation.clear(), setError(''));setAssetMessage('');
    try{const payload=await normalizeBrandImage(file,type,t('Choose an image file.'),t('Choose an image smaller than 5 MB.'),t('Image processing is unavailable.')),token=await getAccessToken();const response=await fetch(`${apiBaseUrl}/admin/clinic/branding/${type}`,{method:'PUT',headers:{Authorization:`Bearer ${token}`,'Content-Type':'application/json'},body:JSON.stringify(payload)}),body=await response.json();if(!response.ok)throw new ApiError(body, response.status, t('Unable to upload brand image.'));await refresh();setAssetMessage(t(type==='logo'?'Business logo updated.':'Favicon updated.'));}catch(cause){ formValidation.capture(cause);setError(cause instanceof Error?cause.message:t('Unable to upload brand image.'));}finally{setAssetBusy(null);event.target.value='';}
  };
  const removeAsset = async (type: BrandAssetType) => {setAssetBusy(type);(formValidation.clear(), setError(''));setAssetMessage('');try{const token=await getAccessToken(),response=await fetch(`${apiBaseUrl}/admin/clinic/branding/${type}`,{method:'DELETE',headers:{Authorization:`Bearer ${token}`}}),body=await response.json();if(!response.ok)throw new ApiError(body, response.status, t('Unable to remove brand image.'));await refresh();setAssetMessage(t(type==='logo'?'Business logo removed.':'Favicon removed.'));}catch(cause){ formValidation.capture(cause);setError(cause instanceof Error?cause.message:t('Unable to remove brand image.'));}finally{setAssetBusy(null);}};
  const saveTheme = async (event: FormEvent) => {event.preventDefault();setThemeBusy(true);(formValidation.clear(), setError(''));setThemeMessage('');try{const token=await getAccessToken(),response=await fetch(`${apiBaseUrl}/admin/clinic/portal-theme`,{method:'PUT',headers:{Authorization:`Bearer ${token}`,'Content-Type':'application/json'},body:JSON.stringify({primary_color:themePrimary,secondary_color:themeSecondary,font_family:themeFont})}),body=await response.json();if(!response.ok)throw new ApiError(body, response.status, t('Unable to save portal theme.'));markClean();await refresh();setThemeMessage(t('Portal theme saved.'));}catch(cause){ formValidation.capture(cause);setError(cause instanceof Error?cause.message:t('Unable to save portal theme.'));}finally{setThemeBusy(false);}};
  const saveWelcome = async (event: FormEvent) => {
    event.preventDefault();setWelcomeBusy(true);(formValidation.clear(), setWelcomeError(''));setWelcomeMessage('');
    try { const token=await getAccessToken();const response=await fetch(`${apiBaseUrl}/admin/clinic/portal-welcome`,{method:'PUT',headers:{Authorization:`Bearer ${token}`,'Content-Type':'application/json'},body:JSON.stringify(welcome)});const body=await response.json();if(!response.ok)throw new ApiError(body, response.status, t('Unable to save portal welcome message.'));welcomeGuard.markClean();await refresh();setWelcomeMessage(t('Portal welcome message saved.')); }
    catch(cause){ formValidation.capture(cause);setWelcomeError(cause instanceof Error?cause.message:t('Unable to save portal welcome message.'));}
    finally{setWelcomeBusy(false);}
  };

  return <Stack spacing={3}><Paper component="form" onSubmit={submit} onChange={markDirty} variant="outlined" sx={{ p: { xs: 2, md: 3 } }}>
    <Typography variant="h5">{t('Clinic identity')}</Typography>
    <Typography color="text.secondary" mb={3}>{t('These values update public branding and clinic contact information without rebuilding the site.')}</Typography>
    <Stack spacing={2} maxWidth={680}>
      <TextField name="name" required label={t('Operating name')} value={form.name} onChange={event => setField('name', event.target.value)} inputProps={{ maxLength: 160 }} />
      <TextField name="legal_name" label={t('Legal business name')} value={form.legal_name ?? ''} onChange={event => setField('legal_name', event.target.value)} inputProps={{ maxLength: 190 }} />
      <TextField name="email" type="email" label={t('Business email')} value={form.email ?? ''} onChange={event => setField('email', event.target.value)} inputProps={{ maxLength: 190 }} />
      <TextField name="phone" label={t('Business phone')} value={form.phone ?? ''} onChange={event => setField('phone', event.target.value)} inputProps={{ maxLength: 40 }} />
      {error && <Alert severity="error">{error}</Alert>}
      {saved && <Alert severity="success">{t('Business settings saved.')}</Alert>}
      <Button type="submit" variant="contained" startIcon={<Save size={18}/>} disabled={saving} sx={{ alignSelf: 'flex-start' }}>{t(saving ? 'Saving…' : 'Save settings')}</Button>
    </Stack>
  </Paper><Paper variant="outlined" sx={{ p: { xs: 2, md: 3 } }}>
    <Typography variant="h5">{t('Brand images')}</Typography><Typography color="text.secondary" mb={3}>{t('Upload a wide business logo and a separate simple square favicon. Both update without rebuilding the site.')}</Typography>
    <Stack spacing={3} maxWidth={760}>
      <BrandAssetEditor type="logo" title={t('Business logo')} description={t('Use a transparent PNG or another image up to 5 MB. It will be resized to fit the site header.')} version={config.logo_version} busy={assetBusy==='logo'} upload={uploadAsset} remove={removeAsset}/>
      <BrandAssetEditor type="favicon" title={t('Browser favicon')} description={t('Use a simple square image that remains recognizable at small sizes. It will be cropped to a square.')} version={config.favicon_version} busy={assetBusy==='favicon'} upload={uploadAsset} remove={removeAsset}/>
      {assetMessage&&<Alert severity="success">{assetMessage}</Alert>}
    </Stack>
  </Paper><Paper component="form" onSubmit={saveWelcome} onChange={welcomeGuard.markDirty} variant="outlined" sx={{ p: { xs: 2, md: 3 } }}><Typography variant="h5">{t('Portal welcome message')}</Typography><Typography color="text.secondary" mb={2}>{t('Introduce your clinic to visitors before they explore treatments. Provide both languages so the portal can switch cleanly.')}</Typography><Stack spacing={2} maxWidth={760}>
    <TextField name="welcome_title_en" required label={t('Welcome title (English)')} value={welcome.welcome_title_en} onChange={event=>setWelcome(current=>({...current,welcome_title_en:event.target.value}))} inputProps={{maxLength:160}}/>
    <TextField name="welcome_body_en" required label={t('Welcome message (English)')} multiline minRows={3} value={welcome.welcome_body_en} onChange={event=>setWelcome(current=>({...current,welcome_body_en:event.target.value}))} inputProps={{maxLength:3000}}/>
    <TextField name="welcome_title_fr" required label={t('Welcome title (French)')} value={welcome.welcome_title_fr} onChange={event=>setWelcome(current=>({...current,welcome_title_fr:event.target.value}))} inputProps={{maxLength:160}}/>
    <TextField name="welcome_body_fr" required label={t('Welcome message (French)')} multiline minRows={3} value={welcome.welcome_body_fr} onChange={event=>setWelcome(current=>({...current,welcome_body_fr:event.target.value}))} inputProps={{maxLength:3000}}/>
    {welcomeError&&<Alert severity="error">{welcomeError}</Alert>}{welcomeMessage&&<Alert severity="success">{welcomeMessage}</Alert>}
    <Button type="submit" variant="contained" disabled={welcomeBusy} sx={{alignSelf:'flex-start'}}>{t('Save welcome message')}</Button>
  </Stack></Paper><Paper component="form" onSubmit={saveTheme} onChange={markDirty} variant="outlined" sx={{ p: { xs: 2, md: 3 } }}><Typography variant="h5">{t('Portal appearance')}</Typography><Typography color="text.secondary" mb={2}>{t('Match the booking portal to the clinic website. Changes apply to guest, client, and staff portal pages.')}</Typography><Stack spacing={2} maxWidth={500}><TextField name="primary_color" type="color" label={t('Primary colour')} value={themePrimary} onChange={event=>setThemePrimary(event.target.value)} slotProps={{ inputLabel: { shrink: true } }}/><TextField name="secondary_color" type="color" label={t('Accent colour')} value={themeSecondary} onChange={event=>setThemeSecondary(event.target.value)} slotProps={{ inputLabel: { shrink: true } }}/><TextField name="font_family" select label={t('Portal font')} value={themeFont} onChange={event=>setThemeFont(event.target.value as ClinicConfig['theme_font_family'])}><MenuItem value="Inter">Inter</MenuItem><MenuItem value="Arial">Arial</MenuItem><MenuItem value="Georgia">Georgia</MenuItem></TextField>{themeMessage&&<Alert severity="success">{themeMessage}</Alert>}<Button type="submit" disabled={themeBusy} variant="contained" sx={{ alignSelf: 'flex-start' }}>{t('Save portal appearance')}</Button></Stack></Paper></Stack>;
}
export const BusinessSettings = withFormValidation(BusinessSettingsForm);


function BrandAssetEditor({type,title,description,version,busy,upload,remove}:{type:BrandAssetType;title:string;description:string;version:string|null;busy:boolean;upload:(type:BrandAssetType,event:ChangeEvent<HTMLInputElement>)=>void;remove:(type:BrandAssetType)=>void}){
  const {t}=useTranslation();const source=version?`${apiBaseUrl}/brand/${type}?v=${encodeURIComponent(version)}`:'';
  return <Stack direction={{xs:'column',sm:'row'}} spacing={2.5} alignItems={{sm:'center'}}><Box sx={{width:type==='logo'?220:96,height:96,border:'1px solid',borderColor:'divider',borderRadius:2,display:'grid',placeItems:'center',bgcolor:'background.default',p:1,flexShrink:0}}>{source?<Box component="img" src={source} alt={type==='logo'?t('Current business logo'):t('Current favicon')} sx={{maxWidth:'100%',maxHeight:'100%',objectFit:'contain'}}/>:<Typography variant="body2" color="text.secondary">{t('Default icon')}</Typography>}</Box><Stack spacing={1} flex={1}><Box><Typography fontWeight={750}>{title}</Typography><Typography variant="body2" color="text.secondary">{description}</Typography></Box><Stack direction="row" gap={1} flexWrap="wrap"><Button component="label" variant="outlined" startIcon={<ImagePlus size={17}/>} disabled={busy}>{t(version?'Replace image':'Choose image')}<input hidden type="file" accept="image/png,image/jpeg,image/webp" onChange={event=>upload(type,event)}/></Button>{version&&<Button color="error" startIcon={<Trash2 size={17}/>} disabled={busy} onClick={()=>remove(type)}>{t('Remove')}</Button>}</Stack></Stack></Stack>;
}

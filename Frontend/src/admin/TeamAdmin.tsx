import { useCallback, useEffect, useState } from 'react';
import { Alert, Button, Checkbox, FormControlLabel, List, ListItemButton, ListItemText, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { Save } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { useUnsavedChanges } from '../shared/UnsavedChanges';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type Profile = { user_id: number; display_name: string; status: string; practitioner_id: number | null; slug: string | null; section: 'practitioner'|'administration'|null; public_title: string | null; public_title_fr: string | null; summary: string | null; summary_fr: string | null; display_order: number | null; published: number|boolean|null; show_booking_action: number|boolean|null; has_image: number|boolean };
type Form = { slug: string; section: 'practitioner'|'administration'; public_title: string; public_title_fr: string; summary: string; summary_fr: string; display_order: number; published: boolean; show_booking_action: boolean };
const slug = (name: string) => name.toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 100);
const formFor = (item: Profile): Form => ({ slug:item.slug??slug(item.display_name), section:item.section??(item.practitioner_id?'practitioner':'administration'), public_title:item.public_title??'', public_title_fr:item.public_title_fr??'', summary:item.summary??'', summary_fr:item.summary_fr??'', display_order:Number(item.display_order??100), published:Boolean(Number(item.published)), show_booking_action:item.practitioner_id?Boolean(Number(item.show_booking_action)):false });

export function TeamAdmin() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [items,setItems]=useState<Profile[]>([]),[selectedId,setSelectedId]=useState<number|null>(null),[form,setForm]=useState<Form|null>(null),[original,setOriginal]=useState<Form|null>(null);
  const [error,setError]=useState(''),[saved,setSaved]=useState('');
  useUnsavedChanges(Boolean(form&&original&&JSON.stringify(form)!==JSON.stringify(original)));
  const load=useCallback(async(preferred?:number)=>{setError('');try{const token=await getAccessToken();const response=await fetch(`${api}/admin/team-profiles`,{headers:{Authorization:`Bearer ${token}`}});const body=await response.json();if(!response.ok)throw new Error(apiErrorMessage(body,response.status,t('Unable to load team profiles.')));const next=normalizeNumericIds<Profile[]>(body.data);setItems(next);const id=preferred??next[0]?.user_id??null;const item=next.find(value=>value.user_id===id)??next[0]??null;setSelectedId(item?.user_id??null);const value=item?formFor(item):null;setForm(value);setOriginal(value);}catch(cause){setError(cause instanceof Error?cause.message:t('Unable to load team profiles.'));}},[getAccessToken,t]);
  useEffect(()=>{void load();},[load]);
  const select=(item:Profile)=>{const value=formFor(item);setSelectedId(item.user_id);setForm(value);setOriginal(value);setError('');setSaved('');};
  const set=<K extends keyof Form>(key:K,value:Form[K])=>setForm(current=>current?{...current,[key]:value}:null);
  const save=async()=>{if(!form||!selectedId)return;setError('');setSaved('');try{const token=await getAccessToken();const response=await fetch(`${api}/admin/team-profiles/${selectedId}`,{method:'PUT',headers:{Authorization:`Bearer ${token}`,'Content-Type':'application/json'},body:JSON.stringify(form)});const body=await response.json();if(!response.ok)throw new Error(apiErrorMessage(body,response.status,t('Unable to save team profile.')));await load(selectedId);setSaved(t('Public team profile saved.'));}catch(cause){setError(cause instanceof Error?cause.message:t('Unable to save team profile.'));}};
  const selected=items.find(item=>item.user_id===selectedId);
  return <Stack spacing={3}>
    <Alert severity="info">{t('Only profiles you publish here appear on the public Contact page. Staff email and account details are never published.')}</Alert>
    {error&&<Alert severity="error">{error}</Alert>}{saved&&<Alert severity="success">{saved}</Alert>}
    <Stack direction={{xs:'column',md:'row'}} spacing={3} alignItems="flex-start">
      <Paper variant="outlined" sx={{width:{xs:'100%',md:330},overflow:'hidden'}}><List disablePadding aria-label={t('Team profiles')}>{items.map(item=><ListItemButton divider key={item.user_id} selected={item.user_id===selectedId} onClick={()=>select(item)}><ListItemText primary={item.display_name} secondary={`${item.practitioner_id?t('Practitioner'):t('Administration')} · ${item.published?t('Published'):t('Not published')}`}/></ListItemButton>)}</List></Paper>
      {form&&selected&&<Paper variant="outlined" sx={{p:3,flex:1,width:'100%'}}><Stack spacing={2}>
        <Typography variant="h5">{selected.display_name}</Typography>
        <Typography variant="body2" color="text.secondary">{selected.has_image?t('Profile image is ready to publish.'):t('No profile image has been uploaded. Initials will be shown.')}</Typography>
        <TextField required label={t('Public URL name')} value={form.slug} helperText={t('Lowercase letters, numbers, and hyphens only.')} onChange={event=>set('slug',event.target.value.toLowerCase())}/>
        <TextField select label={t('Team section')} value={form.section} onChange={event=>{const value=event.target.value as Form['section'];setForm(current=>current?{...current,section:value,show_booking_action:value==='practitioner'?current.show_booking_action:false}:null);}}><MenuItem value="practitioner" disabled={!selected.practitioner_id}>{t('Practitioners')}</MenuItem><MenuItem value="administration">{t('Administration')}</MenuItem></TextField>
        <TextField required label={t('Title (English)')} value={form.public_title} inputProps={{maxLength:150}} onChange={event=>set('public_title',event.target.value)}/>
        <TextField label={t('Title (French)')} value={form.public_title_fr} inputProps={{maxLength:150}} onChange={event=>set('public_title_fr',event.target.value)}/>
        <TextField multiline minRows={3} label={t('Profile summary (English)')} value={form.summary} inputProps={{maxLength:1000}} onChange={event=>set('summary',event.target.value)}/>
        <TextField multiline minRows={3} label={t('Profile summary (French)')} value={form.summary_fr} inputProps={{maxLength:1000}} onChange={event=>set('summary_fr',event.target.value)}/>
        <TextField type="number" label={t('Display order')} value={form.display_order} inputProps={{min:0,max:65535}} onChange={event=>set('display_order',Number(event.target.value))}/>
        {form.section==='practitioner'&&<FormControlLabel control={<Checkbox checked={form.show_booking_action} onChange={event=>set('show_booking_action',event.target.checked)}/>} label={t('Show Book a session action')}/>}
        <FormControlLabel control={<Checkbox checked={form.published} onChange={event=>set('published',event.target.checked)}/>} label={t('Publish on the Contact page')}/>
        <Button variant="contained" startIcon={<Save size={18}/>} disabled={!form.public_title.trim()||!form.slug.trim()} onClick={save}>{t('Save team profile')}</Button>
      </Stack></Paper>}
    </Stack>
  </Stack>;
}

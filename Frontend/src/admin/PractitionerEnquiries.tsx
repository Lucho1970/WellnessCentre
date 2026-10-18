import { useEffect, useState } from 'react';
import { Alert, Button, Checkbox, FormControlLabel, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiRequest } from '../shared/api';
import { useUnsavedChanges } from '../shared/UnsavedChanges';
type Item={id:number;reference:string;full_name:string;email:string;phone:string;discipline:string;credentials:string;service_area:string;message:string;status:string;revision:number;notification_status:string;created_at:string};
type Settings={recipient_email:string|null;enabled:boolean;revision:number;mail_configured:boolean};
type Inbox={items:Item[];has_more:boolean;settings:Settings};
export function PractitionerEnquiries({central=false}:{central?:boolean}) {
  const {t}=useTranslation(),{getAccessToken}=useStaffAuth();
  const [clinics,setClinics]=useState<{id:number;name:string}[]>([]),[clinic,setClinic]=useState(''),[page,setPage]=useState(1),[data,setData]=useState<Inbox|null>(null),[error,setError]=useState(''),[busy,setBusy]=useState(false),[recipient,setRecipient]=useState(''),[enabled,setEnabled]=useState(true),[saved,setSaved]=useState(false),[dirty,setDirty]=useState(false);
  useUnsavedChanges(dirty);
  const base=central?`/application/clinics/${clinic}/practitioner-enquiries`:'/admin/practitioner-enquiries';
  const headers=async()=>({Authorization:`Bearer ${await getAccessToken()}`,'Content-Type':'application/json'});
  const load=async()=>{const result=await apiRequest<Inbox>(`${base}?page=${page}`,{headers:await headers()});setData(result);setRecipient(result.settings.recipient_email??'');setEnabled(result.settings.enabled);setDirty(false);};
  useEffect(()=>{if(!central)return;let active=true;void (async()=>{const result=await apiRequest<{items:{id:number;name:string}[]}>('/application/clinics',{headers:await headers()});if(active){setClinics(result.items);if(result.items[0])setClinic(String(result.items[0].id));}})().catch(cause=>setError(cause.message));return()=>{active=false};},[central,getAccessToken]);
  useEffect(()=>{setData(null);setError('');setSaved(false);if(central&&!clinic)return;let active=true;void(async()=>{const result=await apiRequest<Inbox>(`${base}?page=${page}`,{headers:await headers()});if(active){setData(result);setRecipient(result.settings.recipient_email??'');setEnabled(result.settings.enabled);setDirty(false);}})().catch(cause=>{if(active)setError(cause.message);});return()=>{active=false};},[central,clinic,page,getAccessToken]);
  const run=async(action:()=>Promise<unknown>)=>{setBusy(true);setError('');setSaved(false);try{await action();await load();setSaved(true);}catch(cause){setError(cause instanceof Error?cause.message:t('Unable to load enquiries.'));}finally{setBusy(false);}};
  const discard=()=>!dirty||window.confirm(t('Discard your unsaved changes?'));
  return <Stack spacing={2}><Typography variant="h5">{t('Practitioner enquiries')}</Typography>{error&&<Alert severity="error">{error}</Alert>}{saved&&<Alert severity="success">{t('Enquiry changes saved.')}</Alert>}
    {central&&<TextField select label={t('Clinic')} value={clinic} disabled={busy} onChange={event=>{if(discard()){setClinic(event.target.value);setPage(1);}}}>{clinics.map(item=><MenuItem key={item.id} value={String(item.id)}>{item.name}</MenuItem>)}</TextField>}
    {data&&<><Paper variant="outlined" sx={{p:2}}><Stack spacing={2}><Typography variant="h6">{t('Enquiry notifications')}</Typography>
    {central?<><TextField type="email" label={t('Enquiry notification email')} value={recipient} disabled={busy} inputProps={{maxLength:254}} onChange={event=>{setRecipient(event.target.value);setDirty(true);}}/><FormControlLabel control={<Checkbox checked={enabled} disabled={busy} onChange={event=>{setEnabled(event.target.checked);setDirty(true);}}/>} label={t('Accept practitioner enquiries')}/><Button variant="contained" disabled={busy||!dirty} onClick={()=>void run(async()=>apiRequest(`${base}/settings`,{method:'PUT',headers:await headers(),body:JSON.stringify({recipient_email:recipient.trim(),enabled,revision:Number(data.settings.revision)})}))}>{t('Save enquiry settings')}</Button></>:<Typography>{t('Manage the notification address in Application administration.')}</Typography>}
    {!data.settings.mail_configured&&<Alert severity="info">{t('Email delivery is disabled. Enquiries are still saved in this inbox.')}</Alert>}
    </Stack></Paper>
    <Button disabled={busy} onClick={()=>{if(discard())void run(async()=>{});}}>{t('Refresh')}</Button>
    {data.items.length===0&&<Typography>{t('No practitioner enquiries yet.')}</Typography>}
    {data.items.map(item=><Paper key={item.id} variant="outlined" sx={{p:2}}><Stack spacing={1}><Typography variant="h6">{item.full_name}</Typography><Typography>{item.discipline} · {item.service_area}</Typography><Typography>{item.email}{item.phone?` · ${item.phone}`:''}</Typography>{item.credentials&&<Typography>{item.credentials}</Typography>}<Typography sx={{whiteSpace:'pre-wrap',overflowWrap:'anywhere'}}>{item.message}</Typography><Typography variant="caption">{item.created_at} UTC · {item.reference}</Typography><Typography>{t('Email notification')}: {t(item.notification_status)}</Typography><TextField select label={t('Enquiry status')} disabled={busy||dirty} value={item.status} onChange={event=>void run(async()=>apiRequest(`${base}/${item.id}`,{method:'PATCH',headers:await headers(),body:JSON.stringify({status:event.target.value,revision:Number(item.revision)})}))}>{['new','contacted','closed'].map(status=><MenuItem value={status} key={status}>{t(status)}</MenuItem>)}</TextField>
    {['pending','failed','disabled'].includes(item.notification_status)&&<Button disabled={busy||dirty} onClick={()=>void run(async()=>apiRequest(`${base}/${item.id}/notify`,{method:'POST',headers:await headers()}))}>{t('Send email notification')}</Button>}
    {['sending','needs_review'].includes(item.notification_status)&&<Alert severity="warning">{t('Email delivery is unconfirmed. Check the recipient mailbox before retrying through support.')}</Alert>}
    </Stack></Paper>)}
    <Stack direction="row" spacing={2}><Button disabled={busy||page===1} onClick={()=>{if(discard())setPage(value=>value-1);}}>{t('Previous')}</Button><Typography>{page}</Typography><Button disabled={busy||!data.has_more} onClick={()=>{if(discard())setPage(value=>value+1);}}>{t('Next')}</Button></Stack></>}
  </Stack>;
}

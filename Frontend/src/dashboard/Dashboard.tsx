import { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, Box, Button, Card, CardActionArea, CardContent, Chip, CircularProgress, FormControl, IconButton, InputLabel, MenuItem, Select, Stack, Typography } from '@mui/material';
import { CalendarCheck, Clock3, MapPin, MoveDown, MoveUp, Pencil, RotateCcw, Save, SlidersHorizontal, X } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { formatDateTime } from '../i18n/format';
import { pagePath, type Workspace } from '../portal/access';
import { apiErrorMessage, normalizeNumericIds } from '../shared/api';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type WidgetId = 'appointments_today'|'awaiting_confirmation'|'onsite_today'|'my_appointments_today'|'my_next_appointment'|'my_onsite_today';
type WidgetSize = 'small'|'medium'|'wide';
type Preference = { id: WidgetId; enabled: boolean; order: number; size: WidgetSize };
type Summary = { workspace: Workspace; timezone: string; as_of: string; widgets: Partial<Record<WidgetId, boolean>>; metrics: { appointments_today: number; awaiting_confirmation: number; onsite_today: number; next_appointment: null | { id: number; starts_at: string; delivery_mode: 'clinic'|'mobile'; service_name: string; client_name: string; timezone: string } } };
type Preferences = { version: 1; workspace: Workspace; widgets: Preference[] };
type Definition = { title: string; description: string; metric: 'appointments_today'|'awaiting_confirmation'|'onsite_today'|'next_appointment'; icon: typeof CalendarCheck };

const definitions: Record<WidgetId, Definition> = {
  appointments_today: { title: "Today's appointments", description: 'All active appointments scheduled today.', metric: 'appointments_today', icon: CalendarCheck },
  awaiting_confirmation: { title: 'Awaiting confirmation', description: 'Requested appointments that still need confirmation.', metric: 'awaiting_confirmation', icon: Clock3 },
  onsite_today: { title: "Today's On-Site visits", description: 'Appointments taking place at a client location.', metric: 'onsite_today', icon: MapPin },
  my_appointments_today: { title: 'My appointments today', description: 'Your active appointments scheduled today.', metric: 'appointments_today', icon: CalendarCheck },
  my_next_appointment: { title: 'My next appointment', description: 'Your next active appointment.', metric: 'next_appointment', icon: Clock3 },
  my_onsite_today: { title: 'My On-Site visits today', description: 'Your visits taking place at a client location.', metric: 'onsite_today', icon: MapPin },
};

export function Dashboard({ workspace }: { workspace: Workspace }) {
  const { t, i18n } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [summary,setSummary]=useState<Summary|null>(null);
  const [preferences,setPreferences]=useState<Preference[]>([]);
  const [draft,setDraft]=useState<Preference[]>([]);
  const [editing,setEditing]=useState(false);
  const [busy,setBusy]=useState(true);
  const [saving,setSaving]=useState(false);
  const [error,setError]=useState('');
  const request=useCallback(async(path:string,init:RequestInit={})=>{
    const token=await getAccessToken();const response=await fetch(`${api}${path}`,{...init,headers:{Authorization:`Bearer ${token}`,'Content-Type':'application/json',...init.headers}});const text=await response.text();let body;
    try{body=JSON.parse(text);}catch{throw new Error(t('The server returned an unreadable response (HTTP {{status}}). Try again or contact the administrator.',{status:response.status}));}
    if(!response.ok)throw new Error(apiErrorMessage(body,response.status,t('Request failed (HTTP {{status}}).',{status:response.status})));return normalizeNumericIds(body.data);
  },[getAccessToken,t]);
  const load=useCallback(async()=>{
    setBusy(true);setError('');try{const [summaryData,preferenceData]=await Promise.all([request(`/dashboard?workspace=${workspace}`),request(`/dashboard/preferences?workspace=${workspace}`)]);setSummary(summaryData);setPreferences(preferenceData.widgets);setDraft(preferenceData.widgets);}catch(cause){setError(cause instanceof Error?cause.message:t('Unable to load dashboard.'));}finally{setBusy(false);}
  },[request,t,workspace]);
  useEffect(()=>{void load();},[load]);
  const ordered=useMemo(()=>[...(editing?draft:preferences)].sort((a,b)=>a.order-b.order),[draft,editing,preferences]);
  const move=(index:number,change:number)=>setDraft(current=>{const sorted=[...current].sort((a,b)=>a.order-b.order);const target=index+change;if(target<0||target>=sorted.length)return current;[sorted[index],sorted[target]]=[sorted[target],sorted[index]];return sorted.map((item,order)=>({...item,order}));});
  const update=(id:WidgetId,values:Partial<Preference>)=>setDraft(current=>current.map(item=>item.id===id?{...item,...values}:item));
  const save=async()=>{setSaving(true);setError('');try{const data:Preferences=await request(`/dashboard/preferences?workspace=${workspace}`,{method:'PUT',body:JSON.stringify({version:1,widgets:draft})});setPreferences(data.widgets);setDraft(data.widgets);setEditing(false);}catch(cause){setError(cause instanceof Error?cause.message:t('Unable to save dashboard.'));}finally{setSaving(false);}};
  const reset=async()=>{setSaving(true);setError('');try{const data:Preferences=await request(`/dashboard/preferences?workspace=${workspace}`,{method:'DELETE'});setPreferences(data.widgets);setDraft(data.widgets);setEditing(false);}catch(cause){setError(cause instanceof Error?cause.message:t('Unable to reset dashboard.'));}finally{setSaving(false);}};
  if(busy)return <Stack alignItems="center" py={8}><CircularProgress aria-label={t('Loading dashboard')} /></Stack>;
  if(error&&!summary)return <Alert severity="error" action={<Button onClick={()=>void load()}>{t('Try again')}</Button>}>{error}</Alert>;
  return <Stack spacing={3}>
    <Stack direction={{xs:'column',sm:'row'}} justifyContent="space-between" alignItems={{xs:'stretch',sm:'center'}} gap={2}>
      <Box><Typography variant="h5">{t(workspace==='practitioner'?'Your day at a glance':'Clinic activity at a glance')}</Typography><Typography color="text.secondary">{t('Dashboard information is live and limited to what your role may access.')}</Typography></Box>
      {!editing?<Button variant="outlined" startIcon={<SlidersHorizontal size={18}/>} onClick={()=>{setDraft(preferences);setEditing(true);}}>{t('Customize dashboard')}</Button>:<Stack direction="row" gap={1}><Button startIcon={<X size={18}/>} disabled={saving} onClick={()=>{setDraft(preferences);setEditing(false);}}>{t('Cancel')}</Button><Button variant="contained" startIcon={<Save size={18}/>} disabled={saving} onClick={()=>void save()}>{t(saving?'Saving…':'Save layout')}</Button></Stack>}
    </Stack>
    {error&&<Alert severity="error" onClose={()=>setError('')}>{error}</Alert>}
    {editing&&<Alert severity="info" icon={<Pencil size={20}/>}>{t('Use the controls on each card to show, hide, resize, or reorder it. Changes apply only to this workspace and your account.')}</Alert>}
    {ordered.length===0?<Alert severity="info">{t('There are no dashboard widgets available for your current role yet.')}</Alert>:<Box sx={{display:'grid',gridTemplateColumns:'repeat(12, minmax(0, 1fr))',gap:2}}>
      {ordered.map((item,index)=>{
        const definition=definitions[item.id];const Icon=definition.icon;const span=item.size==='wide'?12:item.size==='medium'?6:4;const destination=pagePath(workspace,'appointments');const next=summary?.metrics.next_appointment;const content=definition.metric==='next_appointment'
          ? next?<><Typography variant="h6" fontWeight={750}>{formatDateTime(`${next.starts_at.replace(' ','T')}Z`,i18n.resolvedLanguage,{timeZone:next.timezone,dateStyle:'medium',timeStyle:'short'})}</Typography><Typography>{next.client_name} · {next.service_name}</Typography>{next.delivery_mode==='mobile'&&<Chip sx={{mt:1}} size="small" color="info" label={t('On-Site')}/>}</>:<Typography variant="h3">0</Typography>
          :<Typography variant="h3">{summary?.metrics[definition.metric]??0}</Typography>;
        if(!item.enabled&&!editing)return null;
        return <Card key={item.id} variant="outlined" sx={{gridColumn:{xs:'span 12',sm:`span ${Math.max(span,6)}`,lg:`span ${span}`},opacity:item.enabled?1:.55,minHeight:190,display:'flex',flexDirection:'column'}}>
          {editing?<CardContent sx={{display:'flex',flexDirection:'column',height:'100%',gap:1.5}}><Stack direction="row" justifyContent="space-between"><Icon size={24}/><Chip size="small" label={t(item.enabled?'Visible':'Hidden')} color={item.enabled?'success':'default'}/></Stack><Typography variant="h6">{t(definition.title)}</Typography><Typography color="text.secondary" sx={{flexGrow:1}}>{t(definition.description)}</Typography><Stack direction={{xs:'column',sm:'row'}} gap={1} alignItems={{sm:'center'}}><Button size="small" variant="outlined" onClick={()=>update(item.id,{enabled:!item.enabled})}>{t(item.enabled?'Hide':'Show')}</Button><FormControl size="small" sx={{minWidth:120}}><InputLabel>{t('Card size')}</InputLabel><Select label={t('Card size')} value={item.size} onChange={event=>update(item.id,{size:event.target.value as WidgetSize})}><MenuItem value="small">{t('Small')}</MenuItem><MenuItem value="medium">{t('Medium')}</MenuItem><MenuItem value="wide">{t('Wide')}</MenuItem></Select></FormControl><Box sx={{ml:{sm:'auto'}}}><IconButton aria-label={t('Move {{name}} earlier',{name:t(definition.title)})} disabled={index===0} onClick={()=>move(index,-1)}><MoveUp size={19}/></IconButton><IconButton aria-label={t('Move {{name}} later',{name:t(definition.title)})} disabled={index===ordered.length-1} onClick={()=>move(index,1)}><MoveDown size={19}/></IconButton></Box></Stack></CardContent>
          :<CardActionArea component={Link} to={destination} sx={{height:'100%',display:'flex',alignItems:'stretch'}}><CardContent sx={{width:'100%'}}><Stack direction="row" justifyContent="space-between" alignItems="flex-start"><Box><Typography variant="overline" color="primary.main" fontWeight={800}>{t(definition.title)}</Typography>{content}</Box><Icon size={28}/></Stack><Typography color="text.secondary" mt={1}>{t(definition.description)}</Typography><Typography color="primary.main" fontWeight={700} mt={2}>{t('View appointments')} →</Typography></CardContent></CardActionArea>}
        </Card>;
      })}
    </Box>}
    {editing&&<Box><Button color="inherit" startIcon={<RotateCcw size={18}/>} disabled={saving} onClick={()=>void reset()}>{t('Reset to default layout')}</Button></Box>}
  </Stack>;
}

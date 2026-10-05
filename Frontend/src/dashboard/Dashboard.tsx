import { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, Box, Button, Card, CardActionArea, CardContent, Chip, CircularProgress, FormControl, IconButton, InputLabel, MenuItem, Select, Stack, Typography } from '@mui/material';
import { CalendarCheck, Clock3, MailCheck, MapPin, MoveDown, MoveUp, Pencil, RefreshCw, RotateCcw, Save, SlidersHorizontal, X } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { formatDateTime } from '../i18n/format';
import { pagePath, type Workspace } from '../portal/access';
import { apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { NotificationHealthBanner, type NotificationHealth } from '../shared/NotificationHealthBanner';
import { PractitionerTravelCard } from './PractitionerTravelCard';
import { PractitionerToday } from './PractitionerToday';
import { PractitionerVisitSummary } from './PractitionerVisitSummary';
import { QualificationReminder } from './QualificationReminder';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type WidgetSize = 'small'|'medium'|'wide';
type Preference = { id: string; enabled: boolean; order: number; size: WidgetSize };
type NextAppointment = { id: number; starts_at: string; delivery_mode: 'clinic'|'mobile'; service_name: string; client_name: string; timezone: string };
type NotificationCounts = { sent: number; queued: number; failed: number; needs_review: number };
type NotificationSummary = Record<'today'|'last7', Record<'email'|'sms', NotificationCounts>> & { needs_review_total: number; health?: NotificationHealth };
type Definition = { id: string; renderer: 'metric'|'next_appointment'|'notification_summary'; title: { en:string; fr:string }; description: { en:string; fr:string }; icon: 'calendar-check'|'clock'|'map-pin'|'mail-check'; destination: { page:'appointments'|'notifications' }; sizes:WidgetSize[] };
type TimeOffFollowUps = { count: number; appointments: { id: number; starts_at: string; status: string }[] };
type Summary = { workspace: Workspace; timezone: string; as_of: string; definitions: Definition[]; values: Record<string,number|NextAppointment|NotificationSummary|null>; time_off_follow_ups?: TimeOffFollowUps };
type Preferences = { version: 1; workspace: Workspace; widgets: Preference[] };
const icons={ 'calendar-check':CalendarCheck,clock:Clock3,'map-pin':MapPin,'mail-check':MailCheck } as const;

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
  const update=(id:string,values:Partial<Preference>)=>setDraft(current=>current.map(item=>item.id===id?{...item,...values}:item));
  const save=async()=>{setSaving(true);setError('');try{const data:Preferences=await request(`/dashboard/preferences?workspace=${workspace}`,{method:'PUT',body:JSON.stringify({version:1,widgets:draft})});setPreferences(data.widgets);setDraft(data.widgets);setEditing(false);}catch(cause){setError(cause instanceof Error?cause.message:t('Unable to save dashboard.'));}finally{setSaving(false);}};
  const reset=async()=>{setSaving(true);setError('');try{const data:Preferences=await request(`/dashboard/preferences?workspace=${workspace}`,{method:'DELETE'});setPreferences(data.widgets);setDraft(data.widgets);setEditing(false);}catch(cause){setError(cause instanceof Error?cause.message:t('Unable to reset dashboard.'));}finally{setSaving(false);}};
  if(busy)return <Stack alignItems="center" py={8}><CircularProgress aria-label={t('Loading dashboard')} /></Stack>;
  if(error&&!summary)return <Alert severity="error" action={<Button onClick={()=>void load()}>{t('Try again')}</Button>}>{error}</Alert>;
  return <Stack spacing={3}>
    <Box><Button component={Link} to={pagePath(workspace, 'appointments')} state={{ startBooking: true }} variant="contained">{t('Book appointment')}</Button></Box>
    <Stack direction={{xs:'column',sm:'row'}} justifyContent="space-between" alignItems={{xs:'stretch',sm:'center'}} gap={2}>
      <Box><Typography variant="h5">{t(workspace==='practitioner'?'Your day at a glance':'Clinic activity at a glance')}</Typography><Typography color="text.secondary">{t('Dashboard information is live and limited to what your role may access.')}</Typography></Box>
      {!editing?<Stack direction="row" gap={1}><Button startIcon={<RefreshCw size={18}/>} onClick={()=>void load()}>{t('Refresh')}</Button><Button variant="outlined" startIcon={<SlidersHorizontal size={18}/>} onClick={()=>{setDraft(preferences);setEditing(true);}}>{t('Customize dashboard')}</Button></Stack>:<Stack direction="row" gap={1}><Button startIcon={<X size={18}/>} disabled={saving} onClick={()=>{setDraft(preferences);setEditing(false);}}>{t('Cancel')}</Button><Button variant="contained" startIcon={<Save size={18}/>} disabled={saving} onClick={()=>void save()}>{t(saving?'Saving…':'Save layout')}</Button></Stack>}
    </Stack>
    {error&&<Alert severity="error" onClose={()=>setError('')}>{error}</Alert>}
    {workspace==='practitioner' && (summary?.time_off_follow_ups?.count??0)>0 && <Alert severity="warning">
      <Typography fontWeight={750}>{t('{{count}} upcoming appointments overlap your time off and need follow-up.',{count:summary?.time_off_follow_ups?.count??0})}</Typography>
      <Typography variant="body2">{t('These appointments have not been moved or canceled. Review each booking and contact the affected client as needed.')}</Typography>
      <Stack direction="row" flexWrap="wrap" gap={1} mt={1}>
        {summary?.time_off_follow_ups?.appointments.map(item=><Button key={item.id} component={Link} to={`${pagePath('practitioner','appointments')}?appointment_id=${item.id}`} size="small" variant="outlined" color="inherit">{t('Review appointment #{{id}}',{id:item.id})}</Button>)}
        {(summary?.time_off_follow_ups?.count??0)>5 && <Button component={Link} to={pagePath('practitioner','calendar')} size="small" color="inherit">{t('View all time off')}</Button>}
      </Stack>
    </Alert>}
    {workspace==='practitioner' && <QualificationReminder />}
    {workspace==='practitioner' && <PractitionerTravelCard />}
    {workspace==='practitioner' && <PractitionerToday />}
    {workspace==='practitioner' && <PractitionerVisitSummary />}
    {editing&&<Alert severity="info" icon={<Pencil size={20}/>}>{t('Use the controls on each card to show, hide, resize, or reorder it. Changes apply only to this workspace and your account.')}</Alert>}
    {ordered.length===0?<Alert severity="info">{t('There are no dashboard widgets available for your current role yet.')}</Alert>:<Box sx={{display:'grid',gridTemplateColumns:'repeat(12, minmax(0, 1fr))',gap:2}}>
      {ordered.map((item,index)=>{
        const definition=summary?.definitions.find(value=>value.id===item.id);if(!definition)return null;const Icon=icons[definition.icon];const language=i18n.resolvedLanguage?.startsWith('fr')?'fr':'en';const title=definition.title[language];const description=definition.description[language];const span=item.size==='wide'?12:item.size==='medium'?6:4;const destination=pagePath(workspace,definition.destination.page);const value=summary?.values[item.id];const next=definition.renderer==='next_appointment'?value as NextAppointment|null:null;const notificationCounts=definition.renderer==='notification_summary'?value as NotificationSummary|null:null;const content=definition.renderer==='next_appointment'
          ? next?<><Typography variant="h6" fontWeight={750}>{formatDateTime(`${next.starts_at.replace(' ','T')}Z`,i18n.resolvedLanguage,{timeZone:next.timezone,dateStyle:'medium',timeStyle:'short'})}</Typography><Typography>{next.client_name} · {next.service_name}</Typography>{next.delivery_mode==='mobile'&&<Chip sx={{mt:1}} size="small" color="info" label={t('On-Site')}/>}</>:<Typography variant="h3">0</Typography>
          :definition.renderer==='notification_summary'
          ? <Box sx={{display:'grid',gridTemplateColumns:{xs:'repeat(2, minmax(0, 1fr))',md:'repeat(4, minmax(0, 1fr))'},gap:2,mt:1}}>{(['today','last7'] as const).flatMap(period=>(['email','sms'] as const).map(channel=>{const counts=notificationCounts?.[period]?.[channel];return <Box key={`${period}-${channel}`}><Typography fontWeight={750}>{t(period==='today'?'Today':'Last 7 days')} · {channel==='email'?t('Email'):'SMS'}</Typography><Typography variant="body2">{t('Accepted by provider')}: {counts?.sent??0}</Typography><Typography variant="body2">{t('Queued')}: {counts?.queued??0}</Typography><Typography variant="body2">{t('Failed')}: {counts?.failed??0}</Typography><Typography variant="body2" color={(counts?.needs_review??0)>0?'warning.main':'text.secondary'}>{t('Needs review')}: {counts?.needs_review??0}</Typography></Box>}))}</Box>
          :<Typography variant="h3">{typeof value==='number'?value:0}</Typography>;
        if(!item.enabled&&!editing)return null;
        return <Card key={item.id} variant="outlined" sx={{gridColumn:{xs:'span 12',sm:`span ${Math.max(span,6)}`,lg:`span ${span}`},opacity:item.enabled?1:.55,minHeight:190,display:'flex',flexDirection:'column'}}>
          {editing?<CardContent sx={{display:'flex',flexDirection:'column',height:'100%',gap:1.5}}><Stack direction="row" justifyContent="space-between"><Icon size={24}/><Chip size="small" label={t(item.enabled?'Visible':'Hidden')} color={item.enabled?'success':'default'}/></Stack><Typography variant="h6">{title}</Typography><Typography color="text.secondary" sx={{flexGrow:1}}>{description}</Typography><Stack direction={{xs:'column',sm:'row'}} gap={1} alignItems={{sm:'center'}}><Button size="small" variant="outlined" onClick={()=>update(item.id,{enabled:!item.enabled})}>{t(item.enabled?'Hide':'Show')}</Button><FormControl size="small" sx={{minWidth:120}}><InputLabel>{t('Card size')}</InputLabel><Select label={t('Card size')} value={item.size} onChange={event=>update(item.id,{size:event.target.value as WidgetSize})}>{definition.sizes.map(size=><MenuItem key={size} value={size}>{t(size==='small'?'Small':size==='medium'?'Medium':'Wide')}</MenuItem>)}</Select></FormControl><Box sx={{ml:{sm:'auto'}}}><IconButton aria-label={t('Move {{name}} earlier',{name:title})} disabled={index===0} onClick={()=>move(index,-1)}><MoveUp size={19}/></IconButton><IconButton aria-label={t('Move {{name}} later',{name:title})} disabled={index===ordered.length-1} onClick={()=>move(index,1)}><MoveDown size={19}/></IconButton></Box></Stack></CardContent>
          :definition.renderer==='notification_summary'?<CardContent sx={{width:'100%'}}><Stack direction="row" justifyContent="space-between" alignItems="flex-start"><Typography variant="overline" color="primary.main" fontWeight={800}>{title}</Typography><Icon size={28}/></Stack><Box sx={{mt:1}}><NotificationHealthBanner health={notificationCounts?.health} /></Box><Alert severity={(notificationCounts?.needs_review_total??0)>0?'warning':'success'} sx={{mt:1}} action={(notificationCounts?.needs_review_total??0)>0?<Button component={Link} to={`${destination}?status=needs_review`} color="inherit" size="small">{t('Review items')}</Button>:undefined}>{t('{{count}} notifications need review across all dates.',{count:notificationCounts?.needs_review_total??0})}</Alert>{content}<Typography color="text.secondary" mt={2}>{description}</Typography><Button component={Link} to={destination} sx={{mt:1}}>{t('View notification status')} →</Button></CardContent>
          :<CardActionArea component={Link} to={destination} sx={{height:'100%',display:'flex',alignItems:'stretch'}}><CardContent sx={{width:'100%'}}><Stack direction="row" justifyContent="space-between" alignItems="flex-start"><Box sx={{minWidth:0,flex:1}}><Typography variant="overline" color="primary.main" fontWeight={800}>{title}</Typography>{content}</Box><Icon size={28}/></Stack><Typography color="text.secondary" mt={1}>{description}</Typography><Typography color="primary.main" fontWeight={700} mt={2}>{t('View appointments')} →</Typography></CardContent></CardActionArea>}
        </Card>;
      })}
    </Box>}
    {editing&&<Box><Button color="inherit" startIcon={<RotateCcw size={18}/>} disabled={saving} onClick={()=>void reset()}>{t('Reset to default layout')}</Button></Box>}
  </Stack>;
}

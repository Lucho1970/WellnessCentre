import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { Button, Checkbox, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, MenuItem, Paper, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { ApiError, apiRequest } from '../shared/api';
import { Alert, TextField, useFormValidation, withFormValidation } from '../shared/FormValidation';
import { useUnsavedForm } from '../shared/UnsavedChanges';
import { ProviderEvidenceAdmin } from './ProviderEvidenceAdmin';

type Settings={revision:number;email_enabled:boolean;sms_enabled:boolean;mail_from:string;reply_to:string;alert_email:string;sms_from_did:string;cad_micros_per_segment:number|null;fixed_cad_micros:number;tariff_valid_until:string|null;opt_out_checked_at:string|null};
type Notice={id:number;kind:string;recipient_kind:string;recipient_address?:string|null;status:string;attempt_count:number;created_at:string};
type Delivery={id:number;segments:number;estimated_cents:number;actual_cents:number|null;status:string;provider_message_id:string|null;period_start:string};
type Data={provider_evidence_available?:boolean;settings:Settings;gates:{automated_email:boolean;sms_budgets:boolean};suppressed_count:number;health:{needs_review:number;overdue:number;stale_leases:number};notices:Notice[];deliveries:Delivery[];held_sms:{id:number;last_error:string;scheduled_at:string}[];members:{id:number;display_name:string;reply_email:string|null;reply_active:boolean;reply_version:number}[];events:{action:string;reason:string;created_at:string}[]};
type Draft={kind:'settings'|'suppression'|'delivery'|'notice'|'reply';values:Record<string,string>;flags:Record<string,boolean>;target?:Notice|Delivery};
type Operation={path:string;body:Record<string,unknown>};
const noticeLabels:Record<string,string>={application:'Application update',trial_ending:'Trial ending',trial_expired:'Trial ended',budget:'SMS budget alert'};
const statusLabels:Record<string,string>={queued:'Queued',sending:'Sending',sent:'Sent',failed:'Retry pending',canceled:'Canceled',needs_review:'Needs review',resolved:'Resolved',reserved:'Reserved',submitted:'Awaiting reconciliation',settled:'Confirmed',released:'Released'};
const holdReasons:Record<string,string>={
  sms_budget_exhausted:'The monthly SMS allowance is exhausted.',
  sms_not_enrolled:'SMS funding is not active for this practitioner.',sms_not_funded:'SMS funding is not active for this practitioner.',sms_pool_inactive:'SMS funding is not active for this practitioner.',sms_membership_ineligible:'SMS funding is not active for this practitioner.',
  sms_not_entitled:'The practitioner subscription does not currently include SMS.',
  sms_opted_out:'This destination has opted out of SMS.',
  sms_overhead_not_reviewed:'Reserve or confirm this month’s shared number costs in Billing.',
  sms_configuration_not_ready:'Review the clinic sender, rate expiry and provider opt-out check.',
  sms_reservation_expired:'This SMS reservation or reminder is no longer current.',
  sms_already_submitted:'A provider attempt already exists. Review it before taking further action.',
  sms_sender_changed:'The sender or message changed. Reconcile the previous reservation.',sms_delivery_changed:'The sender or message changed. Reconcile the previous reservation.',
};
function micros(value:string):number { if(!/^\d+(\.\d{1,6})?$/.test(value))throw new Error('Enter a nonnegative CAD amount with at most six decimal places.');const [whole,fraction='']=value.split('.');const result=Number(whole)*1000000+Number(fraction.padEnd(6,'0'));if(!Number.isSafeInteger(result)||result>100000000)throw new Error('Enter a CAD amount within the supported range.');return result; }
function CommunicationsForm({onDirtyChange}:{onDirtyChange?:(dirty:boolean)=>void}) {
  const {t,i18n}=useTranslation();const {getAccessToken,account}=useStaffAuth();const validation=useFormValidation();const guard=useUnsavedForm();
  const [providerDirty,setProviderDirty]=useState(false);
  const [clinics,setClinics]=useState<{id:number;name:string}[]>([]),[clinic,setClinic]=useState(''),[data,setData]=useState<Data|null>(null);
  const [draft,setDraft]=useState<Draft|null>(null),[pending,setPending]=useState<Operation|null>(null),[busy,setBusy]=useState(false),[error,setError]=useState(''),[saved,setSaved]=useState(false);const sequence=useRef(0);
  useEffect(()=>{onDirtyChange?.(guard.dirty||providerDirty);},[guard.dirty,providerDirty,onDirtyChange]);useEffect(()=>()=>onDirtyChange?.(false),[onDirtyChange]);
  const fail=useCallback((cause:unknown)=>{validation.capture(cause);setError(cause instanceof Error?cause.message:t('Unable to load communications.'));if(cause instanceof ApiError&&[401,403,404,409].includes(cause.status)){setData(null);setClinics([]);setDraft(null);setPending(null);guard.markClean();}},[validation.capture,t,guard.markClean]);
  const load=useCallback(async()=>{const version=++sequence.current;setBusy(true);try{const headers={Authorization:`Bearer ${await getAccessToken()}`};const [list,result]=await Promise.all([apiRequest<{items:{id:number;name:string}[]}>('/application/clinics',{headers}),clinic?apiRequest<Data>(`/application/clinics/${clinic}/communications`,{headers}):Promise.resolve(null)]);if(version!==sequence.current)return;setClinics(list.items);setData(result);setError('');if(!clinic&&list.items.length)setClinic(String(list.items[0].id));}catch(cause){if(version===sequence.current)fail(cause);}finally{if(version===sequence.current)setBusy(false);}},[clinic,getAccessToken,fail]);
  const loadRef=useRef(load);loadRef.current=load;
  useEffect(()=>{setData(null);void loadRef.current();return()=>{sequence.current++;};},[clinic,account?.homeAccountId]);
  const open=(kind:Draft['kind'],target?:Notice|Delivery)=>{if(!data)return;validation.clear();setSaved(false);setError('');const s=data.settings;let values:Record<string,string>={reason:''};let flags:Record<string,boolean>={};
    if(kind==='settings'){values={...values,mail_from:s.mail_from,reply_to:s.reply_to,alert_email:s.alert_email,sms_from_did:s.sms_from_did,rate:s.cad_micros_per_segment===null?'':String(Number(s.cad_micros_per_segment)/1000000),fixed:String(Number(s.fixed_cad_micros)/1000000),tariff_valid_until:s.tariff_valid_until??''};flags={email_enabled:s.email_enabled,sms_enabled:s.sms_enabled,senders_verified:false,opt_out_reviewed:false};}
    if(kind==='suppression'){values={...values,number:''};flags={active:true,opt_in_confirmed:false};}
    if(kind==='reply'){values={...values,membership_id:'',email:'',version:'0'};flags={active:true,sharing_confirmed:false};}
    if(kind==='delivery'){const d=target as Delivery;values={...values,outcome:d.status==='settled'?'correction':d.status==='reserved'?'provider_not_sent':'provider_accepted',actual:d.actual_cents===null?'':String(Number(d.actual_cents)/100)};flags={checked_provider_history:false};}
    if(kind==='notice'){values={...values,decision:'resolve'};flags={checked_provider_history:false,provider_not_sent:false};}
    setDraft({kind,values,flags,target});guard.markClean();};
  const change=(key:string,value:string)=>{if(draft){setDraft({...draft,values:{...draft.values,[key]:value}});guard.markDirty();}};
  const flag=(key:string,value:boolean)=>{if(draft){setDraft({...draft,flags:{...draft.flags,[key]:value}});guard.markDirty();}};
  const close=()=>{if(pending||busy)return;if(guard.dirty&&!window.confirm(t('Discard your unsaved changes?')))return;setDraft(null);guard.markClean();};
  const perform=async(operation:Operation)=>{setPending(operation);setBusy(true);setError('');validation.clear();try{await apiRequest(operation.path,{method:'POST',headers:{Authorization:`Bearer ${await getAccessToken()}`,'Content-Type':'application/json'},body:JSON.stringify(operation.body)});}catch(cause){if(cause instanceof ApiError&&cause.status<500)setPending(null);fail(cause);setBusy(false);return;}setPending(null);setDraft(null);setSaved(true);guard.markClean();await load();};
  const submit=(event:FormEvent)=>{event.preventDefault();if(!draft||!data)return;try{const v=draft.values;let path=`/application/clinics/${clinic}/communications`;let body:Record<string,unknown>={request_key:crypto.randomUUID(),reason:v.reason,...draft.flags};
    if(draft.kind==='settings'){path+='/settings';Object.assign(body,{revision:Number(data.settings.revision),mail_from:v.mail_from,reply_to:v.reply_to,alert_email:v.alert_email,sms_from_did:v.sms_from_did,cad_micros_per_segment:v.rate===''?null:micros(v.rate),fixed_cad_micros:micros(v.fixed),tariff_valid_until:v.tariff_valid_until});}
    if(draft.kind==='suppression'){path+='/suppression';body.number=v.number;}
    if(draft.kind==='reply'){path+='/reply-address';Object.assign(body,{membership_id:Number(v.membership_id),email:v.email,version:Number(v.version)});}
    if(draft.kind==='delivery'){const d=draft.target as Delivery;if(!/^\d+(\.\d{1,2})?$/.test(v.actual))throw new Error('Reconciled costs must use whole CAD cents.');const [whole,fraction='']=v.actual.split('.');const amount=(Number(whole)*100+Number(fraction.padEnd(2,'0')))*10000;path+=`/deliveries/${d.id}/reconcile`;Object.assign(body,{outcome:v.outcome,actual_cents:amount/10000,expected_actual_cents:d.actual_cents===null?null:Number(d.actual_cents)});}
    if(draft.kind==='notice'){const n=draft.target as Notice;path+=`/notices/${n.id}/review`;Object.assign(body,{decision:v.decision,attempt_count:Number(n.attempt_count)});}
    void perform({path,body});}catch(cause){setError(t(cause instanceof Error?cause.message:'Check the communications fields.'));}};
  const field=(key:string,label:string,required=true)=><TextField name={({rate:'cad_micros_per_segment',fixed:'fixed_cad_micros',actual:'actual_cents'} as Record<string,string>)[key]??key} label={t(label)} value={draft?.values[key]??''} required={required} disabled={busy||!!pending} onChange={e=>change(key,e.target.value)}/>;
  const check=(key:string,label:string,disabled=false)=><FormControlLabel control={<Checkbox checked={draft?.flags[key]??false} disabled={busy||!!pending||disabled} onChange={e=>flag(key,e.target.checked)}/>} label={t(label)}/>;
  const money=(value:number)=>new Intl.NumberFormat(i18n.language.startsWith('fr')?'fr-CA':'en-CA',{style:'currency',currency:'CAD'}).format(Number(value)/100);
  return <Stack spacing={2}>
    <Typography variant="h5">{t('Communications')}</Typography><Typography>{t('Manage clinic senders, operational email notices and the spending guard for existing staff booking texts. Email follows current recipient preferences independently.')}</Typography>
    {error&&!draft&&<Alert severity="error">{error}</Alert>}{saved&&<Alert severity="success">{t('Communications saved.')}</Alert>}
    <Stack direction={{xs:'column',sm:'row'}} spacing={2}><TextField select label={t('Clinic')} value={clinic} disabled={busy||!!draft||providerDirty} onChange={e=>setClinic(e.target.value)}>{clinics.map(c=><MenuItem key={c.id} value={String(c.id)}>{c.name}</MenuItem>)}</TextField><Button disabled={busy||!!draft||providerDirty} onClick={()=>void load()}>{t('Refresh communications')}</Button></Stack>
    {busy&&<Typography role="status">{t('Loading…')}</Typography>}
    {data&&<>
      <Alert severity="info">{t('Unconfirmed SMS costs remain committed until reconciliation. Fixed number costs are maintained separately in Billing. No number or mailbox is purchased here.')}</Alert>
      <Typography>{t('Notices requiring review: {{review}}. Overdue: {{overdue}}. Stale delivery leases: {{stale}}.',{review:data.health.needs_review??0,overdue:data.health.overdue??0,stale:data.health.stale_leases??0})}</Typography>
      <Typography>{t('Suppressed SMS destinations: {{count}}',{count:data.suppressed_count})}</Typography>
      <Typography>{t('Last provider opt-out review (UTC): {{date}}',{date:data.settings.opt_out_checked_at??t('Not configured')})}</Typography>
      <Stack direction={{xs:'column',sm:'row'}}><Button disabled={busy||!!draft} onClick={()=>open('settings')}>{t('Configure communications')}</Button><Button disabled={busy||!!draft} onClick={()=>open('suppression')}>{t('Manage an SMS opt-out')}</Button><Button disabled={busy||!!draft} onClick={()=>open('reply')}>{t('Set practitioner reply address')}</Button></Stack>
      <Typography variant="h6">{t('Recent operational notices')}</Typography>{data.notices.length===0&&<Typography>{t('No notices yet.')}</Typography>}
      {(data.held_sms??[]).map(item=><Alert severity="warning" key={item.id}>{t('SMS attempt {{id}}',{id:item.id})}: {t(holdReasons[item.last_error.replace('Spending policy: ','')]??'Held by spending or recipient policy. Review current settings, funding, consent and entitlement before sending a new notice.')}</Alert>)}
      {data.notices.map(n=><Paper variant="outlined" key={n.id} sx={{p:2}}><Typography>{t(noticeLabels[n.kind]??n.kind)} · {t(statusLabels[n.status]??n.status)} · {n.created_at} UTC</Typography>{n.status==='needs_review'&&<Button disabled={busy||!!draft} onClick={()=>open('notice',n)}>{t('Review notice {{id}}',{id:n.id})}</Button>}</Paper>)}
      <Typography variant="h6">{t('Recent budgeted SMS attempts')}</Typography>{data.deliveries.map(d=><Paper variant="outlined" key={d.id} sx={{p:2}}><Stack spacing={1}><Typography>{t('SMS attempt {{id}}',{id:d.id})} · {t(statusLabels[d.status]??d.status)} · {d.period_start}</Typography><Typography>{t('Segments: {{segments}}. Reserved estimate: {{estimate}}. Confirmed cost: {{actual}}.',{segments:d.segments,estimate:money(d.estimated_cents),actual:d.actual_cents===null?t('Awaiting reconciliation'):money(d.actual_cents)})}</Typography><Typography sx={{overflowWrap:'anywhere'}}>{t('Provider reference: {{reference}}',{reference:d.provider_message_id??t('Not confirmed')})}</Typography>{d.status!=='released'&&<Button disabled={busy||!!draft} onClick={()=>open('delivery',d)}>{t('Reconcile SMS {{id}}',{id:d.id})}</Button>}</Stack></Paper>)}
      {data.provider_evidence_available&&<ProviderEvidenceAdmin key={clinic} clinic={clinic} onDirtyChange={setProviderDirty} disabled={busy||!!draft}/>}
    </>}
    <Dialog open={!!draft} onClose={busy||pending?undefined:close} fullWidth maxWidth="sm" aria-labelledby="communications-title"><DialogTitle id="communications-title">{t('Review communications change')}</DialogTitle><DialogContent><Stack component="form" id="communications-form" onSubmit={submit} spacing={2} sx={{pt:1}}>
      {error&&<Alert severity="error">{error}</Alert>}{pending&&!busy&&<Alert severity="warning">{t('Save outcome is uncertain. Retry the same communications change before editing.')}</Alert>}
      {draft?.kind==='settings'&&<>
        {check('email_enabled','Enable operational emails and clinic mail routing',!data?.gates.automated_email)}{field('mail_from','Verified clinic sending email',false)}{field('reply_to','Clinic reply address',false)}{field('alert_email','Operator alert email',false)}
        {check('sms_enabled','Enable budgeted staff SMS',!data?.gates.sms_budgets)}{field('sms_from_did','Assigned Canadian sending number (10 digits)',false)}{field('rate','Conservative CAD cost per segment',false)}{field('fixed','Additional CAD cost per message')}{field('tariff_valid_until','Cost estimate expiry (UTC: YYYY-MM-DD HH:mm:ss)',false)}
        <Typography>{t('Include currency conversion, provider fees and taxes in the conservative rate. Unknown or expired rates stop SMS. Rates may be approved for at most 90 days.')}</Typography>
        {check('senders_verified','I verified sender ownership, Graph authorization and the approved SMS number.')}{check('opt_out_reviewed','I synchronized provider opt-outs and consent records. This review is valid for 24 hours.')}
      </>}
      {draft?.kind==='suppression'&&<>{field('number','Destination number (+1 Canadian format)')}{check('active','Block SMS to this number')}{check('opt_in_confirmed','If unblocking, I confirmed a new valid opt-in and provider clearance.')}</>}
      {draft?.kind==='reply'&&<><TextField select name="membership_id" label={t('Practitioner membership')} required value={draft.values.membership_id} disabled={busy||!!pending} onChange={e=>{const member=data?.members.find(m=>m.id===Number(e.target.value));setDraft({...draft,values:{...draft.values,membership_id:e.target.value,email:member?.reply_email??'',version:String(member?.reply_version??0)},flags:{...draft.flags,active:member?.reply_email?Boolean(Number(member.reply_active)):true,sharing_confirmed:false}});guard.markDirty();}}>{(data?.members??[]).map(m=><MenuItem key={m.id} value={String(m.id)}>{m.display_name}</MenuItem>)}</TextField>{field('email','Patient-facing reply email')}{check('active','Use this address for patient replies')}{check('sharing_confirmed','I verified this address and the practitioner explicitly agreed to share it with patients.')}<Typography>{t('Private notification email addresses are never copied automatically. The clinic reply address is used unless an approved active override exists.')}</Typography></>}
      {draft?.kind==='delivery'&&<>
        <TextField select label={t('Provider outcome')} value={draft.values.outcome} disabled={busy||!!pending} onChange={e=>change('outcome',e.target.value)}>{((draft.target as Delivery).status==='settled'?['correction']:(draft.target as Delivery).status==='reserved'?['provider_not_sent']:['provider_accepted','provider_not_sent']).map(value=><MenuItem key={value} value={value}>{t(({correction:'Correct a confirmed cost',provider_not_sent:'Provider confirms no send',provider_accepted:'Provider accepted the SMS'} as Record<string,string>)[value])}</MenuItem>)}</TextField>
        {field('actual','Actual cost in CAD')}{check('checked_provider_history','I checked the exact provider record, original period, conversion and final cost.')}
      </>}
      {draft?.kind==='notice'&&<><TextField label={t('Recorded notice recipient')} value={(draft.target as Notice).recipient_address??t('Not confirmed')} slotProps={{input:{readOnly:true}}}/><TextField select label={t('Notice decision')} value={draft.values.decision} disabled={busy||!!pending} onChange={e=>change('decision',e.target.value)}><MenuItem value="resolve">{t('Resolve without resending')}</MenuItem><MenuItem value="retry">{t('Retry after verified non-delivery')}</MenuItem></TextField>{check('checked_provider_history','I checked provider history and the intended recipient.')}{check('provider_not_sent','Provider history confirms that the email was not sent.')}</>}
      {draft&&field('reason','Reason for change')}
    </Stack></DialogContent><DialogActions><Button disabled={busy||!!pending} onClick={close}>{t('Close')}</Button>{pending&&!busy?<Button onClick={()=>void perform(pending)}>{t('Retry communications change')}</Button>:<Button type="submit" form="communications-form" variant="contained" disabled={busy}>{t('Save communications change')}</Button>}</DialogActions></Dialog>
  </Stack>;
}
export const CommunicationsAdmin=withFormValidation(CommunicationsForm);

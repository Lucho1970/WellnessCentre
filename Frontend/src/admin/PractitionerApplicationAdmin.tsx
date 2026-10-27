import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { Button, Checkbox, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, MenuItem, Paper, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { ApiError, apiRequest } from '../shared/api';
import { Alert, TextField, useFormValidation, withFormValidation } from '../shared/FormValidation';
import { useUnsavedForm } from '../shared/UnsavedChanges';

type Settings={revision:number;enabled:boolean;activation_enabled:boolean;terms_text:string;terms_version:number|null;plan_version_id:number|null;timezone:string};
type Item={id:number;version:number;status:string;given_name:string;family_name:string;email:string;phone:string;discipline:string;credentials:string;service_area:string;services:string;enquiry_reference:string;verification_code:string;approved_location_id:number|null;source_enquiry_id:number|null;allow_existing_membership:boolean;review_message:string;existing_membership_id?:number|null};
type Data={settings:Settings;activation_gate:boolean;items:Item[];plans:{id:number;display_name:string;version:number}[];locations:{id:number;name:string}[];enquiries:{id:number;full_name:string;reference:string}[]};
type Detail={application:Item;submissions:{version:number;details:Record<string,string>;submitted_at:string}[];events:{action:string;reason:string;created_at:string}[]};
type Draft={kind:'settings'|'review';values:Record<string,string>;flags:Record<string,boolean>;item?:Item};
type Operation={path:string;body:Record<string,unknown>};
const statusLabels:Record<string,string>={submitted:'Application submitted',changes_requested:'Changes requested',approved:'Application approved',rejected:'Application not approved',withdrawn:'Application withdrawn',activated:'Account activated'};
function ApplicationsForm({onDirtyChange}:{onDirtyChange?:(dirty:boolean)=>void}) {
  const {t}=useTranslation();const {getAccessToken}=useStaffAuth();const guard=useUnsavedForm();const validation=useFormValidation();
  const [clinics,setClinics]=useState<{id:number;name:string}[]>([]),[clinic,setClinic]=useState(''),[data,setData]=useState<Data|null>(null);
  const [draft,setDraft]=useState<Draft|null>(null),[detail,setDetail]=useState<Detail|null>(null),[pending,setPending]=useState<Operation|null>(null);
  const [busy,setBusy]=useState(false),[error,setError]=useState(''),[saved,setSaved]=useState(false),[blocked,setBlocked]=useState(false);const sequence=useRef(0);
  useEffect(()=>{onDirtyChange?.(guard.dirty);},[guard.dirty,onDirtyChange]);useEffect(()=>()=>onDirtyChange?.(false),[onDirtyChange]);
  const fail=useCallback((cause:unknown)=>{validation.capture(cause);setError(cause instanceof Error?cause.message:t('Unable to load practitioner applications.'));
    if(cause instanceof ApiError&&[401,403,404,409].includes(cause.status)){setData(null);setClinics([]);setDraft(null);setDetail(null);setPending(null);setBlocked(true);guard.markClean();}
  },[validation.capture,t,guard.markClean]);
  const load=useCallback(async()=>{const version=++sequence.current;setBusy(true);
    try{const headers={Authorization:`Bearer ${await getAccessToken()}`};const [list,result]=await Promise.all([apiRequest<{items:{id:number;name:string}[]}>('/application/clinics',{headers}),clinic?apiRequest<Data>(`/application/clinics/${clinic}/practitioner-applications`,{headers}):Promise.resolve(null)]);
      if(version!==sequence.current)return;setClinics(list.items);setData(result);setBlocked(false);setError('');if(!clinic&&list.items.length)setClinic(String(list.items[0].id));
    }catch(cause){if(version===sequence.current)fail(cause);}finally{if(version===sequence.current)setBusy(false);}
  },[clinic,getAccessToken,fail]);
  useEffect(()=>{setData(null);void load();return()=>{sequence.current++;};},[load]);
  const openSettings=()=>{if(!data)return;const s=data.settings;validation.clear();setError('');setSaved(false);setDetail(null);setDraft({kind:'settings',flags:{enabled:s.enabled,activation_enabled:s.activation_enabled,readiness_acknowledged:false},values:{revision:String(s.revision),terms_text:s.terms_text,plan_version_id:s.plan_version_id?String(s.plan_version_id):'',timezone:s.timezone,reason:''}});guard.markClean();};
  const openReview=async(item:Item)=>{setBusy(true);setSaved(false);setError('');validation.clear();
    try{const result=await apiRequest<Detail>(`/application/clinics/${clinic}/practitioner-applications/${item.id}`,{headers:{Authorization:`Bearer ${await getAccessToken()}`}});const current=result.application;setDetail(result);setDraft({kind:'review',item:current,flags:{applicant_verified:false,credentials_reviewed:false,allow_existing_membership:Boolean(Number(current.allow_existing_membership))},values:{decision:'changes_requested',location_id:current.approved_location_id?String(current.approved_location_id):'',source_enquiry_id:current.source_enquiry_id?String(current.source_enquiry_id):'',verification_code:'',review_message:current.review_message,reason:''}});guard.markClean();}
    catch(cause){fail(cause);}finally{setBusy(false);}
  };
  const change=(key:string,value:string)=>{if(draft){setDraft({...draft,values:{...draft.values,[key]:value}});guard.markDirty();}};
  const flag=(key:string,value:boolean)=>{if(draft){setDraft({...draft,flags:{...draft.flags,[key]:value}});guard.markDirty();}};
  const close=()=>{if(pending||busy)return;if(guard.dirty&&!window.confirm(t('Discard your unsaved changes?')))return;setDraft(null);setDetail(null);guard.markClean();validation.clear();};
  const perform=async(operation:Operation)=>{setBusy(true);setPending(operation);setError('');validation.clear();
    try{await apiRequest(operation.path,{method:'POST',headers:{Authorization:`Bearer ${await getAccessToken()}`,'Content-Type':'application/json'},body:JSON.stringify(operation.body)});}
    catch(cause){if(cause instanceof ApiError&&cause.status<500)setPending(null);fail(cause);setBusy(false);return;}
    setPending(null);setDraft(null);setDetail(null);setSaved(true);guard.markClean();await load();
  };
  const submit=(event:FormEvent)=>{event.preventDefault();if(!draft)return;const v=draft.values;const body:Record<string,unknown>={request_key:crypto.randomUUID(),reason:v.reason};let path=`/application/clinics/${clinic}/practitioner-applications`;
    if(draft.kind==='settings'){path+='/settings';Object.assign(body,{revision:Number(v.revision),...draft.flags,terms_text:v.terms_text,plan_version_id:v.plan_version_id?Number(v.plan_version_id):null,timezone:v.timezone});}
    else{path+=`/${draft.item!.id}/review`;Object.assign(body,{version:Number(draft.item!.version),decision:v.decision,review_message:v.review_message});if(v.decision==='approved')Object.assign(body,{verification_code:v.verification_code,location_id:Number(v.location_id),source_enquiry_id:v.source_enquiry_id?Number(v.source_enquiry_id):null,...draft.flags});}
    void perform({path,body});
  };
  const field=(key:string,label:string,options:{multiline?:boolean;required?:boolean}={})=><TextField name={key} label={t(label)} value={draft?.values[key]??''} required disabled={busy||!!pending} {...options} minRows={options.multiline?3:undefined} onChange={event=>change(key,event.target.value)}/>;
  const checkbox=(key:string,label:string,disabled=false)=><FormControlLabel control={<Checkbox checked={draft?.flags[key]??false} disabled={busy||!!pending||disabled} onChange={event=>flag(key,event.target.checked)}/>} label={t(label)}/>;
  return <Stack spacing={2}>
    <Typography variant="h5">{t('Practitioner applications')}</Typography><Typography>{t('Review joining applications without publishing profiles or granting clinical access. Applicants activate approved accounts separately when the clinic is ready.')}</Typography>
    {error&&!draft&&<Alert severity="error">{error}</Alert>}{saved&&<Alert severity="success">{t('Application changes saved.')}</Alert>}
    <Stack direction={{xs:'column',sm:'row'}} spacing={2}><TextField select label={t('Clinic')} value={clinic} disabled={busy||!!draft} onChange={e=>{setData(null);setClinic(e.target.value);}}>{clinics.map(item=><MenuItem key={item.id} value={String(item.id)}>{item.name}</MenuItem>)}</TextField><Button disabled={busy} onClick={()=>{if(guard.dirty&&!window.confirm(t('Discard your unsaved changes?')))return;setDraft(null);setPending(null);guard.markClean();void load();}}>{t('Refresh applications')}</Button></Stack>
    {busy&&<Typography role="status">{t('Loading…')}</Typography>}
    {data&&!blocked&&<>
      <Alert severity="info">{t(data.settings.enabled?'Applications are open.':'Applications are closed.')}{' '}{t(data.settings.activation_enabled&&data.activation_gate?'Account activation is enabled.':'Account activation is held for rollout acceptance.')}</Alert>
      <Button disabled={busy||!!draft} onClick={openSettings}>{t('Configure practitioner applications')}</Button>
      {data.items.length===0&&<Typography>{t('No practitioner applications have been submitted.')}</Typography>}
      {data.items.map(item=><Paper key={item.id} variant="outlined" sx={{p:2}}><Stack spacing={1}><Typography variant="h6">{item.given_name} {item.family_name}</Typography><Typography>{item.email} · {item.discipline} · {t(statusLabels[item.status]??item.status)}</Typography><Typography>{item.service_area}</Typography><Button disabled={busy||!!draft} onClick={()=>void openReview(item)}>{t('Review application {{id}}',{id:item.id})}</Button></Stack></Paper>)}
    </>}
    <Dialog open={!!draft} onClose={busy||pending?undefined:close} fullWidth maxWidth="md" aria-labelledby="application-admin-title"><DialogTitle id="application-admin-title">{t(draft?.kind==='settings'?'Configure practitioner applications':'Review practitioner application')}</DialogTitle><DialogContent><Stack component="form" id="application-admin-form" onSubmit={submit} spacing={2} sx={{pt:1}}>
      {error&&<Alert severity="error">{error}</Alert>}{pending&&!busy&&<Alert severity="warning">{t('The save outcome is uncertain. Retry the same application change before editing.')}</Alert>}
      {draft?.kind==='settings'&&<>
        {checkbox('enabled','Accept practitioner applications')}
        <TextField select name="plan_version_id" label={t('Approved beta plan')} value={draft.values.plan_version_id} disabled={busy||!!pending} onChange={e=>change('plan_version_id',e.target.value)}><MenuItem value="">{t('Not configured')}</MenuItem>{data?.plans.map(plan=><MenuItem key={plan.id} value={String(plan.id)}>{plan.display_name} v{plan.version}</MenuItem>)}</TextField>
        {field('timezone','Trial timezone')}{field('terms_text','Reviewed joining terms',{required:false,multiline:true})}
        <Typography>{t('Publish the approved joining terms before opening applications. The system does not invent legal terms or a paid price.')}</Typography>
        {checkbox('activation_enabled','Allow approved applicants to activate accounts',!data?.activation_gate)}
        {data?.activation_gate?checkbox('readiness_acknowledged','I have confirmed the independent-practitioner privacy, identity and operational rollout checks.'): <Alert severity="info">{t('Activation remains disabled until the separate rollout gate and durable practitioner sign-in are enabled.')}</Alert>}
      </>}
      {draft?.kind==='review'&&draft.item&&<>
        <Typography variant="h6">{draft.item.given_name} {draft.item.family_name} · {t(statusLabels[draft.item.status]??draft.item.status)}</Typography>
        <Typography>{draft.item.email} · {draft.item.phone} · {draft.item.discipline} · {draft.item.service_area}</Typography><Typography sx={{whiteSpace:'pre-wrap'}}>{draft.item.credentials}</Typography><Typography sx={{whiteSpace:'pre-wrap'}}>{draft.item.services}</Typography>
        {draft.item.enquiry_reference&&<Typography>{t('Prior enquiry reference: {{reference}}',{reference:draft.item.enquiry_reference})}</Typography>}
        <TextField label={t('Applicant verification code')} value={draft.item.verification_code} slotProps={{input:{readOnly:true}}}/>
        <Typography>{t('Confirm the applicant and this code through a trusted contact method. Contact email is not an identity binding. Declared credentials do not publish or verify a professional qualification automatically.')}</Typography>
        {!['activated','withdrawn'].includes(draft.item.status)&&<>
          <TextField select name="decision" label={t('Review decision')} value={draft.values.decision} disabled={busy||!!pending} onChange={e=>change('decision',e.target.value)}>{['changes_requested','approved','rejected'].map(value=><MenuItem key={value} value={value}>{t(statusLabels[value])}</MenuItem>)}</TextField>
          {field('review_message','Message shown to the applicant',{multiline:true})}
          {draft.values.decision==='approved'&&<>
            {field('verification_code','Independently confirmed code')}{checkbox('applicant_verified','I have independently confirmed the intended applicant.')}{checkbox('credentials_reviewed','I have reviewed the application credentials and eligibility requirements.')}{checkbox('allow_existing_membership','Allow reuse of this identity’s existing active clinic membership, if present.')}
            <TextField select name="location_id" label={t('Initial clinic location')} required value={draft.values.location_id} disabled={busy||!!pending} onChange={e=>change('location_id',e.target.value)}>{data?.locations.map(location=><MenuItem key={location.id} value={String(location.id)}>{location.name}</MenuItem>)}</TextField>
            <TextField select name="source_enquiry_id" label={t('Related joining enquiry (optional)')} value={draft.values.source_enquiry_id} disabled={busy||!!pending} onChange={e=>change('source_enquiry_id',e.target.value)}><MenuItem value="">{t('None')}</MenuItem>{data?.enquiries.map(enquiry=><MenuItem key={enquiry.id} value={String(enquiry.id)}>{enquiry.full_name} · {enquiry.reference}</MenuItem>)}</TextField>
          </>}
        </>}
        {detail?.submissions.map(submission=><details key={submission.version}><summary>{t('Submitted version {{version}}',{version:submission.version})} · {submission.submitted_at} UTC</summary><Typography sx={{whiteSpace:'pre-wrap'}}>{submission.details.credentials}{'\n'}{submission.details.services}</Typography></details>)}
        {detail?.events.map((entry,index)=><Typography variant="body2" key={index}>{entry.created_at} UTC · {entry.reason}</Typography>)}
      </>}
      {draft&&!(draft.kind==='review'&&['activated','withdrawn'].includes(draft.item?.status??''))&&field('reason','Reason for change')}
    </Stack></DialogContent><DialogActions><Button disabled={busy||!!pending} onClick={close}>{t('Close')}</Button>{pending&&!busy?<Button onClick={()=>void perform(pending)}>{t('Retry application change')}</Button>:!(draft?.kind==='review'&&['activated','withdrawn'].includes(draft.item?.status??''))&&<Button variant="contained" form="application-admin-form" type="submit" disabled={busy||blocked}>{t('Save application review')}</Button>}</DialogActions></Dialog>
  </Stack>;
}
export const PractitionerApplicationAdmin=withFormValidation(ApplicationsForm);

import { useCallback, useEffect, useRef, useState, type ChangeEvent, type FormEvent } from 'react';
import { Button, Checkbox, FormControlLabel, Paper, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { ApiError, apiRequest } from '../shared/api';
import { Alert, TextField, useFormValidation, withFormValidation } from '../shared/FormValidation';
import { useUnsavedForm } from '../shared/UnsavedChanges';

type Report={id:number;status:string;statement_reference:string;preview_hash:string;can_apply:boolean;fx:{rate_micros:number|null;date:string|null;reference:string};rows:{line:number;type:string;reference:string;sender_did:string;currency:string;amount_micros:number|null;status:string;actual_cents:number|null}[]};
type Operation={path:string;body:Record<string,unknown>;kind:'preview'|'apply'|'clear'};
const labels:Record<string,string>={ready:'Ready to apply',already_recorded:'Already recorded',unmatched:'No exact delivery match',conflict:'Conflicting evidence'};
function EvidenceForm({clinic,onDirtyChange,disabled=false}:{clinic:string;onDirtyChange?:(dirty:boolean)=>void;disabled?:boolean}){
  const {t,i18n}=useTranslation();const {getAccessToken}=useStaffAuth();const validation=useFormValidation();const guard=useUnsavedForm();
  const [items,setItems]=useState<{id:number;statement_reference:string;status:string}[]>([]),[report,setReport]=useState<Report|null>(null),[page,setPage]=useState(0);
  const [values,setValues]=useState({statement_reference:'',csv:'',usd_cad_rate:'',fx_date:'',fx_reference:'',reason:'',sender_did:'',destination:'',opt_in_at:''});
  const [records,setRecords]=useState(false),[conversion,setConversion]=useState(false),[clearance,setClearance]=useState(false),[busy,setBusy]=useState(false),[error,setError]=useState(''),[saved,setSaved]=useState(false),[pending,setPending]=useState<Operation|null>(null);
  const sequence=useRef(0);const path=`/application/clinics/${clinic}/provider-evidence`;
  useEffect(()=>{onDirtyChange?.(guard.dirty);},[guard.dirty,onDirtyChange]);useEffect(()=>()=>onDirtyChange?.(false),[onDirtyChange]);
  const fail=useCallback((cause:unknown)=>{validation.capture(cause);setError(cause instanceof Error?cause.message:t('Unable to load provider evidence.'));if(cause instanceof ApiError&&[401,403,404,409].includes(cause.status)){setReport(null);setItems([]);setPending(null);setValues(v=>({...v,csv:'',destination:''}));guard.markClean();}},[validation.capture,t,guard.markClean]);
  const load=useCallback(async()=>{try{const data=await apiRequest<{items:typeof items}>(path,{headers:{Authorization:`Bearer ${await getAccessToken()}`}});setItems(data.items);}catch(cause){fail(cause);}},[path,getAccessToken,fail]);const loadRef=useRef(load);loadRef.current=load;
  useEffect(()=>{void loadRef.current();return()=>{sequence.current++;};},[clinic]);
  const change=(key:keyof typeof values,value:string)=>{setValues(v=>({...v,[key]:value}));guard.markDirty();setSaved(false);};
  const field=(key:keyof typeof values,label:string,required=false)=><TextField name={key} label={t(label)} value={values[key]} required={required} disabled={busy||!!pending||disabled} onChange={e=>change(key,e.target.value)}/>;
  const pick=async(e:ChangeEvent<HTMLInputElement>)=>{const file=e.target.files?.[0];setValues(v=>({...v,csv:''}));setReport(null);setError('');if(!file)return;guard.markDirty();if(file.size>50000){setError(t('Use a normalized CSV file up to 50,000 bytes.'));return;}const current=++sequence.current;setBusy(true);try{const csv=await file.text();if(current===sequence.current)setValues(v=>({...v,csv}));}catch(cause){fail(cause);}finally{if(current===sequence.current)setBusy(false);}};
  const perform=async(operation:Operation)=>{setBusy(true);setPending(operation);setError('');validation.clear();try{const result=await apiRequest<Report>(operation.path,{method:'POST',headers:{Authorization:`Bearer ${await getAccessToken()}`,'Content-Type':'application/json'},body:JSON.stringify(operation.body)});if(operation.kind==='preview'){setReport(result);setPage(0);setRecords(false);setConversion(false);}else{setReport(null);setSaved(true);setValues(v=>({...v,csv:'',destination:'',reason:''}));}setPending(null);guard.markClean();await load();}catch(cause){if(cause instanceof ApiError&&cause.status<500)setPending(null);fail(cause);}finally{setBusy(false);}};
  const preview=(e:FormEvent)=>{e.preventDefault();void perform({path:path+'/preview',kind:'preview',body:{...Object.fromEntries(['statement_reference','csv','usd_cad_rate','fx_date','fx_reference','reason'].map(k=>[k,values[k as keyof typeof values]])),request_key:crypto.randomUUID()}});};
  const open=async(id:number)=>{if(guard.dirty&&!window.confirm(t('Discard your unsaved changes?')))return;setBusy(true);try{setReport(await apiRequest<Report>(path+'/'+id,{headers:{Authorization:`Bearer ${await getAccessToken()}`}}));setPage(0);setRecords(false);setConversion(false);guard.markClean();}catch(cause){fail(cause);}finally{setBusy(false);}};
  const locked=busy||!!pending||disabled;
  return <Stack spacing={2}>
    <Typography variant="h6">{t('Provider evidence imports')}</Typography>
    <Typography>{t('Import the normalized evidence format after checking provider history. This is not a direct VoIP.ms connection. Unknown deliveries stay committed; missing rows never prove non-delivery.')}</Typography>
    {field('reason','Reason for change',true)}
    {error&&<Alert severity="error">{error}</Alert>}{saved&&<Alert severity="success">{t('Provider evidence changes saved.')}</Alert>}
    {pending&&!busy&&<><Alert severity="warning">{t('The outcome is uncertain. Retry the same provider evidence request before editing.')}</Alert><Button onClick={()=>void perform(pending)}>{t('Retry provider evidence request')}</Button></>}
    {!report&&<Paper component="form" onSubmit={preview} variant="outlined" sx={{p:2}}><Stack spacing={2}>
      <Typography sx={{overflowWrap:'anywhere'}}>{t('CSV header: type,reference,sender_did,destination,occurred_at,currency,amount')}</Typography>
      <Typography>{t('Use charge or stop rows only. No message bodies, patient names, credentials or complete account statements. Maximum 500 rows and 50,000 bytes.')}</Typography>
      <input aria-label={t('Normalized provider CSV')} type="file" accept=".csv,text/csv" disabled={locked} onChange={e=>void pick(e)}/>
      {field('statement_reference','Provider statement reference',true)}{field('usd_cad_rate','CAD per USD (up to six decimal places)')}{field('fx_date','Conversion quote date (YYYY-MM-DD)')}{field('fx_reference','Conversion evidence reference')}
      <Button type="submit" variant="contained" disabled={locked||!values.csv||values.reason.trim().length<5}>{t('Preview provider evidence')}</Button>
    </Stack></Paper>}
    {report&&<Paper variant="outlined" sx={{p:2}}><Stack spacing={2}>
      <Typography>{report.statement_reference} · {t(report.status==='applied'?'Applied provider evidence':'Provider evidence preview')}</Typography>
      {report.fx.rate_micros!==null&&<Typography>{t('Conversion snapshot: {{rate}} CAD per USD, {{date}}, {{reference}}',{rate:Number(report.fx.rate_micros)/1000000,date:report.fx.date,reference:report.fx.reference})}</Typography>}
      <TableContainer><Table size="small"><TableHead><TableRow>{['CSV line','Provider reference','Evidence result','Provider amount','CAD amount'].map(label=><TableCell key={label}>{t(label)}</TableCell>)}</TableRow></TableHead><TableBody>{report.rows.slice(page*50,page*50+50).map(row=><TableRow key={row.line}><TableCell>{row.line}</TableCell><TableCell sx={{overflowWrap:'anywhere'}}>{row.reference}</TableCell><TableCell>{t(labels[row.status]??row.status)} · {row.type}</TableCell><TableCell>{row.amount_micros===null?'—':`${row.currency} ${Number(row.amount_micros)/1000000}`}</TableCell><TableCell>{row.actual_cents===null?'—':new Intl.NumberFormat(i18n.language.startsWith('fr')?'fr-CA':'en-CA',{style:'currency',currency:'CAD'}).format(Number(row.actual_cents)/100)}</TableCell></TableRow>)}</TableBody></Table></TableContainer>
      <Stack direction="row"><Button disabled={page===0} onClick={()=>setPage(p=>p-1)}>{t('Previous')}</Button><Typography>{t('Evidence rows: {{count}}',{count:report.rows.length})}</Typography><Button disabled={(page+1)*50>=report.rows.length} onClick={()=>setPage(p=>p+1)}>{t('Next')}</Button></Stack>
      {!report.can_apply&&report.status==='preview'&&<Alert severity="warning">{t('This batch cannot be applied. Resolve unmatched or changed evidence and create a new preview. No ledger costs or suppression decisions have been changed by this preview.')}</Alert>}
      {report.can_apply&&<><FormControlLabel control={<Checkbox checked={records} disabled={locked} onChange={e=>{setRecords(e.target.checked);guard.markDirty();}}/>} label={t('I verified the exact provider records, sender ownership and STOP classifications.')}/><FormControlLabel control={<Checkbox checked={conversion} disabled={locked} onChange={e=>{setConversion(e.target.checked);guard.markDirty();}}/>} label={t('I verified the amounts and conversion evidence. Imported STOP applies to this sender across clinics.')}/><Button variant="contained" disabled={locked||!records||!conversion||values.reason.trim().length<5} onClick={()=>void perform({path:`${path}/${report.id}/apply`,kind:'apply',body:{request_key:crypto.randomUUID(),preview_hash:report.preview_hash,provider_records_verified:records,conversion_verified:conversion,reason:values.reason}})}>{t('Apply reviewed provider evidence')}</Button></>}
      <Button disabled={locked} onClick={()=>{if(!guard.dirty||window.confirm(t('Discard your unsaved changes?'))){setReport(null);guard.markClean();}}}>{t('Return to evidence imports')}</Button>
    </Stack></Paper>}
    {items.map(item=><Button key={item.id} disabled={locked} onClick={()=>void open(item.id)}>{item.statement_reference} · {t(item.status==='applied'?'Applied provider evidence':'Provider evidence preview')}</Button>)}
    <Paper component="form" variant="outlined" sx={{p:2}} onSubmit={e=>{e.preventDefault();void perform({path:path+'/clear-stop',kind:'clear',body:{request_key:crypto.randomUUID(),sender_did:values.sender_did,destination:values.destination,opt_in_at:values.opt_in_at,provider_clearance_verified:clearance,reason:values.reason}});}}><Stack spacing={2}>
      <Typography variant="subtitle1">{t('Clear an imported STOP after renewed consent')}</Typography><Typography>{t('The new opt-in must be later than the latest imported STOP. Local manual blocks remain separate. START messages never clear a block automatically.')}</Typography>
      {field('sender_did','Provider sending number (10 digits)',true)}{field('destination','Destination number (+1 Canadian format)',true)}{field('opt_in_at','Renewed opt-in time (UTC: YYYY-MM-DD HH:mm:ss)',true)}
      <FormControlLabel control={<Checkbox checked={clearance} disabled={locked} onChange={e=>{setClearance(e.target.checked);guard.markDirty();}}/>} label={t('I verified renewed consent and provider clearance for this destination and sender.')}/><Button type="submit" disabled={locked||!clearance||values.reason.trim().length<5}>{t('Clear reviewed provider STOP')}</Button>
    </Stack></Paper>
  </Stack>;
}
export const ProviderEvidenceAdmin=withFormValidation(EvidenceForm);

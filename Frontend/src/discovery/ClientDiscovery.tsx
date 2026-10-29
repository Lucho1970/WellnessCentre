import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LocationSearch, TravelMatchActions, type Match, type SearchResult } from './LocationSearch';
import { customerFetch } from '../customer/session';
import { travelClientRequest } from './TravelEnquiries';
import { useUnsavedChanges } from '../shared/UnsavedChanges';
import { publicDiscoveryRequest } from './publicRequest';
import { travelIntentKey } from './travelIntent';

export function ClientDiscovery({book,openInbox,onLockedChange,canBook=true}:{canBook?:boolean;book:(match:Match)=>void;openInbox:()=>void;onLockedChange?:(locked:boolean)=>void}) {
  const {t}=useTranslation();const [result,setResult]=useState<SearchResult|null>(null),[area,setArea]=useState(''),[people,setPeople]=useState<{slug:string;public_name:string}[]>([]),[services,setServices]=useState<{slug:string;name:string}[]>([]);
  const [enquiry,setEnquiry]=useState<{token:string}|null>(()=>{try{const intent=JSON.parse(sessionStorage.getItem(travelIntentKey)??'null');return intent?.expires>Date.now()?{token:intent.token}:null;}catch{return null;}});
  const [label,setLabel]=useState(''),[message,setMessage]=useState(''),[busy,setBusy]=useState(false),[error,setError]=useState(''),[uncertain,setUncertain]=useState(false);
  const pending=useRef<Record<string,string>|null>(null);
  useUnsavedChanges(Boolean(message)||uncertain);
  useEffect(()=>{onLockedChange?.(Boolean(message)||uncertain||busy);return()=>onLockedChange?.(false);},[message,uncertain,busy,onLockedChange]);
  useEffect(()=>{let active=true;void Promise.all([customerFetch('/profile'),publicDiscoveryRequest<{ slug: string; public_name: string }[]>('/public/practitioners'),publicDiscoveryRequest<{ slug: string; name: string }[]>('/public/services')]).then(([profile,nextPeople,nextServices])=>{
    if(!active)return;const a=profile.address;if(a)setArea([a.address_line1,a.city,a.province,a.postal_code,a.country].filter(Boolean).join(', '));setPeople(nextPeople);setServices(nextServices);
  }).catch(cause=>{if(active)setError(cause.message);});return()=>{active=false;};},[]);
  const request=useCallback((path:string,body?:unknown)=>path.endsWith('/options')?publicDiscoveryRequest(path):customerFetch('/discovery/search',{method:'POST',body:JSON.stringify(body),signal:AbortSignal.timeout(45000)}),[]);
  useEffect(()=>{if(!message&&!uncertain)return;const warn=(event:BeforeUnloadEvent)=>event.preventDefault();window.addEventListener('beforeunload',warn);return()=>window.removeEventListener('beforeunload',warn);},[message,uncertain]);
  const send=async()=>{
    if(!enquiry)return;setBusy(true);setError('');pending.current??={reference:crypto.randomUUID(),token:enquiry.token,search_label:label,message};
    try{await travelClientRequest('/travel-enquiries',pending.current);sessionStorage.removeItem(travelIntentKey);pending.current=null;setUncertain(false);setMessage('');setEnquiry(null);openInbox();}
    catch(cause){const status=(cause as {status?:number}).status;if(status&&status>=400&&status<500){pending.current=null;setUncertain(false);}else setUncertain(true);setError(cause instanceof Error?cause.message:t('Enquiry could not be confirmed. Retry the same enquiry.'));}
    finally{setBusy(false);}
  };
  return <section className="discovery-ui"><h2>{t('Find nearby care')}</h2>
    {error&&<p role="alert">{error}</p>}
    {!enquiry&&<><LocationSearch request={request} onResult={setResult} defaultArea={area} t={t}/>
      {result&&<ul>{Array.from(new Set(result.matches.map(row=>row.practitioner_slug))).map(slug=><li key={slug}><h3>{people.find(person=>person.slug===slug)?.public_name??slug}</h3>{Array.from(new Set(result.matches.filter(row=>row.practitioner_slug===slug).map(row=>row.service_slug))).map(serviceSlug=>{
        const matches=result.matches.filter(row=>row.practitioner_slug===slug&&row.service_slug===serviceSlug);return <div key={serviceSlug}><strong>{services.find(service=>service.slug===serviceSlug)?.name??serviceSlug}</strong>{matches.some(row=>row.status==='within_area')?<><span>{t('Within service area')}</span>{canBook&&<button type="button" onClick={()=>book(matches.find(row=>row.status==='within_area')!)}>{t('Book appointment')}</button>}</>:<TravelMatchActions matches={matches} onEnquire={match=>{setEnquiry(match);setLabel(result.label==='Selected search point'?'':result.label);}} t={t}/>}</div>;
      })}</li>)}</ul>}</>}
    {enquiry&&<><h3>{t('Ask about extra travel')}</h3><p>{t('Include your proposed visit area and preferred service. Avoid health details. This is an enquiry, not an appointment or agreed price.')}</p>
      <fieldset disabled={busy||uncertain}><label>{t('Proposed visit area')}<input maxLength={250} value={label} onChange={event=>setLabel(event.target.value)}/></label><label>{t('Your message')}<textarea rows={5} maxLength={2000} value={message} onChange={event=>setMessage(event.target.value)}/></label></fieldset>
      <button type="button" disabled={busy||!label.trim()||!message.trim()} onClick={()=>void send()}>{uncertain?t('Retry same enquiry'):t('Send travel enquiry')}</button>
      <button type="button" disabled={busy||uncertain} onClick={()=>{if(!message||window.confirm(t('Discard this unsent enquiry?'))){setEnquiry(null);setMessage('');sessionStorage.removeItem(travelIntentKey);}}}>{t('Cancel enquiry')}</button>
    </>}
  </section>;
}

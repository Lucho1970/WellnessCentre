import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiRequest } from '../shared/api';
import { customerFetch } from '../customer/session';
import type { DiscoveryRequest } from './LocationSearch';
import './discovery.css';
import { useUnsavedChanges } from '../shared/UnsavedChanges';

type Thread = {id:number;status:string;search_label:string;client_name:string;practitioner_name:string;service_name:string;unread:number};
type Message = {id:number;body:string;created_at:string;sender_name:string;own_message:number};
export const travelClientRequest:DiscoveryRequest=(path,body)=>customerFetch(path,body===undefined?{}:{method:'POST',body:JSON.stringify(body)});
export function useTravelRequest():DiscoveryRequest {
  const {getAccessToken}=useStaffAuth();
  return useCallback(async(path:string,body?:unknown)=>{
    const token=await getAccessToken();return apiRequest(path,{method:body===undefined?'GET':'POST',headers:{Authorization:`Bearer ${token}`,'Content-Type':'application/json'},...(body===undefined?{}:{body:JSON.stringify(body)})});
  },[getAccessToken]);
}
export function StaffTravelEnquiries(){const request=useTravelRequest();return <TravelEnquiries request={request}/>;}

export function TravelEnquiryNotice({request,open}:{request:DiscoveryRequest;open:()=>void}) {
  const {t}=useTranslation();const [count,setCount]=useState(0);
  useEffect(()=>{
    const interval=60000,maxDelay=15*60000;
    let live=true,inFlight=false,refreshPending=false,generation=0,delay=interval;
    let timer: ReturnType<typeof setTimeout> | undefined;
    setCount(0);
    const visible=()=>document.visibilityState!=='hidden';
    const clear=()=>{if(timer!==undefined)clearTimeout(timer);timer=undefined;};
    const load=async()=>{
      clear();
      if(!live||!visible())return;
      if(inFlight){refreshPending=true;return;}
      inFlight=true;refreshPending=false;const version=generation;
      try{
        const data=await request('/travel-enquiries/unread-count');
        if(!Number.isSafeInteger(data.unread_count)||data.unread_count<0)throw new Error('Invalid unread count');
        if(live&&version===generation&&visible()){setCount(data.unread_count);delay=interval;}
      }catch{
        if(live&&version===generation){setCount(0);delay=Math.min(delay*2,maxDelay);}
      }finally{
        inFlight=false;
        if(live&&visible()){
          if(refreshPending)void load();else timer=setTimeout(()=>{void load();},delay);
        }
      }
    };
    const visibility=()=>{
      generation++;clear();setCount(0);
      if(visible())void load();
    };
    document.addEventListener('visibilitychange',visibility);
    // Let a development StrictMode cleanup cancel the first effect before it sends a request.
    queueMicrotask(()=>{void load();});
    return()=>{live=false;generation++;clear();document.removeEventListener('visibilitychange',visibility);};
  },[request]);
  return count>0?<div className="discovery-ui" role="status"><button type="button" onClick={open}>{t('Unread travel enquiries')}: {count}</button></div>:null;
}

export function TravelEnquiries({client=false,request,onLockedChange}:{client?:boolean;request:DiscoveryRequest;onLockedChange?:(locked:boolean)=>void}) {
  const {t}=useTranslation();
  const [items,setItems]=useState<Thread[]>([]),[thread,setThread]=useState<(Thread&{messages:Message[];next_after:number|null})|null>(null),[before,setBefore]=useState<number|null>(null);
  const [error,setError]=useState(''),[busy,setBusy]=useState(false),[message,setMessage]=useState(''),[notice,setNotice]=useState('');
  const pending=useRef<{reference:string;message:string}|null>(null),[uncertain,setUncertain]=useState(false);
  const active=useRef(true),generation=useRef(0);
  const revoked=useCallback((cause:unknown)=>{
    if(![401,403,404].includes((cause as {status?:number})?.status??0))return false;
    generation.current++;setItems([]);setBefore(null);setThread(null);setMessage('');pending.current=null;setUncertain(false);setNotice('');setBusy(false);
    setError(t('This travel enquiry is no longer available. Refresh your inbox.'));return true;
  },[t]);
  useUnsavedChanges(Boolean(message)||uncertain);
  useEffect(()=>{onLockedChange?.(Boolean(message)||uncertain||busy);return()=>onLockedChange?.(false);},[message,uncertain,busy,onLockedChange]);
  useEffect(()=>{active.current=true;return()=>{active.current=false;generation.current++;};},[]);
  const list=useCallback(async(cursor?:number)=>{const version=generation.current;try{const data=await request(`/travel-enquiries${cursor?`?before=${cursor}`:''}`);if(active.current&&version===generation.current){setItems(data.items);setBefore(data.next_before);setError('');}}catch(cause){if(active.current&&version===generation.current&&!revoked(cause))setError(cause instanceof Error?cause.message:t('Unable to load travel enquiries.'));}},[request,t,revoked]);
  useEffect(()=>{void list();},[list]);
  const show=async(id:number,after?:number)=>{
    const version=generation.current;setError('');setBusy(true);
    try{
      const data=await request(`/travel-enquiries/${id}${after?`?after=${after}`:''}`);
      if(!active.current||version!==generation.current)return;setThread(data);setNotice('');
      const last=data.messages.at(-1)?.id??0;
      await request(`/travel-enquiries/${id}/read`,{message_id:last});if(active.current&&version===generation.current)await list();
    }catch(cause){if(active.current&&version===generation.current&&!revoked(cause))setError(cause instanceof Error?cause.message:t('Unable to load travel enquiries.'));}
    finally{if(active.current&&version===generation.current)setBusy(false);}
  };
  const reply=async()=>{
    if(!thread)return;const version=generation.current;setBusy(true);setError('');
    pending.current??={reference:crypto.randomUUID(),message};
    try{
      await request(`/travel-enquiries/${thread.id}/reply`,pending.current);if(!active.current||version!==generation.current)return;pending.current=null;setUncertain(false);setMessage('');await show(thread.id);if(active.current&&version===generation.current)setNotice(t('Reply saved.'));
    }catch(cause){if(!active.current||version!==generation.current||revoked(cause))return;const status=(cause as {status?:number}).status;const unknown=!status||status>=500;setUncertain(unknown);if(!unknown)pending.current=null;setError(cause instanceof Error?cause.message:t('Reply could not be confirmed. Retry the same message.'));}
    finally{if(active.current&&version===generation.current)setBusy(false);}
  };
  const close=async()=>{
    if(!thread||!window.confirm(t('Close this travel enquiry?')))return;
    const version=generation.current;setBusy(true);setError('');
    try{await request(`/travel-enquiries/${thread.id}/close`,{});if(active.current&&version===generation.current)await show(thread.id);}
    catch(cause){if(active.current&&version===generation.current&&!revoked(cause))setError(cause instanceof Error?cause.message:t('Unable to load travel enquiries.'));}
    finally{if(active.current&&version===generation.current)setBusy(false);}
  };
  useEffect(()=>{if(!message&&!uncertain)return;const warn=(event:BeforeUnloadEvent)=>event.preventDefault();window.addEventListener('beforeunload',warn);return()=>window.removeEventListener('beforeunload',warn);},[message,uncertain]);
  return <section className="discovery-ui"><h2>{t('Travel enquiries')}</h2><p>{t('Messages discuss possible travel only. Staff must arrange any resulting appointment; a reply does not confirm a booking or fee.')}</p>
    {error&&<p role="alert">{error}</p>}{notice&&<p role="status">{notice}</p>}
    <button type="button" disabled={busy||Boolean(message)||uncertain} onClick={()=>{setThread(null);void list();}}>{t('Refresh inbox')}</button>
    {!items.length&&<p>{t('No travel enquiries yet.')}</p>}
    <ul>{items.map(item=><li key={item.id}><button disabled={busy||Boolean(message)||uncertain} type="button" onClick={()=>void show(item.id)}>{Number(item.unread)>0?`${t('Unread')} · `:''}{item.service_name} — {client?item.practitioner_name:item.client_name} · {item.status==='closed'?t('Closed'):t('Open')}</button></li>)}</ul>
    {before&&<button type="button" disabled={busy||Boolean(message)||uncertain} onClick={()=>void list(before)}>{t('Older enquiries')}</button>}
    {thread&&<article><h3>{thread.service_name} — {thread.practitioner_name}</h3><p>{t('Proposed visit area')}: {thread.search_label}</p>
      <ul className="travel-messages">{thread.messages.map(item=><li key={item.id}><strong>{item.sender_name==='Client'?t('Client'):item.sender_name==='Clinic team'?t('Clinic team'):item.sender_name}</strong> <time>{new Date(item.created_at.replace(' ','T')+'Z').toLocaleString()}</time><p>{item.body}</p></li>)}</ul>
      {thread.next_after&&<button type="button" disabled={busy||Boolean(message)||uncertain} onClick={()=>void show(thread.id,thread.next_after!)}>{t('More messages')}</button>}
      {thread.status==='open'&&<><label>{t('Your reply')}<textarea rows={5} maxLength={2000} value={message} disabled={busy||uncertain} onChange={event=>setMessage(event.target.value)}/></label><button type="button" disabled={busy||!message.trim()} onClick={()=>void reply()}>{uncertain?t('Retry same reply'):t('Send reply')}</button>{!client&&<button type="button" disabled={busy||Boolean(message)||uncertain} onClick={()=>void close()}>{t('Close enquiry')}</button>}</>}
      {thread.status==='closed'&&<p>{t('This enquiry is closed.')}</p>}
    </article>}
  </section>;
}

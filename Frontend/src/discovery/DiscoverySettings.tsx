import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiRequest } from '../shared/api';
import './discovery.css';

export function DiscoverySettings() {
  const {t}=useTranslation(),{getAccessToken}=useStaffAuth();
  const [settings,setSettings]=useState<{extra_km:number;cap_km:number;revision:number}|null>(null),[error,setError]=useState(''),[notice,setNotice]=useState(''),[busy,setBusy]=useState(false);
  const request=useCallback(async(body?:unknown)=>apiRequest<any>('/application/discovery-settings',{method:body?'PUT':'GET',headers:{Authorization:`Bearer ${await getAccessToken()}`,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})}),[getAccessToken]);
  const load=useCallback(()=>{setError('');void request().then(setSettings).catch(cause=>setError(cause.message));},[request]);
  useEffect(load,[load]);
  return <section className="discovery-ui"><h2>{t('Location discovery settings')}</h2><p>{t('These limits apply across all clinics. Discovery never overrides a practitioner’s booking limit.')}</p>
    {error&&<p role="alert">{error}</p>}{notice&&<p role="status">{notice}</p>}<button type="button" disabled={busy} onClick={load}>{t('Reload settings')}</button>
    {settings&&<form onSubmit={event=>{event.preventDefault();setBusy(true);setError('');setNotice('');void request({extra_km:settings.extra_km,cap_km:settings.cap_km,revision:settings.revision}).then(data=>{setSettings(data);setNotice(t('Discovery settings saved.'));}).catch(cause=>setError(cause.message)).finally(()=>setBusy(false));}}><fieldset disabled={busy}>
      <label>{t('Extra enquiry allowance (km)')}<input required type="number" min={0} max={200} step={1} value={settings.extra_km} onChange={event=>setSettings({...settings,extra_km:Number(event.target.value)})}/></label>
      <label>{t('Absolute discovery cap (km)')}<input required type="number" min={1} max={500} step={1} value={settings.cap_km} onChange={event=>setSettings({...settings,cap_km:Number(event.target.value)})}/></label>
      <button type="submit">{t('Save discovery settings')}</button>
    </fieldset></form>}
  </section>;
}

export function DiscoveryPreferences({request}:{request:(path:string,body?:unknown)=>Promise<any>}) {
  const {t}=useTranslation();const [items,setItems]=useState<{service_id:number;name:string;allow_extra:boolean}[]>([]),[error,setError]=useState(''),[busy,setBusy]=useState(false);
  useEffect(()=>{let active=true;void request('/profile/discovery-preferences').then(data=>{if(active)setItems(Array.isArray(data.items)?data.items:[]);}).catch(cause=>{if(active)setError(cause.message);});return()=>{active=false;};},[request]);
  return <section className="discovery-ui"><h3>{t('Extra-distance travel enquiries')}</h3><p>{t('Allow clients just beyond your normal limit to ask about travel. This does not change your fees, maximum booking distance or other clinics.')}</p>{error&&<p role="alert">{error}</p>}
    {items.map(item=><label className="discovery-check" key={item.service_id}><input type="checkbox" disabled={busy} checked={item.allow_extra} onChange={event=>{const allow_extra=event.target.checked;setItems(current=>current.map(row=>row.service_id===item.service_id?{...row,allow_extra}:row));setBusy(true);setError('');void request('/profile/discovery-preferences',{service_id:item.service_id,allow_extra}).then(data=>setItems(data.items)).catch(cause=>{setItems(current=>current.map(row=>row.service_id===item.service_id?item:row));setError(cause.message);}).finally(()=>setBusy(false));}}/>{item.name}</label>)}
  </section>;
}

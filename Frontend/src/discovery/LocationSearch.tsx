/// <reference types="google.maps" />
import { useEffect, useRef, useState } from 'react';
import { importLibrary, setOptions } from '@googlemaps/js-api-loader';
import './discovery.css';

export type Point = { latitude: number; longitude: number };
export type Match = { practitioner_id: number; service_id: number; location_id: number; practitioner_slug: string; service_slug: string; status: 'within_area'|'enquiry_only'; token: string; starting_price_cents:number };
export type SearchResult = { point: Point; label: string; matches: Match[]; settings: { extra_km: number; cap_km: number } };
export type DiscoveryRequest = <T = unknown>(path: string, body?: unknown) => Promise<T>;
export type Translate = (key: string) => string;
const identity: Translate = key => key;
const storageKey = 'wellness.discovery.point.v1';

function SearchMap({ point, apiKey, choose, t }: {point: Point; apiKey: string; choose: (point: Point) => void; t: Translate}) {
  const ref=useRef<HTMLDivElement>(null), map=useRef<google.maps.Map|null>(null), circle=useRef<google.maps.Circle|null>(null);
  const chooseRef=useRef(choose);chooseRef.current=choose;
  const [error,setError]=useState('');
  useEffect(()=>{
    let active=true;let listener:google.maps.MapsEventListener|undefined;
    setOptions({key:apiKey,v:'weekly',region:'CA',authReferrerPolicy:'origin'});
    void importLibrary('maps').then(()=>{
      if(!active||!ref.current)return;
      const center={lat:point.latitude,lng:point.longitude};
      const instance=new google.maps.Map(ref.current,{center,zoom:10,mapTypeControl:false,streetViewControl:false,fullscreenControl:false,gestureHandling:'cooperative'});
      map.current=instance;circle.current=new google.maps.Circle({map:instance,center,radius:300,fillColor:'#7A4DFF',fillOpacity:.6,strokeColor:'#0F4C5C',clickable:false});
      listener=instance.addListener('click',(event:google.maps.MapMouseEvent)=>{if(event.latLng)chooseRef.current({latitude:event.latLng.lat(),longitude:event.latLng.lng()});});
    }).catch(()=>{if(active)setError(t('The map could not load. Use an address or your current location instead.'));});
    return()=>{active=false;listener?.remove();circle.current?.setMap(null);map.current=null;};
  },[apiKey,t]);
  useEffect(()=>{const center={lat:point.latitude,lng:point.longitude};map.current?.panTo(center);circle.current?.setCenter(center);},[point]);
  return <>{error&&<p role="alert">{error}</p>}<div ref={ref} className="discovery-map" aria-label={t('Choose a search point on the map')}/><button type="button" onClick={()=>{const center=map.current?.getCenter();if(center)choose({latitude:center.lat(),longitude:center.lng()});}}>{t('Use map centre')}</button></>;
}

/** Shared by the public website, guest catalogue and signed-in client discovery. */
export function LocationSearch({request,onResult,defaultArea,savedAddressLink,t=identity}:{request:DiscoveryRequest;onResult:(value:SearchResult|null)=>void;defaultArea?:string;savedAddressLink?:string;t?:Translate}) {
  const [area,setArea]=useState(''),[result,setResult]=useState<SearchResult|null>(null),[error,setError]=useState(''),[busy,setBusy]=useState(false),[only,setOnly]=useState(false);
  const [point,setPoint]=useState<Point>({latitude:43.6532,longitude:-79.3832}),[showMap,setShowMap]=useState(false),[mapKey,setMapKey]=useState('');
  const initialized=useRef(false),mounted=useRef(true),generation=useRef(0),onlyRef=useRef(false);
  onlyRef.current=only;
  const resultRef=useRef(onResult);resultRef.current=onResult;
  const search=async(body:{point:Point}|{area:string})=>{
    const current=++generation.current;setBusy(true);setError('');
    try{
      const data=await request('/public/discovery/search',body) as SearchResult;
      if(!Array.isArray(data.matches)||!data.point||!data.settings)throw new Error(t('Location search returned an unexpected response.'));
      if(!mounted.current||current!==generation.current)return;
      setResult(data);setPoint(data.point);resultRef.current({...data,matches:onlyRef.current?data.matches.filter(row=>row.status==='within_area'):data.matches});
      try{sessionStorage.setItem(storageKey,JSON.stringify({point:data.point,expires:Date.now()+1800000,only:onlyRef.current}));}catch{/* Optional tab-local preference. */}
    }catch(cause){if(mounted.current&&current===generation.current)setError(cause instanceof Error?cause.message:t('Location search is temporarily unavailable.'));}
    finally{if(mounted.current&&current===generation.current)setBusy(false);}
  };
  useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;initialized.current=false;generation.current++;};},[]);
  useEffect(()=>{let live=true;void request<{ map_browser_key?: string }>('/public/discovery/options').then(data=>{if(live&&typeof data.map_browser_key==='string')setMapKey(data.map_browser_key);}).catch(()=>{});return()=>{live=false;};},[request]);
  useEffect(()=>{
    if(initialized.current)return;
    try{const saved=JSON.parse(sessionStorage.getItem(storageKey)??'null');if(saved?.expires>Date.now()&&Number.isFinite(saved.point?.latitude)&&Number.isFinite(saved.point?.longitude)){initialized.current=true;setOnly(Boolean(saved.only));void search({point:saved.point});return;}}catch{/* No saved point. */}
    if(defaultArea){initialized.current=true;setArea(defaultArea);void search({area:defaultArea});}
  },[defaultArea]);
  const locate=()=>{
    if(!navigator.geolocation){setError(t('Location access is unavailable. Enter an address instead.'));return;}
    setError('');setBusy(true);
    navigator.geolocation.getCurrentPosition(position=>{if(mounted.current)void search({point:{latitude:position.coords.latitude,longitude:position.coords.longitude}});},()=>{if(mounted.current){setBusy(false);setError(t('Location access was denied or unavailable. Enter an address instead.'));}},{timeout:12000,maximumAge:60000,enableHighAccuracy:false});
  };
  return <div className="discovery-ui discovery-search">
    <h3>{t('Find care near your visit location')}</h3>
    <p>{t('Choose where the appointment would take place. Your search area does not change your profile or appointment address.')}</p>
    {savedAddressLink&&<p><a href={savedAddressLink}>{t('Sign in to use my saved address')}</a></p>}
    <fieldset disabled={busy}>
      <label>{t('City, postal code or address')}<input value={area} maxLength={250} onChange={event=>setArea(event.target.value)} onKeyDown={event=>{if(event.key==='Enter'){event.preventDefault();if(area.trim().length>=3)void search({area});}}}/></label>
      <div className="discovery-actions"><button type="button" disabled={area.trim().length<3} onClick={()=>void search({area})}>{t('Search this area')}</button><button type="button" onClick={locate}>{t('Use my current location')}</button><button type="button" onClick={()=>setShowMap(value=>!value)}>{t('Choose on map')}</button></div>
      {defaultArea&&<button type="button" onClick={()=>{setArea(defaultArea);void search({area:defaultArea});}}>{t('Use my saved address')}</button>}
      <label className="discovery-check"><input type="checkbox" checked={only} onChange={event=>{const checked=event.target.checked;setOnly(checked);if(result){resultRef.current({...result,matches:checked?result.matches.filter(row=>row.status==='within_area'):result.matches});try{sessionStorage.setItem(storageKey,JSON.stringify({point:result.point,expires:Date.now()+1800000,only:checked}));}catch{/* Optional. */}}}}/>{t('Only show practitioners within their normal travel limit')}</label>
      {showMap&&(mapKey?<><p>{t('Click a point, or move the map and use its centre. Then search that point.')}</p><SearchMap point={point} apiKey={mapKey} choose={setPoint} t={t}/><button type="button" onClick={()=>void search({point})}>{t('Search selected point')}</button></>:<p>{t('Map selection is not configured yet. Address and current-location search are available.')}</p>)}
      {result&&<button type="button" onClick={()=>{initialized.current=true;setOnly(false);setResult(null);resultRef.current(null);try{sessionStorage.removeItem(storageKey);}catch{/* Optional. */}}}>{t('Clear location filter')}</button>}
    </fieldset>
    {busy&&<p role="status">{t('Checking travel areas…')}</p>}{error&&<p role="alert">{error}</p>}
    {result&&<p role="status">{t('Showing results for:')} {result.label}. {t('Travel approval required means you can enquire; it does not allow online booking. Exact visit coverage and fees are checked before booking.')}</p>}
    {result&&!(only?result.matches.filter(row=>row.status==='within_area'):result.matches).length&&!busy&&<p>{t('No matching On-Site practitioners in this area. Try another search area.')}</p>}
  </div>;
}

export function TravelMatchActions({matches,portal='',t=identity,onEnquire}:{matches:Match[];portal?:string;t?:Translate;onEnquire?:(match:Match)=>void}) {
  const book=matches.find(row=>row.status==='within_area'),enquiry=matches.find(row=>row.status==='enquiry_only');
  return <div className="discovery-ui discovery-match">{book?<><span>{t('Within service area')}</span> <a href={`${portal}/services/${encodeURIComponent(book.service_slug)}/book?practitioner_id=${book.practitioner_id}&location_id=${book.location_id}`}>{t('Explore availability')}</a></>:enquiry?<><span>{t('Travel approval required')}</span> {onEnquire?<button type="button" onClick={()=>onEnquire(enquiry)}>{t('Ask about travel')}</button>:<a href={`${portal}/client#travel=${encodeURIComponent(enquiry.token)}`}>{t('Ask about travel')}</a>}</>:null}</div>;
}

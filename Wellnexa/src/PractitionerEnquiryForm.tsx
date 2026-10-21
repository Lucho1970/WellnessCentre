import { useEffect, useRef, useState, type FormEvent } from 'react'
type Fields={full_name:string;email:string;phone:string;discipline:string;credentials:string;service_area:string;message:string;website:string;consent:boolean}
type Submission=Fields&{idempotency_key:string}
const empty:Fields={full_name:'',email:'',phone:'',discipline:'',credentials:'',service_area:'',message:'',website:'',consent:false}
export function PractitionerEnquiryForm() {
  const [form,setForm]=useState<Fields>(empty),[busy,setBusy]=useState(false),[uncertain,setUncertain]=useState(false),[error,setError]=useState(''),[reference,setReference]=useState('')
  const pending=useRef<Submission|null>(null)
  useEffect(()=>{const warn=(event:BeforeUnloadEvent)=>{if(!reference&&Object.values(form).some(Boolean)){event.preventDefault();event.returnValue='';}};window.addEventListener('beforeunload',warn);return()=>window.removeEventListener('beforeunload',warn);},[form,reference])
  const set=<K extends keyof Fields>(key:K,value:Fields[K])=>setForm(old=>({...old,[key]:value}))
  const submit=async(event:FormEvent)=>{
    event.preventDefault();setBusy(true);setError('')
    pending.current??={...form,idempotency_key:crypto.randomUUID()}
    try{
      const response=await fetch('/api/public/practitioner-enquiries',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'omit',body:JSON.stringify(pending.current),signal:AbortSignal.timeout(20000)})
      const body=await response.json()
      if(!response.ok){
        if((response.status>=400&&response.status<500)||body.error?.code==='enquiries_closed'){pending.current=null;setUncertain(false);throw new Error(body.error?.message||'Check your enquiry details and try again.')}
        throw new Error('We could not confirm receipt. Please retry here using the same enquiry.')
      }
      if(body.data?.received!==true||body.data.reference!==pending.current.idempotency_key)throw new Error('We could not confirm receipt. Please retry here using the same enquiry.')
      setReference(body.data.reference);pending.current=null;setUncertain(false);setForm(empty)
    }catch(cause){setUncertain(pending.current!==null);setError(cause instanceof Error?cause.message:'We could not confirm receipt. Please retry here.')}
    finally{setBusy(false)}
  }
  if(reference)return <section className="enquiry-panel" id="enquiry-form" role="status"><h2>Thank you for your interest</h2><p>Your enquiry has been saved for administrator review. We can contact you using the details you provided.</p><p className="enquiry-reference">Your reference: {reference}</p><p>Submitting an enquiry does not create a practitioner account or confirm membership.</p></section>
  return <section className="enquiry-panel" id="enquiry-form" aria-labelledby="enquiry-title"><h2 id="enquiry-title">Tell us about your practice</h2><p>Fields marked * are required. Please do not include client information, medical details, identification documents or payment information.</p>
    {error&&<p className="catalogue-feedback" role="alert">{error}</p>}
    {uncertain&&<p role="status">Your details are preserved for a safe retry. Please retry before changing or leaving this page.</p>}
    <form onSubmit={event=>void submit(event)}>
      <fieldset disabled={busy||uncertain}><legend className="visually-hidden">Practitioner enquiry details</legend><div className="enquiry-grid">
      {([['full_name','Full name',160,true,'name'],['email','Email address',254,true,'email'],['phone','Phone number (optional)',40,false,'tel'],['discipline','Discipline / type of practice',190,true,'off'],['credentials','Credentials or professional registration (optional)',500,false,'off'],['service_area','City, province and service area',190,true,'address-level2']] as const).map(([key,label,max,required,autocomplete])=><label key={key} htmlFor={`enquiry-${key}`}>{label}{required?' *':''}<input id={`enquiry-${key}`} name={key} type={key==='email'?'email':key==='phone'?'tel':'text'} autoComplete={autocomplete} maxLength={max} required={required} value={form[key]} onChange={event=>set(key,event.target.value)}/></label>)}
      <label className="enquiry-wide" htmlFor="enquiry-message">Introduce yourself and the services you would like to offer *<textarea id="enquiry-message" name="message" required maxLength={2000} rows={5} value={form.message} onChange={event=>set('message',event.target.value)}/></label>
      </div><div className="enquiry-trap" aria-hidden="true"><label htmlFor="enquiry-website">Leave this field empty<input id="enquiry-website" name="website" tabIndex={-1} autoComplete="off" value={form.website} onChange={event=>set('website',event.target.value)}/></label></div>
      <p>Wellnexa’s clinic and platform administrators will use these details to review your enquiry and contact you about joining. They are kept in the administrator inbox and are not published on the website. This is not a mailing-list subscription.</p>
      <label className="enquiry-consent"><input type="checkbox" required checked={form.consent} onChange={event=>set('consent',event.target.checked)}/> I agree to the use of these details to review my enquiry and contact me about joining the collective. *</label></fieldset>
      <button className="button-link enquiry-submit" type="submit" disabled={busy}>{busy?'Sending enquiry…':uncertain?'Retry enquiry':'Send enquiry'}</button>
    </form></section>
}

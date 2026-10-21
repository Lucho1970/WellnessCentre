import { site } from '../config/site'
import type { PublicPractitioner, PublicService } from './api'

export function portalLink(path: string, practitionerId?: number) {
  const url = new URL(path, site.portalUrl.endsWith('/') ? site.portalUrl : site.portalUrl + '/')
  if (practitionerId) url.searchParams.set('practitioner_id', String(practitionerId))
  return url.href
}
export function safeWebsite(value: string | null) {
  try {const url=new URL(value??'');return ['https:','http:'].includes(url.protocol)&&!url.username&&!url.password?url.href:null} catch{return null}
}
function SocialIcon({label,url}:{label:string;url:string}) {
  const network=`${label} ${url}`.toLowerCase()
  return <svg viewBox="0 0 32 32" width="30" height="30" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.8">
    {network.includes('instagram')?<><rect x="4" y="4" width="24" height="24" rx="7"/><circle cx="16" cy="16" r="6"/><circle cx="23" cy="9" r="1" fill="currentColor"/></>:network.includes('youtube')?<><rect x="3" y="7" width="26" height="18" rx="5"/><path d="m13 11 9 5-9 5Z" fill="currentColor" stroke="none"/></>:<><circle cx="16" cy="16" r="14"/><text x="16" y="21" textAnchor="middle" fill="currentColor" stroke="none" fontSize="14" fontFamily="Arial">{network.includes('facebook')?'f':network.includes('linkedin')?'in':label.slice(0,2).toUpperCase()}</text></>}
  </svg>
}
export function ServicePrice({service}:{service:PublicService}) {
  return service.durations.length ? <p className="service-options">{[...new Set(service.durations.map(option=>option.minutes))].join(' / ')} minutes · From {new Intl.NumberFormat('en-CA',{style:'currency',currency:'CAD'}).format(Math.min(...service.durations.map(option=>option.price_cents))/100)} CAD</p> : null
}
export function ServiceDetails({service,practitioners}:{service:PublicService;practitioners:PublicPractitioner[]}) {
  return <section className="detail-page"><a href="/#services">← All services</a><p className="eyebrow">{service.category||'Explore your care'}</p><h1>{service.name}</h1>
    <ServicePrice service={service}/><p className="detail-copy">{service.description||service.public_summary||'Contact the clinic to learn more about this service.'}</p>
    {service.offers_mobile&&<p className="catalogue-empty">Available on-site. Your address, practitioner travel area and any travel fee are checked during booking.</p>}
    {service.offers_clinic&&<p>Clinic appointments are available for this service.</p>}
    {service.preparation_instructions&&<><h2>Before your appointment</h2><p className="detail-copy">{service.preparation_instructions}</p></>}
    <p className="status">Prices can vary by practitioner. Review the selected practitioner, duration and any additional fee before confirming in the clinic portal.</p>
    <a className="button-link" href={portalLink(`services/${service.slug}/book`)}>Explore availability ↗</a>
    <h2>Meet your practitioners</h2><div className="catalogue-grid">{practitioners.filter(person=>person.services.some(item=>item.slug===service.slug)).map(person=><a className="catalogue-card profile-link" href={`/practitioners/${person.slug}`} key={person.slug}><h3>{person.public_name}</h3><p>{person.public_title||person.discipline}</p><span>View profile →</span></a>)}</div>
    <p className="status">A time is reserved only after you confirm your booking in the portal.</p>
  </section>
}
export function PractitionerDetails({person,photo}:{person:PublicPractitioner;photo:React.ReactNode}) {
  const website=safeWebsite(person.public_website_url)
  const phone=person.public_contact_phone&&/^\+[1-9][0-9]{7,14}$/.test(person.public_contact_phone)?person.public_contact_phone:null
  const email=person.public_contact_email&&/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(person.public_contact_email)?person.public_contact_email:null
  return <section className="detail-page"><a href="/#practitioners">← All practitioners</a><div className="profile-heading">{photo}<div><p className="eyebrow">Meet the collective</p><h1>{person.public_name}</h1><p className="introduction">{person.public_title||person.discipline}</p><p>{[person.credentials,person.discipline].filter(Boolean).join(' · ')}</p></div></div>
    {person.summary&&<p className="detail-copy">{person.summary}</p>}
    <div className="contact-actions">{website&&<a href={website} target="_blank" rel="noopener noreferrer">Website ↗</a>}{email&&<a href={`mailto:${email}`}>Email</a>}{phone&&<a href={`tel:${phone}`}>Call</a>}{phone&&person.public_contact_sms&&<a href={`sms:${phone}`}>Text</a>}</div>
    {person.social_links.length>0&&<nav className="social-links" aria-label={`${person.public_name} social profiles`}>{person.social_links.filter(link=>safeWebsite(link.url)?.startsWith('https://')).map(link=><a className="social-icon" key={link.url} href={link.url} target="_blank" rel="noopener noreferrer" aria-label={`${person.public_name} on ${link.label} (opens in a new tab)`} title={link.label}><SocialIcon label={link.label} url={link.url}/><span>{link.label}</span></a>)}</nav>}
    {person.booking_practitioner_id&&<a className="button-link" href={portalLink('availability',person.booking_practitioner_id)}>Book with me ↗</a>}
    {person.services.length>0&&<><h2>Services I offer</h2><ul className="practitioner-services">{person.services.map(service=><li key={service.slug}><a href={`/services/${service.slug}`}>{service.name}</a>{person.booking_practitioner_id&&<> · <a href={portalLink(`services/${service.slug}/book`,person.booking_practitioner_id)}>Book with {person.public_name} ↗</a></>}</li>)}</ul></>}
  </section>
}

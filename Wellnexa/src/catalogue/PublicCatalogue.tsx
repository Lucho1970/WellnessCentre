import { useEffect, useState } from 'react'
import { site } from '../config/site'
import { fetchCatalogue, type PublicPractitioner, type PublicService } from './api'
import { PractitionerDetails, ServiceDetails, ServicePrice } from './Details'

type CatalogueState = { services: PublicService[] | null; practitioners: PublicPractitioner[] | null; serviceError: boolean; practitionerError: boolean }
const initial: CatalogueState = { services: null, practitioners: null, serviceError: false, practitionerError: false }
function CatalogueFeedback({ loading, error, empty, label, onRetry }: { loading: boolean; error: boolean; empty: boolean; label: string; onRetry: () => void }) {
  if (error) return <div className="catalogue-feedback" role="alert"><p>We couldn’t load our {label} right now.</p><button type="button" onClick={onRetry}>Try again</button> <a href={site.portalUrl}>Visit the clinic portal</a></div>
  if (loading) return <p role="status">Loading {label}…</p>
  if (empty) return <p className="catalogue-empty">{label === 'services' ? 'Services' : 'Practitioner profiles'} will appear here as they are published.</p>
  return null
}
function PractitionerPhoto({ person }: { person: PublicPractitioner }) {
  const [failed, setFailed] = useState(false)
  const initials = person.public_name.trim().split(/\s+/u).slice(0, 2).map(name => Array.from(name)[0]).join('').toLocaleUpperCase('en-CA')
  // Same fixed public clinic as the catalogue bridge; never accept an image URL from profile data.
  const image = new URL(`https://wellnexa.copihue.ca/api/v1/team/${encodeURIComponent(person.slug)}/image`)
  if (person.image_version) image.searchParams.set('v', person.image_version)
  return <div className="practitioner-photo">{person.has_image && !failed
    ? <img src={image.href} alt={`${person.public_name} — practitioner portrait`} width={128} height={128} loading="lazy" decoding="async" referrerPolicy="no-referrer" onError={() => setFailed(true)}/>
    : <span aria-hidden="true">{initials}</span>}</div>
}
export function PublicCatalogue() {
  const [state, setState] = useState<CatalogueState>(initial)
  const [reload, setReload] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    const load = async () => {
      const result = await Promise.allSettled([fetchCatalogue('services', controller.signal), fetchCatalogue('practitioners', controller.signal)])
      if (controller.signal.aborted) return
      setState({ services: result[0].status === 'fulfilled' ? result[0].value : null, practitioners: result[1].status === 'fulfilled' ? result[1].value : null, serviceError: result[0].status === 'rejected', practitionerError: result[1].status === 'rejected' })
    }
    void load()
    return () => controller.abort()
  }, [reload])
  const retry = () => { setState(initial); setReload(value => value + 1) }
  const detail = /^\/(services|practitioners)\/([a-z0-9][a-z0-9-]{0,119})\/?$/.exec(window.location.pathname)
  if (detail) {
    const service = state.services?.find(item=>item.slug===detail[2])
    const person = state.practitioners?.find(item=>item.slug===detail[2])
    const loading = detail[1]==='services'?!state.services&&!state.serviceError:!state.practitioners&&!state.practitionerError
    const error = detail[1]==='services'?state.serviceError:state.practitionerError
    if (loading||error) return <section className="detail-page"><CatalogueFeedback loading={loading} error={error} empty={false} label={detail[1]} onRetry={retry}/></section>
    if (detail[1]==='services'&&service) return <ServiceDetails service={service} practitioners={state.practitioners??[]}/>
    if (detail[1]==='practitioners'&&person) return <PractitionerDetails person={person} photo={<PractitionerPhoto person={person}/>}/>
    return <section className="detail-page"><h1>Profile or service unavailable</h1><p>This item may no longer be published.</p><a href="/">Return to Wellnexa</a></section>
  }
  return <>
    <section className="catalogue-section" id="services" aria-labelledby="services-title">
      <p className="eyebrow">Explore your options</p><h2 id="services-title">Our services</h2>
      <CatalogueFeedback label="services" loading={!state.services && !state.serviceError} error={state.serviceError} empty={state.services?.length === 0} onRetry={retry}/>
      <div className="catalogue-grid">{state.services?.map(service => <article className="catalogue-card" key={service.slug}>
        {service.category && <p className="card-eyebrow">{service.category}</p>}<h3><a className="profile-link" href={`/services/${service.slug}`}>{service.name}</a></h3>
        {service.public_summary && <p className="card-summary">{service.public_summary}</p>}
        <ServicePrice service={service}/>
        <a className="card-link" href={`/services/${service.slug}`}>About this service →</a>
      </article>)}</div>
    </section>
    <section className="catalogue-section" id="practitioners" aria-labelledby="practitioners-title">
      <p className="eyebrow">Get to know the collective</p><h2 id="practitioners-title">Our practitioners</h2>
      <CatalogueFeedback label="practitioners" loading={!state.practitioners && !state.practitionerError} error={state.practitionerError} empty={state.practitioners?.length === 0} onRetry={retry}/>
      <div className="catalogue-grid practitioner-grid">{state.practitioners?.map(person => <article className="catalogue-card practitioner-compact" key={person.slug}>
        <PractitionerPhoto key={person.image_version ?? 'no-photo'} person={person}/>
        <h3><a className="profile-link" href={`/practitioners/${person.slug}`}>{person.public_name}</a></h3>{(person.public_title || person.discipline) && <p className="card-eyebrow">{person.public_title || person.discipline}</p>}
        {person.credentials && <p>{person.credentials}</p>}
        <a className="card-link" href={`/practitioners/${person.slug}`}>Meet {person.public_name} →</a>
      </article>)}</div>
    </section>
  </>
}

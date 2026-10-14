import { useEffect, useState } from 'react'
import { site } from '../config/site'
import { fetchCatalogue, type PublicPractitioner, type PublicService } from './api'

type CatalogueState = { services: PublicService[] | null; practitioners: PublicPractitioner[] | null; serviceError: boolean; practitionerError: boolean }
const initial: CatalogueState = { services: null, practitioners: null, serviceError: false, practitionerError: false }
function portalLink(path: string, practitionerId?: number) {
  const url = new URL(path, site.portalUrl.endsWith('/') ? site.portalUrl : site.portalUrl + '/')
  if (practitionerId) url.searchParams.set('practitioner_id', String(practitionerId))
  return url.href
}
function CatalogueFeedback({ loading, error, empty, label, onRetry }: { loading: boolean; error: boolean; empty: boolean; label: string; onRetry: () => void }) {
  if (error) return <div className="catalogue-feedback" role="alert"><p>We couldn’t load our {label} right now.</p><button type="button" onClick={onRetry}>Try again</button> <a href={site.portalUrl}>Visit the clinic portal</a></div>
  if (loading) return <p role="status">Loading {label}…</p>
  if (empty) return <p className="catalogue-empty">{label === 'services' ? 'Services' : 'Practitioner profiles'} will appear here as they are published.</p>
  return null
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
  return <>
    <section className="catalogue-section" id="services" aria-labelledby="services-title">
      <p className="eyebrow">Explore your options</p><h2 id="services-title">Our services</h2>
      <CatalogueFeedback label="services" loading={!state.services && !state.serviceError} error={state.serviceError} empty={state.services?.length === 0} onRetry={retry}/>
      <div className="catalogue-grid">{state.services?.map(service => <article className="catalogue-card" key={service.slug}>
        {service.category && <p className="card-eyebrow">{service.category}</p>}<h3>{service.name}</h3>
        {service.public_summary && <p className="card-summary">{service.public_summary}</p>}
        {service.durations.length > 0 && <p className="service-options">{[...new Set(service.durations.map(option => option.minutes))].join(' / ')} minutes · From {new Intl.NumberFormat('en-CA', { style: 'currency', currency: 'CAD' }).format(Math.min(...service.durations.map(option => option.price_cents)) / 100)} CAD</p>}
        <a className="card-link" href={portalLink(`services/${encodeURIComponent(service.slug)}/book`)}>Explore availability <span aria-hidden="true">↗</span></a>
      </article>)}</div>
    </section>
    <section className="catalogue-section" id="practitioners" aria-labelledby="practitioners-title">
      <p className="eyebrow">Get to know the collective</p><h2 id="practitioners-title">Our practitioners</h2>
      <CatalogueFeedback label="practitioners" loading={!state.practitioners && !state.practitionerError} error={state.practitionerError} empty={state.practitioners?.length === 0} onRetry={retry}/>
      <div className="catalogue-grid">{state.practitioners?.map(person => <article className="catalogue-card" key={person.slug}>
        <h3>{person.public_name}</h3>{(person.public_title || person.discipline) && <p className="card-eyebrow">{person.public_title || person.discipline}</p>}
        {person.credentials && <p>{person.credentials}</p>}{person.summary && <p className="card-summary">{person.summary}</p>}
        {person.services.length > 0 && <ul className="practitioner-services">{person.services.map(service => <li key={service.slug}><a href={portalLink(`services/${encodeURIComponent(service.slug)}/book`, person.booking_practitioner_id ?? undefined)}>{service.name}</a></li>)}</ul>}
        {person.booking_practitioner_id && <a className="card-link" href={portalLink('', person.booking_practitioner_id)}>Find an appointment <span aria-hidden="true">↗</span></a>}
      </article>)}</div>
    </section>
  </>
}

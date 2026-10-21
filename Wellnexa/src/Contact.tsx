import { useEffect, useState } from 'react'
import { site } from './config/site'

export function Contact({ french }: { french: boolean }) {
  const [contact, setContact] = useState<{ email: string | null; phone: string | null } | null>(null)
  const [failed, setFailed] = useState(false)
  useEffect(() => {
    const controller = new AbortController()
    void fetch('/api/public/contact', { signal: controller.signal, credentials: 'omit' })
      .then(async response => {
        if (!response.ok) throw new Error('Unavailable')
        const { data } = await response.json()
        if (!data || (data.email != null && (typeof data.email !== 'string' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email))) || (data.phone != null && typeof data.phone !== 'string')) throw new Error('Invalid contact')
        setContact({ email: data.email ?? null, phone: data.phone ?? null })
      }).catch(() => { if (!controller.signal.aborted) setFailed(true) })
    return () => controller.abort()
  }, [])
  const portal = new URL(site.portalUrl)
  portal.searchParams.set('lang', french ? 'fr' : 'en')
  const client = new URL('client', portal)
  client.searchParams.set('lang', french ? 'fr' : 'en')
  const phone = contact?.phone?.replace(/[^+0-9]/g, '')
  return <section className="welcome" aria-labelledby="contact-title">
    <p className="eyebrow">{site.descriptor}</p>
    <h1 id="contact-title">{french ? 'Contactez ' : 'Contact '}<span>{site.name}</span></h1>
    <p className="introduction">{french ? 'Nous sommes là pour vous aider à trouver vos soins.' : 'We’re here to help you find your care.'}</p>
    {!contact && !failed && <p role="status">{french ? 'Chargement des coordonnées…' : 'Loading contact details…'}</p>}
    {contact?.email && <p><a href={`mailto:${contact.email}`}>{contact.email}</a></p>}
    {contact?.phone && <p>{phone ? <a href={`tel:${phone}`}>{contact.phone}</a> : contact.phone}</p>}
    {(failed || (contact && !contact.email && !contact.phone)) && <p className="status">{french ? 'Les coordonnées de la clinique sont indisponibles pour le moment. Consultez les profils des praticiens dans le portail pour leurs coordonnées publiques.' : 'Clinic contact details are unavailable at the moment. Visit practitioner profiles in the portal for their published contact details.'}</p>}
    <p className="status">{french ? 'Pour trouver un rendez-vous, consultez les disponibilités. Connectez-vous au portail client pour consulter vos rendez-vous existants.' : 'To find an appointment, browse availability. Sign in to the client portal to review your existing appointments.'}</p>
    <div className="contact-actions"><a className="button-link" href={portal.href}>{french ? 'Consulter les soins et les praticiens' : 'Browse treatments and practitioners'}</a><a href={client.href}>{french ? 'Mes rendez-vous' : 'My appointments'}</a></div>
  </section>
}

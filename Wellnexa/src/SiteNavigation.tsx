import { useLayoutEffect, useRef, useState } from 'react'
import { site } from './config/site'

const sections = ['services', 'practitioners', 'faq'] as const

export function SiteNavigation() {
  const headerRef = useRef<HTMLElement>(null)
  const [activeSection, setActiveSection] = useState('')
  const path = window.location.pathname
  const home = path === '/'

  useLayoutEffect(() => {
    const header = headerRef.current!
    const main = document.querySelector('main')!
    let frame = 0
    let initialAnchor = home ? document.getElementById(window.location.hash.slice(1)) : null

    const update = () => {
      frame = 0
      if (!home) return
      const threshold = header.getBoundingClientRect().bottom + 9
      let current = ''
      for (const id of sections) {
        const section = document.getElementById(id)
        if (section && section.getBoundingClientRect().top <= threshold) current = id
      }
      // The final section may be too short to reach the header at the end of the page.
      if (window.scrollY > 0 && window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 2) current = 'faq'
      setActiveSection(current)
    }
    const schedule = () => {
      if (!frame) frame = requestAnimationFrame(update)
    }
    const measure = () => {
      document.documentElement.style.setProperty('--site-header-height', `${header.getBoundingClientRect().height}px`)
      // Keep a direct section link aligned while the catalogue loads, until the visitor takes control.
      initialAnchor?.scrollIntoView({ behavior: 'instant', block: 'start' })
      schedule()
    }
    const releaseAnchor = () => { initialAnchor = null }
    const observer = new ResizeObserver(measure)
    observer.observe(header)
    observer.observe(main)
    measure()
    window.addEventListener('scroll', schedule, { passive: true })
    window.addEventListener('resize', schedule)
    window.addEventListener('hashchange', releaseAnchor)
    const interactions = ['wheel', 'touchstart', 'pointerdown', 'keydown'] as const
    for (const event of interactions) window.addEventListener(event, releaseAnchor, { passive: true })
    return () => {
      observer.disconnect()
      cancelAnimationFrame(frame)
      window.removeEventListener('scroll', schedule)
      window.removeEventListener('resize', schedule)
      window.removeEventListener('hashchange', releaseAnchor)
      for (const event of interactions) window.removeEventListener(event, releaseAnchor)
      document.documentElement.style.removeProperty('--site-header-height')
    }
  }, [home])

  const current = (section: string) => home
    ? activeSection === section ? 'location' : undefined
    : path === `/${section}` || path === `/${section}/` || path.startsWith(`/${section}/`) ? 'page' : undefined

  return <header className="site-header" ref={headerRef}>
    <a className="brand" href="/" aria-label={`${site.name} home`}>
      <span className="brand-mark" aria-hidden="true">W</span><span>{site.name}</span>
    </a>
    <nav className="main-nav" aria-label="Main navigation">
      <a href="/#services" aria-current={current('services')}>Services</a>
      <a href="/#practitioners" aria-current={current('practitioners')}>Practitioners</a>
      <a href="/#faq" aria-current={current('faq')}>FAQ</a>
      <a href="/join" aria-current={current('join')}>Join the collective</a>
      <a href="/contact" aria-current={current('contact')}>Contact</a>
      <a className="portal-link" href={site.portalUrl}>Clinic portal <span aria-hidden="true">↗</span></a>
    </nav>
  </header>
}

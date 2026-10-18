import { site } from './config/site'
import './App.css'
import { PublicCatalogue } from './catalogue/PublicCatalogue'
import { Contact } from './Contact'
import { Join } from './Join'
import { Faq } from './Faq'
import { SiteNavigation } from './SiteNavigation'

function App() {
  const contactPage = /^\/contact\/?$/.test(window.location.pathname)
  const joinPage = /^\/join\/?$/.test(window.location.pathname)
  const detailPage = /^\/(services|practitioners)\//.test(window.location.pathname)
  const french = contactPage && new URLSearchParams(window.location.search).get('lang') === 'fr'
  document.documentElement.lang = french ? 'fr' : 'en'
  document.title = contactPage ? `${french ? 'Contactez' : 'Contact'} ${site.name}` : site.name
  return (
    <div className="site-shell">
      <a className="skip-link" href="#main-content">Skip to content</a>
      <SiteNavigation />
      <main id="main-content">
        {contactPage ? <Contact french={french} /> : joinPage ? <Join/> : <>{!detailPage&&<section className="welcome" aria-labelledby="welcome-title">
          <p className="eyebrow">{site.descriptor}</p>
          <h1 id="welcome-title">Welcome to <span>{site.name}.</span></h1>
          <p className="introduction">A new space for connected wellness.</p>
          <p className="status">Get to know the collective, explore our published services, and find the care that feels right for you.</p>
          <a className="button-link" href={site.portalUrl}>Visit the clinic portal <span aria-hidden="true">↗</span></a>
        </section>}
        <PublicCatalogue />
        {!detailPage&&<section className="catalogue-section" aria-labelledby="booking-guide"><p className="eyebrow">Your next step</p><h2 id="booking-guide">How booking works</h2><ol className="booking-steps"><li>Explore a service and meet your practitioner.</li><li>Choose your practitioner, location and available time in the clinic portal.</li><li>Sign in, review the price and any travel fee, then confirm your appointment.</li></ol><p>Browsing a time does not reserve it. On-site eligibility is checked during booking.</p><a href="/join">Are you a practitioner? Explore joining Wellnexa →</a></section>}
        {!detailPage&&<Faq/>}</>}
      </main>
      <footer className="site-footer"><span>{site.name}</span><a href={`/contact?lang=${french ? 'fr' : 'en'}`}>{french ? 'Contactez-nous' : 'Contact us'}</a><span>{site.descriptor}</span></footer>
    </div>
  )
}

export default App

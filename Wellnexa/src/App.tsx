import { site } from './config/site'
import './App.css'
import { PublicCatalogue } from './catalogue/PublicCatalogue'

function App() {
  return (
    <div className="site-shell">
      <a className="skip-link" href="#main-content">Skip to content</a>
      <header className="site-header">
        <a className="brand" href="/" aria-label={`${site.name} home`}>
          <span className="brand-mark" aria-hidden="true">W</span>
          <span>{site.name}</span>
        </a>
        <a className="portal-link" href={site.portalUrl}>Clinic portal <span aria-hidden="true">↗</span></a>
      </header>
      <main id="main-content">
        <section className="welcome" aria-labelledby="welcome-title">
          <p className="eyebrow">{site.descriptor}</p>
          <h1 id="welcome-title">Welcome to <span>{site.name}.</span></h1>
          <p className="introduction">A new space for connected wellness.</p>
          <p className="status">Get to know the collective, explore our published services, and find the care that feels right for you.</p>
          <a className="button-link" href={site.portalUrl}>Visit the clinic portal <span aria-hidden="true">↗</span></a>
        </section>
        <PublicCatalogue />
      </main>
      <footer className="site-footer"><span>{site.name}</span><span>{site.descriptor}</span></footer>
    </div>
  )
}

export default App

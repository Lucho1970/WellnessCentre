import { PractitionerEnquiryForm } from './PractitionerEnquiryForm'
export function Join() {
  return <section className="detail-page"><p className="eyebrow">For independent practitioners</p><h1>Your practice.<br/><span>A connected collective.</span></h1>
    <p className="introduction">Bring your expertise to Wellnexa.</p><p className="status">We welcome conversations with wellness practitioners who want a public presence, simpler booking and a shared place to support their clients.</p>
    <div className="catalogue-grid"><article className="catalogue-card"><h2>Be discoverable</h2><p>A public practitioner profile with your biography, credentials, photo, services and social links.</p></article><article className="catalogue-card"><h2>Organize your care</h2><p>Manage availability, service assignments and travel preferences in the clinic portal. Clients can review appointments and complete assigned forms.</p></article><article className="catalogue-card"><h2>Start with support</h2><p>Use approved intake templates, record payments and issue session receipts through the clinic workflow.</p></article></div>
    <h2>Let’s explore the fit</h2><p className="detail-copy">Tell us about your discipline, credentials, services and where you practise. We can discuss clinic onboarding, your public profile and how your services would be offered. Membership terms and participation are confirmed individually.</p>
    <a className="button-link" href="#enquiry-form">Ask about joining →</a><p><a href="/#practitioners">Meet the collective</a></p><PractitionerEnquiryForm/>
  </section>
}

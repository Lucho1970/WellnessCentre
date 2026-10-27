import { PractitionerEnquiryForm } from './PractitionerEnquiryForm'
import { site } from './config/site'
export function Join() {
  return <section className="detail-page"><p className="eyebrow">For independent practitioners</p><h1>Your practice.<br/><span>A connected collective.</span></h1>
    <p className="introduction">Bring your expertise to Wellnexa.</p><p className="status">We welcome conversations with wellness practitioners who want a public presence, simpler booking and a shared place to support their clients.</p>
    <div className="catalogue-grid"><article className="catalogue-card"><h2>Be discoverable</h2><p>A public practitioner profile with your biography, credentials, photo, services and social links.</p></article><article className="catalogue-card"><h2>Organize your care</h2><p>Manage availability, service assignments and travel preferences in the clinic portal. Clients can review appointments and complete assigned forms.</p></article><article className="catalogue-card"><h2>Start with support</h2><p>Use approved intake templates, record payments and issue session receipts through the clinic workflow.</p></article></div>
    <h2>Let’s explore the fit</h2><p className="detail-copy">Tell us about your discipline, credentials, services and where you practise. We can discuss clinic onboarding, your public profile and how your services would be offered. Membership terms and participation are confirmed individually.</p>
    {import.meta.env.VITE_PRACTITIONER_APPLICATIONS_ENABLED === 'true' && <article className="catalogue-card"><h2>Apply to join Wellnexa</h2><p>Sign in through the practitioner portal to submit your application or check its review. Clinic approval and account activation are separate steps. Eligible new memberships receive a six-month free trial when their approved account is activated; SMS funding is assigned separately.</p><a className="button-link" href={new URL('staff/apply',site.portalUrl).href}>Apply or check your application →</a></article>}
    <a className="button-link" href="#enquiry-form">Ask about joining →</a><p><a href="/#practitioners">Meet the collective</a></p><PractitionerEnquiryForm/>
  </section>
}

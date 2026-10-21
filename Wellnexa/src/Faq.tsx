const questions = [
  ['How do I book an appointment?', 'Explore a service or practitioner, then continue to the clinic portal to choose an available time. Sign in, review the details and price, and confirm. Browsing a time does not reserve it.'],
  ['Where does my appointment take place?', 'Check the delivery options shown for your service. For an on-site appointment, your practitioner travels to the address you provide. The portal checks whether that address is within the practitioner’s service area.'],
  ['Why does the price say “From”?', 'Prices can vary by practitioner and appointment duration. The starting price is a guide; review the price for your selection in the portal before confirming.'],
  ['Is there an additional travel fee?', 'Some practitioners include travel within a set distance and charge a flat fee beyond it. The booking portal checks your address and displays any applicable fee. Addresses beyond the practitioner’s maximum distance cannot be booked through that assignment.'],
  ['Do I need a client account?', 'You can explore services and practitioners without signing in. Sign in to confirm a booking and use your client portal to review appointments, complete assigned forms and access issued receipts.'],
  ['Can I receive a receipt?', 'When payment has been recorded and a receipt issued by the clinic, you can access it in your client portal. Ask your practitioner or the clinic if you need help with an issued receipt.'],
  ['How do I prepare for my appointment?', 'Read the preparation information on the service page and any instructions provided with your booking. Your practitioner can discuss individual needs before the session.'],
  ['I am a practitioner. How can I join?', 'Visit Join the collective and send a practitioner enquiry. Tell us about your discipline, credentials and service area. An administrator will review your enquiry; submitting it does not create an account or approve membership.'],
]
export function Faq() {
  return <section className="catalogue-section" id="faq" aria-labelledby="faq-title"><p className="eyebrow">A little clarity</p><h2 id="faq-title">Frequently asked questions</h2><div className="faq-list">{questions.map(([question,answer])=><details key={question}><summary>{question}</summary><p>{answer}</p></details>)}</div><p>Still have a question? <a href="/contact">Contact the clinic</a> or <a href="/join">ask about joining the collective</a>.</p></section>
}

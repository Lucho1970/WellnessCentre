# Wellnexa communications and commerce roadmap

Confirmed product direction: 15 October 2026. All capabilities below are planned, not an implementation or hosted acceptance claim. This supplements the authoritative [Master Requirements](MASTER_REQUIREMENTS.md), specifically MSG-01–03, NOT-01–02, FIN-01–05 and FIN-09–10.

## Practitioner messaging

A practitioner opens a colleague's profile and selects **Message**. The portal opens an existing authorized conversation or starts one with explicit participants and clinic/practice context. Personal phone numbers and email addresses need not be exposed. A participant's global identity must not implicitly share conversations or client information across clinics.

Recommended experience:

- **Messages navigation and unread badge:** a persistent destination available from any portal page, on mobile and desktop.
- **Dashboard preview:** unread count and recent conversations with sender, time and a link to the inbox. Show minimal content by default, especially when calendar privacy mode is active. The dashboard is a view of the same inbox, not separate message storage.
- **Email and optional SMS alerts:** a generic notice such as “You have a new message in your Wellnexa portal” and a safe link. Authenticate and recheck authorization before opening the conversation. Do not include message bodies, health details or personal contact information in alerts.
- **Notification preferences:** verified destinations, channel opt-in, quiet hours/digests, retry and duplicate controls, plus clear failure states. Portal unread state remains independent of whether an external alert was delivered. Only an authorized portal read marks a conversation read.

Start with asynchronous one-to-one text conversations. Group conversations, attachments, typing/presence indicators, external SMS/email replies and cross-clinic networking require separately defined scope. Practitioner-to-practitioner communication does not itself authorize sharing a client's records or discussing identifiable care information.

## Client messaging without sharing personal numbers

Clients use **Message practitioner** from an eligible profile, appointment or established relationship. Replies remain in the same portal conversation. A client may have separate conversations with different practitioners; neither practitioner sees the other's conversations. Administrators receive no automatic access to message bodies. Any delegated handling must be explicit and auditable.

Before implementation, define whether a prospective client can send an enquiry before booking, who can initiate conversations, what happens when a practitioner leaves or becomes unavailable, and the approved response/retention policy. Preserve permitted history while enforcing changes to membership and access; do not leave former staff access active merely to retain records.

Acceptance must cover guessed conversation IDs, participant removal, wrong-clinic access, account linking, blocked/inactive recipients, rate limits, unread updates, duplicate notifications and failed delivery. Display response expectations and state that messaging is not an emergency channel. No automatic clinical advice or guaranteed instant response is implied.

## Official receipts for benefits reimbursement

Produce an official PDF receipt from recorded services and payments. Candidate fields include a unique receipt number, issuer/business details, client name, actual treating practitioner, applicable professional designation and registration, service date/type/duration, itemized charge, applicable taxes, payment date/method, amount paid and any remaining balance. These are product requirements to validate with the relevant professions and insurers, not a legal or insurer-approved universal template.

Snapshot the credentials applicable to the rendered service; do not pull an unrelated global credential or change historical receipts after a profile edit. Reissues, corrections, voids and refunds retain their links and audit history. An invoice or unpaid balance must never be presented as a paid receipt. Reimbursement remains subject to the client's coverage and insurer decision; direct claims submission is a separate integration.

Use digital delivery first:

1. The authorized practitioner records the completed service and actual payment, including manually collected payments where enabled.
2. The system generates the receipt once, with duplicate prevention, and makes it available to the authorized client in the portal.
3. Send a generic email notice and secure portal link. Optional consented receipt attachments require a separate delivery/privacy decision.
4. Offer Download PDF and Print. On-Site practitioners can issue from a phone; the client or practitioner can print later through their device's standard print dialogue. Portable-printer integration and offline issuance are not required for the first release.

Acceptance includes multiple credentials, the actual treating practitioner, partial/unpaid transactions, refunds, historical price/credential changes, duplicate requests, wrong-client access, mobile downloads and legible printed output. This can precede integrated online payments by relying on audited manual payment recording.

## Product listings and distributor shipping

Wellnexa should support published products with a purchase path, including products sold by the clinic, products sold by individual practitioners, and approved externally fulfilled products. Each listing must identify the seller, price/currency, applicable availability, shipping source, support and return terms. Keep retail transactions separate from clinical service receipts and benefits claims.

Suggested stages:

1. **Approved storefront links:** link to the appropriate distributor/practitioner storefront, with clear notice that purchasing and fulfilment take place there. Do not copy price or stock claims without an approved, maintained data source.
2. **Fullscript feasibility and account model:** confirm Canadian practitioner eligibility and catalogue, whether accounts are practitioner-owned or clinic-owned, recommendation/purchase restrictions, attribution and commercial terms, data permissions and supported checkout/return workflows.
3. **Reviewed integration:** use an approved API or embedded experience only for supported capabilities. Confirm account linking, minimal client-data sharing, consent, disconnect/revocation, product availability, order status and reconciliation. Never treat an outbound click as a completed purchase or duplicate the distributor's charge/refund.
4. **Wellnexa managed sales:** introduce local stock, checkout, payment collection, taxes, refunds, shipping and inventory only for approved sellers/workflows. A combined basket across sellers is a separate decision, not a prerequisite for distributor links.

Fullscript is the named integration candidate, not a selected vendor or completed partnership. Its official API page describes embedded tools, REST endpoints, redirect options and an integration review before production. This does not establish that a custom embedded checkout, unrestricted public catalogue or every advertised capability is available to Wellnexa in Canada. Canadian dispensary purchases ship to Canadian addresses under the published shipping policy.

Official sources checked 15 October 2026:

- [Fullscript API and integration options](https://fullscript.com/fs-api)
- [API access and documentation](https://support.fullscript.com/articles/api-documentation)
- [Eligible shipping addresses](https://support.fullscript.com/articles/eligible-shipping-addresses)

Acceptance includes unpublished/ineligible products, seller attribution, stale price/stock, Canadian shipping restrictions, external checkout handoff, order/refund ownership, duplicate callbacks if used, and access revocation. No purchasing feature is launched on the strength of a catalogue page alone.

## Proposed delivery order

Build a common conversation and notification foundation, then practitioner colleague messaging, followed by relationship-scoped client messaging. Receipt generation can progress separately once invoice and manual payment records are reliable. Explore an approved Fullscript storefront link in parallel; deeper commerce integration follows feasibility and account decisions. This is a dependency-based recommendation, not a delivery-date commitment or authorization to enable the modules in production.

## Documentation validation

This change records scope only. Review confirmed the new requirement identifiers are unique, the local Markdown links resolve, and the patch passes `git diff --check`. No application source, schema or hosted configuration changes are included; runtime builds, browser regression and hosted acceptance are not evidence for this documentation-only update.

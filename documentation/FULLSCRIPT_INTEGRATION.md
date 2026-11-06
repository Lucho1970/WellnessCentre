# Fullscript integration roadmap

**Status:** proposal for a later release; no Fullscript integration, credentials, patient data transfer, webhook or browser embed is enabled. Reviewed for repository inclusion on 6 November 2026. This replaces the 5 November working draft, whose illustrative token-storage, OAuth and webhook snippets were not suitable for implementation. Fullscript and the accountable Wellnexa custodian must approve the actual integration and data flow before development involving patient information.

## Product decision to make first

Choose one narrow use case with practitioners and the clinical owner: a practitioner opens Fullscript to prepare a supplement plan for a patient, or a patient sees an approved catalog/plan in the portal. Decide whether a redirect, Fullscript's embed, or selected server API endpoints meet that use case. Do not add labs, clinical recommendations, order history synchronization, adherence monitoring or automated messages by implication. The clinic must decide who owns each resulting record, which professional types may use the feature, what the patient sees, and whether a practitioner has a Fullscript account independent of Wellnexa.

Fullscript describes OAuth, embeddable components, webhooks and REST endpoints as separate integration parts. It requires sandbox work and an integration review before production access; some production endpoints need separate approval. Use the current [Fullscript API overview](https://fullscript.com/fs-api), [technical reference](https://fullscript.dev/technical-reference/going-to-production) and [restricted-endpoint guidance](https://fullscript.dev/technical-reference/restricted-endpoints) to select the actual product and endpoints. Verify the Canadian region, contract, fees, current API terms and production approval with Fullscript rather than relying on a sample URL or assumed OAuth grant.

## Privacy and clinical gate

Before any patient or practitioner data leaves Wellnexa, record the purpose, legal authority and necessary fields for each direction: account link, patient match, plan, order, webhook and optional notification. Obtain custodian/privacy, clinical and security approval for the vendor assessment and contract, geography and support access, retention/deletion, incident terms and onward processing. Update the endpoint/subprocessor register, data inventory, flow diagram, privacy notice, PIA/threat assessment and practitioner/patient workflow. Do not transmit clinical notes, diagnosis, home address or the full patient chart merely to open a catalog. Fullscript's API licence says development testing must not send data concerning actual people; use synthetic identities and records in sandbox. See the [Fullscript API licence](https://fullscript.dev/static/pdfs/Fullscript_API_License_Agreement_Feb15-2025.pdf) and repository controls GOV-05, PRIV-02, SEC-08 and REL-05.

## Proposed technical design after approval

1. **Identity and consent:** Keep Wellnexa's existing staff/client authentication and clinic-scoped authorization. Link an approved practitioner to the correct Fullscript practitioner account through the provider's documented OAuth flow. Map a patient only after checking the current clinic, care assignment, patient restriction and approved disclosure purpose. Do not treat an email match or historical appointment as authorization.
2. **Secrets and tokens:** Keep client secrets and refresh credentials in a private server-side credential store with encryption, access controls and rotation. Store only minimal external identifiers and linkage state in application tables, scoped by clinic and practitioner. Never place reusable tokens in `users`, browser storage, URLs, logs or a public bundle. Document provider token lifetimes, renewal, revocation and departure cleanup before implementation.
3. **API and browser boundary:** Use the exact Canadian sandbox/production hosts and permissions supplied for the approved Fullscript application. A browser component, if selected, receives only a short-lived, purpose-bound session created by the backend after a fresh server authorization check. Restrict CSP and script sources to reviewed origins; make component loading optional so its failure does not block ordinary clinic care. Prevent external content from receiving unrelated PHI.
4. **Webhooks:** Accept only documented events needed by the use case. Verify the provider's signature against the raw request body and approved secret before parsing or writing. Bound body size, reject stale/replayed/unknown events, deduplicate by provider event ID, store minimal event metadata, and queue any work. Recheck current clinic, recipient, preferences and patient restrictions before a message or record update. Provider acceptance is not proof of delivery.
5. **Records and messages:** Define which Fullscript results become clinic records and who can read them. Keep supplement instructions in the protected portal. Any email/SMS must be separately classified, minimized and governed by recipient verification, preferences and CASL review. Preserve audit correlation without tokens, clinical bodies or identifying webhook URLs.

## Implementation sequence and acceptance

| Stage | Deliverable and gate |
| --- | --- |
| Discovery | Approved use case, professions, provider account model, data fields, vendor/contract and endpoint register. |
| Sandbox | Synthetic-only OAuth/linking, selected workflow and webhook prototype with documented failure/retry behavior; no real identities. |
| Security/privacy review | Threat model, least-privilege scopes, secret storage, signature verification, revocation, data deletion, consent/restriction and audit tests. |
| Local and hosted tests | Cross-clinic and unassigned-patient denials, stale session, concurrent revoke, duplicate/replayed webhook, provider outage, token rotation, rollback and accessibility in both languages. |
| Release review | Fullscript production approval and any restricted-endpoint grants, custodian/privacy, clinical, security/operations and release-owner sign-offs, backed-up migration, artifact inspection and monitored rollout. |

**Current decision:** documentation only. No database schema, application code, dependency, provider permission or external data flow changes are authorized by this roadmap. The integration remains outside the initial release until the gates above are complete.

## Repository control impact for this documentation change

**Reviewed:** 6 November 2026 by Codex against `main` `6cb3376` before commit. The statuses describe this roadmap only; they do not replace the repository's application compliance audit. The custodian, privacy/legal, clinical, security/operations and release owner have not approved a Fullscript integration.

| Control | Status for this change | Evidence and next owner action |
| --- | --- | --- |
| GOV-01 | Partial | Custodian/provider roles are proposed; custodian and counsel decide actual responsibilities. |
| GOV-02 | Unverified | Privacy/legal owner reviews current applicable law and profession rules at design. |
| GOV-03 | Unverified | Privacy/legal owner assesses PIPEDA and any cross-border processing. |
| GOV-04 | Partial | Intended use is restricted to a decision; clinical owner assesses any expanded function. |
| GOV-05 | Missing | Privacy/security owners complete data inventory, flow, PIA and threat assessment before transfer. |
| PRIV-01 | Unverified | Custodian updates clinic notice if the approved flow needs it. |
| PRIV-02 | Partial | Minimum-fields and purpose gate stated; privacy owner approves actual authority/consent. |
| PRIV-03 | Partial | Current assignment/restriction recheck specified; engineering must implement and test. |
| PRIV-04 | Unverified | Clinical/privacy owners define minor/representative handling before use. |
| PRIV-05 | Unverified | Privacy owner maps provider-held data to patient access/correction process. |
| PRIV-06 | Unverified | Privacy/legal owner defines record and provider retention/deletion. |
| PRIV-07 | Unverified | Privacy lead adds provider incidents to breach runbook. |
| AUTH-01 | Unverified | Security owner verifies exact OAuth flow and existing Wellnexa identity boundary. |
| AUTH-02 | Partial | Clinic/care-assignment checks specified, not implemented. |
| AUTH-03 | Unverified | Security owner validates session and provider revocation. |
| AUTH-04 | Partial | Server-only credential design specified, not implemented. |
| AUTH-05 | Missing | Security owner inventories and approves exact API grants. |
| SEC-01 | Partial | No credentials in this roadmap; security scans future artifacts. |
| SEC-02 | Unverified | Operations verifies provider/API TLS, storage and backup controls. |
| SEC-03 | Unverified | Security reviews future embed CSP, CORS and cache behavior. |
| SEC-04 | Partial | No executable sample; future API/webhook validation requires tests. |
| SEC-05 | Partial | Bounds/replay tests specified, not implemented. |
| SEC-06 | Partial | Audit requirements specified, not implemented. |
| SEC-07 | Partial | Token/body redaction specified, not implemented. |
| SEC-08 | Missing | Provider endpoint/subprocessor and contract approval required before data flow. |
| SEC-09 | Unverified | Privacy owner classifies any future patient messages and opt-outs. |
| DEV-01 | Partial | Documentation-only task on its own branch; source/package files unchanged. |
| DEV-02 | Not applicable | No code is generated or modified; unsafe working-draft snippets removed. |
| DEV-03 | Not applicable | No dependency, lockfile or toolchain change. |
| DEV-04 | Not applicable | No executable code or UI change; future implementation requires full gates. |
| DEV-05 | Not applicable | No UI change; future embed needs bilingual accessibility review. |
| DEV-06 | Not applicable | No runtime/performance change; future integration needs volume limits. |
| REL-01 | Partial | This dated impact ledger covers this document; named owner decisions remain open. |
| REL-02 | Not applicable | Documentation-only diff; link and content inspection recorded in commit. |
| REL-03 | Not applicable | No package or hosted release artifact. |
| REL-04 | Missing | No real-data or Fullscript release decision; keep integration unavailable. |
| REL-05 | Partial | No production, schema, permission or data-flow change; future approvals are listed. |
| REL-06 | Partial | Document records scope and outstanding gates; release owner must report future implementation separately. |

RELEASE STATUS: NOT READY FOR RELEASE

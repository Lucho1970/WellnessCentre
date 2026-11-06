# Wellnexa record operations — controlled-process draft

**Draft for custodian, Ontario health privacy/legal and clinical approval, 6 November 2026.** No new software workflow in this document is a substitute for approval or staff training. Do not use a general upload, email attachment or practitioner bulk export as a workaround. This document is not permission to accept real PHI into development or the pilot.

## A. Records from an outside practice

1. The privacy lead verifies the source custodian, patient identity/capacity or lawful representative, purpose, recipient and legal authority for the proposed disclosure. The practitioner bringing a client does not automatically transfer a prior record.
2. The responsible clinician requests only specific material needed for Wellnexa care. Record the source, date range, record classes and why each is needed. Preserve the source custodian's independent obligations.
3. Use a separately approved secure transfer channel. An operator verifies file type/content, size, malware status, recipient, integrity hash and match to the correct clinic/client before intake. Keep the original transfer evidence and reject uncertain matches.
4. Record source, authority/consent wording and version, patient/representative, received items, operator, date, hash, restrictions and review outcome in a dedicated intake case. Do not turn a received file into a practitioner-visible clinic record until the responsible clinician approves it and care-team access is set.
5. Treat a later correction, withdrawal or source notification as a new case; preserve history. Reconcile backups and lawful disposal under the approved schedule.

**Software gap:** no approved private upload, malware-control, provenance case, review or authorized retrieval workflow exists yet. The pilot must not accept outside clinical files through ad hoc upload/email.

## B. Access and correction requests

1. Privacy lead logs the request date, channel, scope, requester, verification status and normal PHIPA response due date. Track any lawful extension, reason and notice separately; do not silently reset the clock.
2. Verify patient identity and any representative's purpose-specific authority. Resolve which record portions Wellnexa actually holds and any separate-practice custodian; coordinate without exposing another custodian's record.
3. Search all applicable clinic record classes, including notes, booking, forms, receipts, messages, audit and archived data, with a documented search plan. Review lawful severance/refusal and permitted fees with the privacy lead.
4. For corrections, preserve the original and record author, time, request, decision, reason, amended value or statement of disagreement, required notices and audit. A practitioner cannot delete an original note to implement correction.
5. Deliver through an approved protected channel after recipient verification. Record delivery evidence, response date, complaint information and closure. Escalate overdue cases.

**Software gap:** no complete case tracker, search/export review, protected delivery or correction addendum linking exists yet. Selected portal forms/receipts alone are insufficient. Controlled manual handling requires an approved secure record system and documented evidence; the present pilot is not such an approval.

## C. Retention, holds and disposal

The custodian and each relevant professional lead must approve a matrix by record class and profession: trigger date, minimum period, minor-age rule, overlapping duties, legal/complaint hold, backup expiry, transfer/closure, secure disposal and evidence. Apply the longest applicable duty to a mixed record until reviewed. Account closure, consent withdrawal, practitioner departure or a temporary access hold does not delete records. Test restoration so expired/disposed material is not returned to active use.

**Software gap:** no approved schedule or enforced hold/disposal engine exists. Do not configure a universal automatic deletion period.

## D. Urgent hold and formal practitioner departure

The new access-hold control is for immediate containment. The clinic administrator records a non-PHI reason category and tells the privacy/security lead. Only a super administrator restores access after review. The formal departure case must separately record the decision maker, effective time, identity/member/provider revocation, future bookings, incomplete care, patient communications, transfers and requests, record custody/retention, devices/copies, billing, legal holds, final verification and audit evidence. Assign an owner and due date to every action. Do not assume the practitioner owns or may export every client they brought to Wellnexa. Resolve what access/copies are needed for applicable professional duties through a documented legal review.

**Software gap:** no formal departure case tracker, automated reassignment, protected transfer or cross-provider revocation checklist is implemented. A reversible hold alone does not close an account or a practice.

Sources to recheck before approval: [PHIPA](https://www.ontario.ca/laws/statute/04p03), [IPC access/correction guidance](https://www.ipc.on.ca/en/health-organizations/access-and-correction), [IPC group-practice and abandoned-record guidance](https://www.ipc.on.ca/sites/default/files/legacy/2019/02/avoiding-abandoned-health-records-e.pdf). Profession-specific college standards may impose further duties.

RELEASE STATUS: NOT READY FOR RELEASE

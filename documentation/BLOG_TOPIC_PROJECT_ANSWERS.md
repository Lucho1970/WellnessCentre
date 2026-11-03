# Wellness blog topics: project answers and review plan

Prepared: 1 November 2026. Status: internal working document for review, not approved public copy.

This expands all 40 topics and 10 bonus ideas from the supplied **Blog Topic Ideas** text. It answers them against the Wellnexa public website, clinic/client/practitioner portals and PHP API at source revision `04475b5223483d9440948f419237ce2045365ea7`. Uncommitted work in the original checkout is outside this review. Repository implementation, configured clinic offerings and verified hosted behavior are separate facts; a feature in source is not proof that it is enabled or accepted on a live site.

**Status key:** **Supported in source** means a relevant implementation was inspected, with hosted validation still separate. **Partial — Needs review** means part of the answer exists but content, policy or validation is incomplete. **Needs review** means no sufficiently supported answer or completed workflow was established. Proposed handling below is a recommendation for review, not authorization or a delivery commitment. Owners are proposed roles; named owners and decisions remain to be assigned.

## Evidence used

These local references support the answers below. Older release notes describe historical checkpoints; their deployment and acceptance claims are not assumed current.

| Reference | Project evidence and limits |
| --- | --- |
| E1 | [Public service catalogue](PUBLIC_SERVICE_CATALOGUE.md), [catalogue service](../api/src/Service/CatalogService.php), [public catalogue UI](../Wellnexa/src/catalogue/PublicCatalogue.tsx): published descriptions, preparation, duration/price choices and assigned providers. |
| E2 | [Public practitioner directory](PUBLIC_PRACTITIONER_DIRECTORY.md), [practitioner details](../Frontend/src/guest/GuestPractitionerDetails.tsx): governed public profiles and booking entry. |
| E3 | [Guest booking](GUEST_PORTAL_BOOKING.md), [client booking](../Frontend/src/customer/CustomerBooking.tsx), [availability service](../api/src/Service/AvailabilityService.php), [booking service](../api/src/Service/BookingService.php): browse, authenticate, revalidate and confirm. Historical guest-routing limitations must be reassessed against current routing before deployment. |
| E4 | [Booking validation](BOOKING_VALIDATION_TESTS.md), [schedule intervals](../api/src/Service/ScheduleIntervals.php): time constraints, conflicts, transactional booking and retry safeguards. |
| E5 | [Appointment management UI](../Frontend/src/customer/CustomerAppointmentManager.tsx), [appointment links](APPOINTMENT_ACTION_LINKS.md), [cancellation policy](CANCELLATION_POLICY.md), [policy calculation](../api/src/Service/CancellationPolicy.php): ownership, policy snapshots, fee previews and explicit changes. |
| E6 | [Intake forms](CLIENT_INTAKE_FORMS.md), [forms service](../api/src/Service/ClientFormsService.php): versioned assignments and protected answers; default-off rollout and approval limits. |
| E7 | [Session receipts](SESSION_RECEIPTS.md), [receipt service](../api/src/Service/SessionReceiptService.php): completed-session manual payment recording and portal PDFs; default-off feature, no card charge or insurer submission. |
| E8 | [Staff notifications](STAFF_NOTIFICATIONS.md), [automated communications](AUTOMATED_COMMUNICATIONS.md), [notification worker](../api/src/Service/NotificationWorker.php): configured channels, preferences, dispatch checks and provider boundaries. |
| E9 | [Recurring appointments](RECURRING_APPOINTMENTS.md), [recurring service](../api/src/Service/RecurringBookingService.php): bounded series, preview/revalidation and all-or-nothing changes. Historical deployment status is not current hosted acceptance. |
| E10 | [On-Site booking](MOBILE_BOOKING.md), [On-Site distance fees](ONSITE_DISTANCE_FEES_RELEASE.md), [fee service](../api/src/Service/OnsiteFeeService.php): practitioner travel to a client; not video care. |
| E11 | [Practitioner qualifications](PRACTITIONER_QUALIFICATIONS.md), [qualification service](../api/src/Service/PractitionerQualificationService.php): private submissions, independent review and expiry warnings; no automatic issuer verification or qualification-based booking gate established. |
| E12 | [User manual](USER_MANUAL.md), [client management](CLIENT_MANAGEMENT.md), [client overview](CLIENT_OVERVIEW.md): appointment/client operations and visit outcomes; operational counts are not clinical outcome measures. |
| E13 | [Master requirements](MASTER_REQUIREMENTS.md), [communications and commerce roadmap](WELLNEXA_COMMUNICATIONS_COMMERCE_ROADMAP.md): target capabilities, including messaging and broader finance. Requirements are not implementation evidence. |
| E14 | [Compliance baseline](COMPLIANCE_AUDIT_2026-10-29.md), [quality-gate follow-up](FRONTEND_QUALITY_GATES.md), [pilot safeguards](PILOT_SAFETY_CONTROLS.md), [repository controls](../AGENTS.md): unresolved release evidence and controlled synthetic testing. |

## Main topics

### 1. How to Book Wellness Appointments Online Without Hassle

**Customer need:** Easy scheduling without a complicated system.

**Project answer — Supported in source:** Visitors can browse published services and practitioners, choose a configured duration and available time, then complete the authenticated client booking flow. The server checks the chosen slot again at confirmation; browsing or selecting a time does not reserve it. Account linking and clinic approval can affect whether a client may confirm. See E1–E4.

**How we will handle it:** Explain the sequence with synthetic screenshots: browse, choose, sign in, review and confirm. Show recovery for a taken slot and how to contact the clinic if account approval blocks booking. **Needs review:** Product/QA must confirm the current hosted onboarding steps and bilingual/mobile experience before publishing instructions.

### 2. Why Can't I Find Wellness Practitioners Who Offer Evening Appointments?

**Customer need:** Options outside a 9–5 work schedule.

**Project answer — Partial — Needs review:** Availability comes from practitioner/location hours, exceptions, time off, service rules and occupied slots. The system can represent evening working hours; it cannot guarantee that any clinic currently offers them. No dedicated evening-availability filter was established in this review. See E3–E4.

**How we will handle it:** Direct clients to actual offered times and suggest checking other eligible practitioners or dates. Clinic operations must confirm evening hours; product should decide whether an evening filter is required. Never advertise evening access based only on the scheduling model.

### 3. What Should I Expect During My First Wellness Session?

**Customer need:** Reassurance about an unfamiliar first visit.

**Project answer — Partial — Needs review:** Published services support descriptions and preparation instructions, and assigned forms can collect information before a visit. The project does not establish one first-session protocol for all disciplines. Forms may be disabled in a particular environment. See E1 and E6.

**How we will handle it:** Each practitioner/clinical reviewer should approve a service-specific outline covering arrival, discussion, consent, the session and follow-up. Product must verify that published content matches the offered service. Do not describe registration or a form checkbox as sufficient treatment consent.

### 4. How to Find a Wellness Coach That Fits Your Budget

**Customer need:** Understand the price before booking.

**Project answer — Partial — Needs review:** Published service duration/price choices and practitioner service links support comparison. On-Site bookings can include a separate travel-related fee. This is a catalogue of configured offerings, not a verified market-wide coach directory or an affordability guarantee. See E1–E3 and E10.

**How we will handle it:** Explain how to compare the same service length and delivery mode and review the quoted total. Clinic billing must validate currency, tax wording, On-Site charges and any concession policy. No sliding scale, subsidy or budget filter should be promised without evidence.

### 5. Best Time to Schedule Your Wellness Appointments for Maximum Results

**Customer need:** Choose a schedule that supports their goals.

**Project answer — Needs review:** The project schedules appointments; it does not determine a clinically optimal time or prove better outcomes from a booking pattern. Recurrence supports an agreed schedule, not a treatment recommendation. See E9 and E13.

**How we will handle it:** Replace the unsupported “maximum results” promise with a question about discussing timing and frequency with the practitioner. A clinical reviewer must approve any service-specific claims and supporting evidence. Product can explain how to book the agreed times after that discussion.

### 6. How to Prepare for Your Wellness Consultation

**Customer need:** Know what to do beforehand.

**Project answer — Partial — Needs review:** Services have public preparation fields, and eligible clients can receive assigned intake forms when enabled. The application does not supply a universal preparation protocol. See E1 and E6.

**How we will handle it:** Publish practitioner-approved preparation for each service and show where assigned forms appear. Clinical owners must approve any instructions about eating, exercise, medications or contraindications; these must not be invented from the booking software. QA must verify preparation content in both languages and the enabled form workflow.

### 7. Why Your Wellness Practitioner Keeps Double-Booking (And How to Avoid It)

**Customer need:** Avoid conflicting appointments.

**Project answer — Supported in source:** The booking service validates current availability inside a transaction under a clinic-row lock. Time, practitioner, room and buffer constraints are part of scheduling; retry handling helps prevent duplicate confirmation. That does not prove that double-booking never occurs on a hosted system or in external calendars. See E3–E4.

**How we will handle it:** Use a neutral title such as “How Wellnexa Checks Appointment Conflicts.” Explain that a stale slot requires another selection. Engineering/QA must validate concurrent bookings, administrative schedule changes and the actual database deployment before making a prevention guarantee. No two-way external calendar synchronization is assumed.

### 8. How Long Does a Typical Wellness Session Last?

**Customer need:** Plan enough time.

**Project answer — Supported in source:** Duration is configured per service option and shown with its price. The repository does not establish one typical length across practitioners or disciplines. Scheduling buffers and On-Site travel time are distinct from the booked session duration. See E1, E3–E4 and E10.

**How we will handle it:** Tell clients to use the selected service's published length. Clinic operations must confirm whether first visits differ, whether intake is included, and how early clients should arrive. **Needs review:** Any numerical example must be checked against current approved catalogue data rather than fixtures.

### 9. Can You Get Wellness Services on Weekends? Here's What to Know

**Customer need:** Appointment options on days off.

**Project answer — Partial — Needs review:** Configured working hours can include weekends, subject to active assignments, booking rules and availability. No verified current weekend offering or dedicated weekend filter was established. See E3–E4.

**How we will handle it:** Explain how to search real dates and practitioners. Clinic operations must approve which services and locations offer weekend visits and keep holiday exceptions current. Do not promise weekend coverage or emergency availability.

### 10. What Questions Should You Ask Your Wellness Coach at Your First Appointment?

**Customer need:** Assess a prospective practitioner.

**Project answer — Partial — Needs review:** Public profiles and service pages provide an initial view of discipline, displayed credentials, offerings, durations and prices. They do not establish suitability or independently verify every displayed credential. See E1–E2 and E11.

**How we will handle it:** Draft questions about scope of practice, relevant experience, the proposed approach, fees, consent and how progress will be reviewed. Practitioner/clinical reviewers must approve the wording and clarify which questions apply to each profession. Keep private credential documents separate from public profile text.

### 11. How to Change Your Wellness Appointment Without Calling

**Customer need:** Manage an appointment online.

**Project answer — Supported in source:** Linked clients can use their appointment management screen to reschedule eligible bookings. It offers available replacement times and checks ownership, appointment version and availability again. Optional email action links navigate to authenticated management; an email link alone does not authorize changes. See E5.

**How we will handle it:** Explain the client-portal steps and distinguish changing a time from changing service or practitioner. **Needs review:** QA/operations must verify hosted eligibility rules, account recovery and late-change assistance. Do not promise unrestricted edits or imply a selected replacement is held before confirmation.

### 12. The 5 Pillars of Wellness: What You Need to Know

**Customer need:** An understandable wellness framework.

**Project answer — Needs review:** No approved project-specific five-pillar framework was established. The software's service categories are not evidence of an authoritative clinical framework. See E1 and E13.

**How we will handle it:** The content owner and clinical reviewer must choose a clearly attributed framework, define the five terms and approve its relevance to actual services. Until then, retain this as an editorial idea. Do not invent five pillars and present them as Wellnexa's established model or promise health outcomes.

### 13. What Is a Wellness Concierge and Do You Need One?

**Customer need:** Understand possible administrative support.

**Project answer — Needs review:** Staff-assisted booking exists, and requirements discuss optional administrative assistance. A named concierge offering, eligibility, pricing and service commitment were not established. Reception or central administration must not be presented as having automatic access to clinical records. See E12–E14.

**How we will handle it:** Product/operations should decide whether “concierge” means scheduling assistance, service navigation or another defined offering. Privacy and clinical reviewers must approve access boundaries and escalation responsibilities. Publish a description only after its staffing, contact route, scope and charges are approved.

### 14. How Much Should Wellness Sessions Cost Per Hour?

**Customer need:** Evaluate pricing fairly.

**Project answer — Partial — Needs review:** The project supplies configured service prices and durations, not a validated market benchmark. An hourly equivalent derived from a short session is a comparison calculation, not necessarily an available hourly rate. See E1.

**How we will handle it:** Explain the actual duration/price choice and separately identify taxes or travel charges where applicable. Billing/content owners must decide whether hourly comparisons help and clearly label their assumptions. External market-rate claims require current, attributable research before publication; none is asserted here.

### 15. Why Your Wellness Practitioner's Cancellation Policy Matters

**Customer need:** Understand terms before changing a booking.

**Project answer — Supported in source:** Appointments snapshot the service's cancellation window and fee settings. Cancellation outside the window is fee-free under the implemented policy; inside it, the configured fixed/percentage calculation applies within its cap. Clients preview the amount and the API recalculates it transactionally. Recording an assessed fee does not collect payment. See E5.

**How we will handle it:** Explain the accepted booking's terms, rather than using today's edited service policy for an old booking. **Needs review:** Clinic operations must approve actual policy values, client-facing disclosure and dispute handling. Do not present a schema default as the clinic's universal policy.

### 16. How to Find Wellness Practitioners Who Take Your Insurance

**Customer need:** Know whether costs may be reimbursed.

**Project answer — Partial — Needs review:** The receipt workflow can record applicable practitioner designation/registration and issue a paid-session PDF when enabled. It does not verify coverage, certify reimbursement, submit insurer claims or establish a directory filter for accepted insurance. See E7 and E11.

**How we will handle it:** Ask clients to confirm coverage with their insurer and professional details with the practitioner before booking. Billing/clinical owners must validate receipt fields and approved insurance wording. Separate reimbursement receipts from direct billing; do not advertise “insurance accepted” without a defined, verified meaning.

### 17. What Happens If You Miss a Wellness Appointment?

**Customer need:** Understand no-shows and possible charges.

**Project answer — Partial — Needs review:** Practitioners can record eligible no-show outcomes, and the application has a separate cancellation-fee workflow. Recording a no-show is not evidence of an automatic no-show charge, card debit or an approved absence policy. See E5 and E12.

**How we will handle it:** Clinic operations must define late arrival, no-show, rebooking and hardship handling. Engineering/QA must confirm which financial actions, if any, follow a no-show. Explain how to contact the clinic and distinguish a recorded outcome, an assessed fee and money actually collected.

### 18. Best Questions to Ask During Your Wellness Consultation

**Customer need:** Make the conversation useful.

**Project answer — Partial — Needs review:** Service information and protected forms can support preparation; the application does not prescribe a consultation or generate clinical recommendations. See E1 and E6.

**How we will handle it:** Build on topic 10 with questions about agreed goals, alternatives, practical follow-up, review timing and how to raise concerns. A clinical reviewer must approve the service-specific list. Clarify that questions and form completion support discussion rather than replacing informed treatment consent.

### 19. How to Track Your Wellness Progress Between Appointments

**Customer need:** Accountability and a useful record of change.

**Project answer — Partial — Needs review:** Appointment history, visit outcomes and assigned form responses exist. The documented visit dashboard counts operational activity; it does not measure health improvement. No complete client goal tracker, validated outcome scale or automated clinical trend analysis was established. See E6 and E12.

**How we will handle it:** Clinical/product owners should define agreed measures and review intervals before promising a tracker. If forms are used, confirm appropriate questions, access and retention rather than treating generic answers as validated outcomes. Avoid collecting sensitive progress details through unapproved email, SMS or third-party tools.

### 20. Why Online Wellness Booking Systems Save You Time

**Customer need:** Reduce scheduling effort.

**Project answer — Supported in source:** Public discovery, available-time selection and authenticated appointment management reduce the steps that otherwise require staff coordination. A slot is rechecked at confirmation, and account approval can still require assistance. No measured time-saving figure was established. See E1–E5.

**How we will handle it:** Describe the actual actions a client can complete online and the situations requiring clinic help. **Needs review:** Product/QA should validate those paths on the hosted portal; any numerical claim about saved time requires a defined, privacy-approved measurement exercise.

### 21. How Far in Advance Should You Book Wellness Appointments?

**Customer need:** Plan within the clinic's rules.

**Project answer — Supported in source:** Service booking windows and lead-time rules bound which slots can be offered and confirmed. Recurring occurrences must also fit the service's current booking constraints. The repository does not establish one advance-booking period for every service. See E3–E4 and E9.

**How we will handle it:** Explain that available dates reflect configured rules and current capacity. **Needs review:** Clinic operations must verify the actual minimum notice and maximum horizon for each published service, including how clients request an appointment outside that window.

### 22. What to Know Before Hiring a Remote Wellness Coach

**Customer need:** Understand virtual service delivery.

**Project answer — Needs review:** The reviewed booking model supports in-clinic and `mobile`/On-Site appointments. On-Site means a practitioner travels to the client; it does not mean video care. A virtual clinic brand also does not establish a video-consultation capability. No approved remote-session platform or completed virtual workflow was established. See E10 and E13.

**How we will handle it:** Product/clinical owners must define eligible services, jurisdiction, consent, privacy, connection failure and emergency handling. Any new video vendor or data flow requires explicit approval. Until verified, describe only the delivery modes actually offered.

### 23. How to Get Wellness Appointments When You Work Full-Time

**Customer need:** Fit visits around employment.

**Project answer — Partial — Needs review:** Clients can inspect available dates, choose another eligible practitioner and consider On-Site care where configured and covered. Evening/weekend supply depends on actual clinic configuration; neither is guaranteed. See E1–E4 and E10.

**How we will handle it:** Give a practical search path using service duration, dates, practitioner and location. Operations should confirm out-of-hours availability and any contact process for unmet needs. Do not recommend unimplemented virtual care or promise appointments outside published hours.

### 24. The Complete Guide to Wellness Program Costs

**Customer need:** Budget beyond a single visit.

**Project answer — Partial — Needs review:** Per-session duration prices, On-Site charges and bounded recurring-series previews exist. A recurring series is not a prepaid program, membership entitlement or discounted package. No complete client program-pricing workflow was established. See E1, E7, E9–E10 and E13.

**How we will handle it:** Explain the verified per-visit quote and distinguish an estimated series subtotal from an approved program total. Billing/product owners must define package inclusions, taxes, validity, cancellation, refunds and unused visits before advertising a complete program price. Practitioner platform subscriptions are a separate commercial product.

### 25. Why Wellness Practitioners Use Appointment Reminders

**Customer need:** Avoid forgetting a booking.

**Project answer — Partial — Needs review:** Appointment notification/reminder services, preferences and worker dispatch checks exist. Provider acceptance is not proof that a person received or read a message. The current automated communications slice meters staff booking SMS; it explicitly does not introduce patient SMS. See E5 and E8.

**How we will handle it:** Describe only enabled, approved reminder channels and direct clients to the portal for appointment details. Operations/QA must verify schedules, recipient preferences, suppression, failures and real provider outcomes using synthetic records. Do not claim reminders eliminate no-shows or promise patient text reminders.

### 26. How to Cancel or Reschedule Wellness Appointments Painlessly

**Customer need:** Clear, predictable self-service changes.

**Project answer — Supported in source:** Clients can manage eligible owned appointments, search replacement slots and preview cancellation fees before confirming. Changed versions, occupied slots or a fee-window transition can require another review. Recurring-series changes have a separate preview/confirmation workflow. See E5 and E9.

**How we will handle it:** Publish separate short instructions for one visit and a future series. **Needs review:** QA/operations must verify current hosted controls, bilingual recovery and the staff-assisted fallback. Use “clearly” rather than promise a painless, fee-free or universally available change.

### 27. What Makes a Good Wellness Practitioner-Client Match?

**Customer need:** Choose an appropriate provider.

**Project answer — Partial — Needs review:** Profiles, discipline, displayed credentials and offered services support an informed initial choice. They do not constitute clinical matching, an independently verified suitability score or a guarantee of results. See E1–E2 and E11.

**How we will handle it:** Encourage a discussion of goals, professional scope, communication preferences, delivery mode and cost. Content/clinical owners must approve selection guidance and verify any language, specialty or accessibility claims against approved profiles. A future matching feature needs separate requirements and clinical/privacy assessment.

### 28. Why You Shouldn't Ignore Wellness Screening Questions

**Customer need:** Understand why information is requested.

**Project answer — Partial — Needs review:** Versioned intake/consent forms support required questions, explicit submission and access limited to the client and authorized assigned practitioner. Administrators do not automatically receive answer access. Form availability is controlled by rollout configuration. This is not a validated screening or triage engine. See E6.

**How we will handle it:** Clinical/privacy owners must approve each question's purpose, necessity, refusal/assistance route and handling of concerning answers. Explain who reads answers and when. Do not promise automatic monitoring, diagnosis or urgent response, and do not equate a required checkbox with treatment consent.

### 29. How to Prepare for Wellness Goals Before Your First Appointment

**Customer need:** Arrive with useful goals.

**Project answer — Partial — Needs review:** Approved service information and optional assigned forms can structure the discussion. No completed goal-setting engine or automatic personalized plan was established. See E1, E6 and E13.

**How we will handle it:** Clinical reviewers should approve prompts about what the client hopes to discuss, practical constraints and how they would recognize progress. Product should determine whether a service needs an assigned goal form. Do not require sensitive health narratives in a public enquiry or promise that the software will create a treatment plan.

### 30. The Best Time of Day to Schedule Your Wellness Appointment

**Customer need:** Choose a workable appointment time.

**Project answer — Needs review:** The project offers valid times based on configuration; it does not identify a universally better morning, afternoon or evening appointment. See E3–E4.

**How we will handle it:** Focus the article on choosing an available time the client can attend consistently and discussing any service-specific preparation with the practitioner. Clinical review is required before making physiological or outcome claims. Keep actual opening hours separate from advice about an ideal time of day.

### 31. How Wellness Practitioners Use Client Management Software to Help You

**Customer need:** Understand the value and handling of their information.

**Project answer — Supported in source:** The portals support scoped client/appointment operations, configured notifications, assigned forms and conditional receipt workflows. Sensitive functions have server-side access checks and audit components; that does not establish complete privacy compliance or unrestricted staff access. See E6–E8 and E12–E14.

**How we will handle it:** Explain concrete examples without displaying real records. **Needs review:** Privacy/operations must approve clinic-specific information-practice wording and current access boundaries. Avoid claims such as “fully compliant,” “all staff can see your history,” or “your data is automatically shared across practitioners.”

### 32. Why Some Wellness Practitioners Have Waiting Lists (And How to Get On Them)

**Customer need:** Request an opening with a preferred practitioner.

**Project answer — Needs review:** Waitlists and offers appear in requirements, but a completed client enrollment, ordering, offer-expiry and slot-claim workflow was not established. Existing enquiry/travel-interest features are not evidence of a booking waitlist. See E13.

**How we will handle it:** Operations should first approve a controlled manual process with minimal contact data and clear expectations. Product/engineering must define eligibility, priority, preferences, expiry, duplicate requests, concurrent claims and privacy before promising an automated queue. Do not tell clients they can join a portal waitlist until demonstrated.

### 33. How to Know If Your Wellness Coach Is Actually Helping

**Customer need:** Evaluate value and progress.

**Project answer — Needs review:** Completed visits, attendance counts and form submissions show activity, not clinical effectiveness. No validated outcomes or effectiveness score was established. See E6, E12 and topic 19.

**How we will handle it:** A clinical reviewer should define appropriate goal-review questions, measures and escalation when goals are not met. Describe collaborative review rather than automated judgment. Do not use visit count, payment history or booking frequency as proof that care works.

### 34. What to Bring to Your First Wellness Appointment

**Customer need:** Practical preparation.

**Project answer — Partial — Needs review:** A published service can carry preparation instructions and the client may have assigned forms. There is no verified universal checklist or rule requiring identification, insurance cards, equipment or medical records for every service. See E1 and E6.

**How we will handle it:** Clinic/practitioner owners must approve service-specific items, clothing, accessibility arrangements and arrival instructions. Ask only for necessary information through an approved channel. QA/content must confirm the checklist reaches clients in both languages without disclosing health details through reminders.

### 35. How Wellness Practitioners Personalize Your Appointment Experience

**Customer need:** Care that reflects individual circumstances.

**Project answer — Partial — Needs review:** Selection of service, duration, practitioner and delivery mode plus authorized form review can support individual discussions. The application does not automatically generate a personalized clinical plan. A scheduling role does not grant access to every form answer. See E1–E3 and E6.

**How we will handle it:** Explain which choices the client controls and which decisions require practitioner discussion. Clinical/privacy owners must approve how information is used, shared and restricted. Do not claim AI personalization, predictive recommendations or cross-practitioner sharing that has not been approved and implemented.

### 36. Why Some Wellness Services Offer Package Pricing vs. Per-Session Rates

**Customer need:** Compare payment models.

**Project answer — Partial — Needs review:** Current evidence supports duration-based session pricing and manual full-payment receipts for completed visits. Recurring booking does not create a discounted package, and the receipt slice excludes advance and partial payment handling. See E1, E7, E9 and E13.

**How we will handle it:** Present per-session billing as the verified model. Billing/product owners must define package eligibility, redemption, expiry, transfers, taxes, cancellations and refunds before implementation or promotion. Do not present a practitioner's Wellnexa subscription as a client treatment package.

### 37. How to Request Specific Appointment Times With Your Wellness Coach

**Customer need:** Choose a convenient time.

**Project answer — Partial — Needs review:** Clients select from offered slots, and authorized staff can assist with scheduling. The project does not establish a client request-and-approval workflow for arbitrary unavailable times. A request, enquiry or selected slot is not a confirmed appointment. See E3–E4 and E12.

**How we will handle it:** Direct clients to available times first and the clinic's approved assistance route second. Operations must define whether and how out-of-hours requests are accepted. Product must decide whether a structured request feature is needed, including notifications, response times and collision handling.

### 38. What Happens in the Days After Your Wellness Appointment?

**Customer need:** Know what follow-up to expect.

**Project answer — Partial — Needs review:** Practitioners can close visit outcomes, clients can manage future bookings, and paid-session receipts may be available when enabled. No universal automated aftercare program, symptom monitoring or completed secure client conversation workflow was established. See E7–E9 and E12–E13.

**How we will handle it:** Each practitioner/clinical owner must approve service-specific follow-up, expected response times and escalation instructions. Operations should explain the approved contact route and receipt availability. Do not promise automatic aftercare messages, medical monitoring or round-the-clock response.

### 39. How to Choose Between In-Person and Virtual Wellness Appointments

**Customer need:** Compare delivery methods.

**Project answer — Needs review:** In-clinic and On-Site are evidenced delivery modes. Both involve a physical visit; On-Site is not a synonym for virtual. A completed video/telephone appointment mode was not established. See E10 and topic 22.

**How we will handle it:** For current content, compare in-clinic with On-Site only where offered. Product/clinical/privacy owners must assess any proposed virtual service, provider technology, suitability, accessibility and failure recovery before publishing a virtual booking guide. Do not convert the existing `mobile` mode into virtual through wording alone.

### 40. Why Wellness Practitioners Ask So Many Questions During Initial Consultations

**Customer need:** Understand purpose and privacy.

**Project answer — Partial — Needs review:** Configurable form questions and versioned consent acknowledgments support information gathering. Their presence does not prove that every question is necessary, that all uses are authorized or that treatment consent has been obtained. See E6 and E14.

**How we will handle it:** Clinical/privacy owners must map each question to its purpose, reader, necessity and response process. Tell clients how to ask for clarification or assistance and how a refusal is handled. Keep clinical details in approved protected workflows and publish the actual clinic's information practices before making assurances.

## Bonus topic ideas

### B1. Wellness practitioner availability and scheduling challenges

**Project answer — Supported in source:** Hours, exceptions, time off, buffers, rooms, service assignments and booking windows all affect offered slots. See E3–E4.

**How we will handle it:** Explain why an empty search can be valid and how to change dates or contact staff. **Needs review:** Operations/QA must verify current availability, concurrent rule changes and hosted recovery; do not invent capacity statistics.

### B2. Comparison of wellness coaching modalities (virtual vs. in-person)

**Project answer — Needs review:** In-clinic/On-Site booking is evidenced; a completed virtual consultation mode is not. See E10 and topics 22/39.

**How we will handle it:** Clinical/product owners must approve supported modalities and comparison criteria. Any vendor/data-flow proposal needs privacy/security authorization before integration. Publish a virtual guide only after an actual service and workflow are verified.

### B3. Understanding wellness packages vs. one-off sessions

**Project answer — Partial — Needs review:** Single-session pricing and recurring scheduling exist; neither proves prepaid package accounting. See E1, E7, E9 and topic 36.

**How we will handle it:** Billing/product owners must define package terms and ledger/refund behavior. Until then, explain confirmed per-session choices and label package content as proposed.

### B4. How to build a wellness routine that actually sticks

**Project answer — Partial — Needs review:** Recurrence can schedule an agreed series with previews and conflict checks. It does not establish a habit coach, adherence tracker or evidence that a routine will succeed. See E9.

**How we will handle it:** Clinical/content owners should approve general routine guidance. Explain how clients manage a series and review fees before changes; do not imply that automatic scheduling is a clinical plan or guarantees adherence.

### B5. Red flags to watch for when choosing a wellness practitioner

**Project answer — Partial — Needs review:** Published profiles and private qualification review provide useful information, but displayed credentials are not an automatic current-licence check or booking eligibility gate. See E2 and E11.

**How we will handle it:** Clinical/content owners must approve profession-specific checks, complaints routes and fair wording. Validate any regulator link before publication. Do not invent a platform certification, safety score or public disciplinary-screening capability.

### B6. Combining multiple wellness practices for better results

**Project answer — Needs review:** Multiple services can be listed and booked; no integrated care pathway, combined-treatment benefit or automatic record-sharing authority follows from that. See E1, E6 and E13–E14.

**How we will handle it:** Clinical reviewers must assess suitability and any benefit claims. Privacy/product owners must define explicit sharing/delegation boundaries before a coordinated-care workflow. Keep each practitioner's authorized access separate from a client's choice to book another service.

### B7. Wellness appointment etiquette and professional expectations

**Project answer — Partial — Needs review:** Appointment times, preparation fields, cancellation policies and visit outcomes supply part of the answer. No universal conduct, late-arrival or accessibility/accommodation policy was established. See E1, E5 and E12.

**How we will handle it:** Operations/clinical owners should approve a respectful, accessible policy covering arrival, changes, consent and raising concerns. Ensure etiquette wording does not discourage requests for assistance or override a client's rights.

### B8. How to communicate with your wellness practitioner between sessions

**Project answer — Partial — Needs review:** Appointment notifications and enquiry workflows exist; the roadmap explicitly describes secure client/practitioner conversations as planned. Notifications are not a clinical chat channel. See E8 and E13.

**How we will handle it:** Operations must publish an approved contact route, hours and escalation expectations. Product/privacy/clinical owners must define and approve messaging scope, access, retention and alerts before implementation. Do not direct health narratives to public enquiry forms or claim an active secure inbox without evidence.

### B9. Understanding different wellness credentials and certifications

**Project answer — Partial — Needs review:** Private qualification submissions track issuer, dates, status and independent administrative review. Public credential text remains a separate display field. The system does not independently verify the issuer or automatically make booking eligibility depend on those records. See E2 and E11.

**How we will handle it:** Clinical/operations owners must approve profession-specific explanations, evidence requirements and renewal review. Decide which verified facts may be published; do not expose private credential records or equate a title with regulated status.

### B10. Cost-benefit analysis of investing in professional wellness support

**Project answer — Needs review:** Catalogue prices and paid receipts can describe financial cost, but the project does not measure clinical benefit, avoided healthcare costs or return on investment. See E1, E7 and topic 33.

**How we will handle it:** Billing/content owners can explain transparent costs and questions to discuss before committing. Clinical review and current attributable evidence are required for benefit claims. Do not infer effectiveness from repeat bookings, client spending or attendance.

## Review priorities and acceptance

| Priority | Decision/work required | Proposed owner | Evidence required to close |
| --- | --- | --- | --- |
| Before public copy | Confirm actual services, hours, durations, prices, taxes, preparation, cancellation/no-show rules and contact routes. | Clinic operations + billing + practitioners | Dated approval of configured offerings and bilingual text; synthetic hosted walkthrough. |
| Before health claims | Approve first-visit, screening, preparation, goal, timing, progress and combined-care content; select or reject the five-pillar framework. | Clinical/content reviewer | Named reviewer, approved wording and current supporting sources where claims require them. |
| Before feature promises | Demonstrate forms, receipts, action links and recurrence in the intended environment; resolve account/onboarding limitations. | Product + engineering + QA | Feature settings and schema evidence, relevant identities, negative tests, concurrency and recovery results. |
| Before planning additions | Decide scope of waitlists, virtual care, packages, concierge support, outcome tracking and secure messaging. | Product + clinical + privacy/security + billing as applicable | Approved requirements, access/data-flow decisions, estimate, acceptance criteria and any required explicit implementation authorization. |
| Before real-data release | Close or formally resolve applicable privacy, identity, infrastructure, artifact and operational gates. | Custodian/privacy + security/operations + clinical + release owner | Current revision-bound ledger, hosted synthetic acceptance, rollback/restore rehearsal and accountable sign-offs. |

No dates, prices, hours, market comparisons, response commitments or clinical outcomes have been invented. This review used project material only; it did not research current medical, legal, insurer or market advice. Such claims remain **Needs review** and require appropriate sources and accountable approval before publication.

## Repository control ledger for this documentation change

All 38 controls are recorded below for the limited documentation scope, as required by REL-01. Evidence is this file, the inspected source/documents E1–E14 and the checks recorded below, against source `04475b5223483d9440948f419237ce2045365ea7`. Review date: 1 November 2026. Reviewer: Codex, source/document review only; accountable human decisions and named assignments are pending. “Unverified” records an evidence gap, not a finding of organizational noncompliance. No operational control is closed by writing this document.

| Control | Status for this change | Evidence, proposed owner and next action |
| --- | --- | --- |
| GOV-01 | Unverified | Custodian/operator responsibilities not approved here; custodian/privacy must confirm roles before publication/real-data use. |
| GOV-02 | Unverified | No current legal applicability review performed; privacy/legal must maintain and approve the dated register. |
| GOV-03 | Unverified | Federal applicability and flows not assessed here; privacy/legal must record the decision. |
| GOV-04 | Partial | Topics distinguish administrative features from clinical claims; clinical/regulatory owner must assess intended use before such claims/features. |
| GOV-05 | Partial | No patient records used in this document; approved data inventory/PIA/TRA not verified. Privacy/security must supply them. |
| PRIV-01 | Unverified | Clinic notices/contact approval not established; custodian/privacy must approve actual bilingual practices. |
| PRIV-02 | Partial | Topics distinguish acknowledgments, treatment consent and communication purposes; clinical/privacy must approve collection/use wording. |
| PRIV-03 | Partial | Access/restriction components inspected through E5–E8; exhaustive dispatch/revocation/route behavior untested. Engineering/privacy must validate. |
| PRIV-04 | Unverified | No representative/capacity process verified; clinical/privacy must define supported or assisted handling. |
| PRIV-05 | Unverified | No end-to-end rights-request process tested; privacy must evidence controlled access/correction handling. |
| PRIV-06 | Unverified | No approved retention/hold/disposal/backup schedule verified; custodian/operations must provide it. |
| PRIV-07 | Unverified | No incident rehearsal verified; incident/privacy lead must supply current runbook and evidence. |
| AUTH-01 | Unverified | No identity changes; hosted identity validation not tested. Identity/security owner must validate before release. |
| AUTH-02 | Partial | Answers preserve clinic/relationship/role distinctions; negative hosted access and stale-principal tests remain for QA/security. |
| AUTH-03 | Unverified | No MFA/workforce session configuration inspected; identity/operations must evidence it. |
| AUTH-04 | Unverified | E14 records storage concerns; this task neither fixes them nor approves an exception. Security must reassess current design. |
| AUTH-05 | Unverified | No permissions changed; deployed grants not inspected. Identity/database owners must verify least privilege. |
| SEC-01 | Partial | New file contains no copied environment values, records or credentials; no whole-repository/history/artifact scan performed. Security/release owner must complete broader scans. |
| SEC-02 | Unverified | No hosting, transport, storage or backup change/evidence; operations/security must verify. |
| SEC-03 | Unverified | No live headers/cache behavior inspected; hosting/security must verify every intended host. |
| SEC-04 | Not applicable | Plain Markdown only; no application rendering, SQL, upload or HTML behavior changed. Engineering must reassess if this becomes public rendered content. |
| SEC-05 | Unverified | No abuse/load tests performed; current source has request-limit follow-up beyond the historical audit. Backend/hosting must evidence deployed bounds. |
| SEC-06 | Unverified | No audit persistence/integrity or monitoring tested; security/database owners must provide operational evidence. |
| SEC-07 | Partial | No diagnostic payloads or provider URLs copied; runtime/log redaction untested. Operations/security must validate separately. |
| SEC-08 | Unverified | No new endpoint/vendor/data transmission introduced; existing flows remain subject to privacy/security approval. |
| SEC-09 | Partial | Reminder answers avoid patient-SMS and guaranteed-delivery claims; consent, live suppression and message classifications remain for privacy/operations. |
| DEV-01 | Implemented | Instructions, Git/worktrees, manifests/locks, relevant configs, deployment script, migration and maintained docs inspected; isolated task branch preserves original edits. Commit/push evidence below. |
| DEV-02 | Implemented | Only a review document added; unsupported assertions marked for review and evidence links checked. Human editorial approval remains pending. |
| DEV-03 | Not applicable | No dependency, lockfile, toolchain or release artifact change; no restore/audit executed. Historical advisory counts are not asserted current. |
| DEV-04 | Not applicable | No application/content bundle input changed; runtime builds, lint, browser and PHP suites omitted for this documentation-only scope. Markdown checks recorded below. |
| DEV-05 | Partial | Semantic Markdown headings/tables used; no UI behavior changed. Published bilingual copy and full accessibility review remain for content/UX owners. |
| DEV-06 | Not applicable | No runtime query, bundle, timer or rendering behavior changed; performance review not part of this document task. |
| REL-01 | Partial | All controls recorded with revision, date, scope, proposed owner and next action; named accountable reviewers and decisions pending. |
| REL-02 | Not applicable | This is not a broad application checkpoint; no claim of build, SQL or hosted acceptance. |
| REL-03 | Unverified | No artifact generated or hosted acceptance performed; release owner must produce provenance and test actual approved hosting. |
| REL-04 | Implemented | Readiness withheld while public-content and operational evidence remain outstanding; no exception granted. |
| REL-05 | Unverified | No merge, deployment, migration, permissions or external-flow change authorized/performed; operational approvals/restore evidence not verified. |
| REL-06 | Implemented | Scope, file purpose, validation, omissions, risks and rollback recorded here; final report retains readiness decision. |

## Change and validation record

- **Requested/result:** Expand the supplied topic list into valid Markdown with project-grounded answers, proposed handling and explicit review gaps. All 40 main topics and 10 bonus ideas are included.
- **Changed file:** `documentation/BLOG_TOPIC_PROJECT_ANSWERS.md` only; contains the expanded content, evidence index, review priorities and control ledger. The documentation index was left untouched because the original checkout has unfinished changes there.
- **Git scope:** Dedicated branch `feature/blog-topic-project-answers` in an isolated worktree based on the source revision above. No merge or deployment is included. The commit containing this file and its branch provide the resulting document revision; the reviewed application SHA remains fixed above.
- **Dependencies/toolchains:** No versions changed; no packages installed. No permission, consent configuration, schema, endpoint or data-flow changes.
- **Validation executed:** `git add -N -- documentation/BLOG_TOPIC_PROJECT_ANSWERS.md` followed by `git diff --check` passed (exit 0). An inline PowerShell check using `Get-Content`, `[regex]::Matches`, `Test-Path` and terminating assertions passed: all 40 supplied main titles and 10 bonus titles matched; 38 unique control rows and 38 local links checked; heading order, fence balance, targeted credential-pattern scan and final status passed. This is a structural Markdown/source-link check, not a complete secret scan or public-site render test. Git warned that LF will become CRLF on its next write under the existing checkout settings; no settings were changed.
- **Omissions:** No npm/Composer restore/audit, runtime lint/build, browser regression, PHP/SQL/HTTP suite, live identity/provider call, database query, hosted test, release package or current legal/medical/market research. These are not required to validate a standalone documentation addition and are not claimed as passing.
- **Security/privacy and remaining risk:** No patient data or private configuration used. Unsupported clinical and feature claims remain explicitly unapproved. E14 is a baseline/follow-up source, not fresh acceptance of all runtime controls. Current advisory, storage, rights, retention, hosting and operational status requires responsible-owner review.
- **Rollback:** Revert this document's commit if needed; no application, database or financial-record rollback is required. Human reviewers must resolve the marked gaps before reusing this draft as public-facing copy.

RELEASE STATUS: NOT READY FOR RELEASE

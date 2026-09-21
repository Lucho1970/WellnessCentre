# Dashboard Widget Framework

## Status and objective

The first staff dashboard release is implemented for Operations and Practitioner workspaces. It uses live, authorization-scoped appointment projections and saved per-user layouts. Earlier visual sample counts were not backed by operational data and are not treated as implemented features. This document defines the current framework and its planned expansion to the client workspace under requirements GOV-05 and EXP-08A.

Every workspace receives a useful recommended layout without setup. A user may personalize eligible widgets and their order, but personalization never grants permissions or creates an arbitrary page builder.

## Standard widget definition

The frontend maintains a code-owned registry. Each released widget definition includes:

| Field | Purpose |
| --- | --- |
| `id` | Stable non-display identifier, for example `appointments_today` |
| `workspace` | `client`, `practitioner` or `admin`; a widget may register distinct variants |
| `kind` | `metric`, `list`, `alert`, `timeline`; charts are added only with a defined metric |
| `titleKey` / `descriptionKey` | English/French resource keys |
| `requiredCapability` | Application policy required to request data and see the destination |
| `destination` | Code-owned route plus allowlisted filters such as `date=today` |
| `sizes` / `defaultSize` | Supported grid widths rather than arbitrary CSS dimensions |
| `loader` | Typed dashboard projection contract, not a general endpoint/URL supplied by a user |
| `refreshPolicy` | Initial load, manual refresh and an optional bounded interval |
| states | Explicit loading, error, empty and unavailable rendering |

The widget shell owns consistent spacing, focus treatment, title/help text, “as of” display, retry and navigation behavior. Content renderers remain small typed components so a single generic card does not accumulate unrelated business rules.

## Personalization and persistence

Store preferences on the server per user and workspace. The target record contains:

- user ID and workspace;
- widget ID;
- display order;
- enabled/hidden state;
- supported size;
- optional allowlisted widget setting values;
- preference schema version and timestamps.

The record never contains rendered counts, client names, appointment details, health information or authentication data. Layout may be optimistically edited in the browser, but the API validates every widget ID, workspace, size and setting before saving. Provide **Edit dashboard**, **Save**, **Cancel** and **Reset to recommended layout** actions. Reordering works with keyboard controls as well as pointer drag-and-drop. Mobile uses the saved linear order even when the desktop grid has multiple columns.

Defaults are versioned by workspace/role and filtered through current capabilities. Removing a role or permission removes the associated widget immediately, regardless of saved preference. Unknown, retired or feature-disabled widgets are ignored safely. Preference migration must preserve deliberate user choices where possible.

## Data, navigation and security

Dashboard endpoints are purpose-built read projections. They enforce the same role, clinic, practitioner ownership, client ownership and care-relationship policies as the linked module. Frontend visibility and a saved widget ID are not authorization evidence.

Each metric defines:

- clinic and practitioner/client scope;
- timezone and date boundary;
- included and excluded statuses;
- numerator, denominator and unit where applicable;
- freshness/caching behavior;
- destination filters that reproduce the visible population where practical.

For example, **Appointments today** may display an authorized total, a pending-confirmation subcount and the next start time. Activating its primary link opens the authorized appointment list with an allowlisted `date=today` filter. The card must not claim a count from stale fixtures or download a broad appointment list merely to count it in the browser.

Cards containing no secondary action may be one accessible descriptive link. Cards with refresh, dismiss or other actions use a linked heading or explicit “View appointments” action so interactive elements are not nested. Empty states such as “No appointments today” remain navigable when the destination is useful.

## Initial widget catalogue

Only widgets backed by released modules and real projections appear.

### Operations

- Appointments today → filtered appointment list
- Awaiting confirmation → filtered appointment list
- Recent cancellations → filtered appointment list
- New clients → client list when authorized
- Practitioner availability exceptions → availability administration
- On-Site travel/coverage alerts → appointment list, scoped to staff with operational need
- Rooms in use/utilization → rooms or reports after its metric is defined
- Outstanding balances → billing only after finance and its permission model ship

### Practitioner

- My appointments today → own schedule
- My next appointment → own appointment details
- My On-Site visits → own schedule with only necessary destination/travel information
- Forms requiring review → protected form workflow when released
- Availability exceptions → own availability
- Schedule gaps → own availability/schedule after a stable definition exists
- Clients requiring approved follow-up → scoped relationship view when policy/workflow exists

### Client

- Next appointment → own appointment
- Upcoming appointments → own appointment list
- Required forms → own assigned forms when released
- Unread messages → own conversations when released
- Outstanding invoices → own invoices when released

## Delivery sequence

1. Create shared widget types, registry, shells and responsive default grid.
2. Add real **Appointments today** for operations and **My appointments today/next appointment** for practitioners using authorization-scoped API projections.
3. Add per-widget loading/error/empty/freshness states and filtered navigation.
4. Add database migration and API for per-user/per-workspace preferences.
5. Add accessible edit mode for show/hide, order, supported size, save/cancel and reset.
6. Add client defaults and later widgets only as their destination modules become released.
7. Add charts only after metric definitions, reporting permissions and accessibility alternatives exist.

Steps 1–5 are implemented for the initial Operations and Practitioner appointment widgets. Pointer drag-and-drop, client widgets, module-specific cards beyond appointments, and richer filtered destination views remain planned. Keyboard/touch move controls are the current accessible ordering mechanism.

## Deployment

Existing databases must apply `api/database/migrations/012_dashboard_preferences.sql` once before deploying the matching API and portal. The migration is additive and stores layout metadata only; it does not modify appointments or other clinical/operational records. Deploy in this order: back up the database, apply migration 012, upload the private API, then upload the portal build. Verify both an Operations and Practitioner dashboard and confirm that changing one layout does not change the other.

## Acceptance criteria

- Default layouts are useful without configuration and contain no invented data.
- Every displayed value comes from an authorized server projection with documented semantics.
- Clicking a widget reaches the intended released route and useful filters survive refresh/back navigation.
- A user cannot reveal another role's widget or data by editing requests, stored preferences or route parameters.
- Preference changes persist across devices and remain separate between eligible workspaces.
- Role/permission removal immediately removes no-longer-eligible widgets.
- Keyboard-only and touch users can configure and activate widgets; mobile order is deterministic.
- Loading, unavailable, error, empty and stale states are distinguishable and translated.
- Reset restores the current recommended authorized default without deleting unrelated preferences.
- Automated tests cover registry filtering, API authorization, preference validation/migration, navigation, responsive order and accessibility-critical interactions.

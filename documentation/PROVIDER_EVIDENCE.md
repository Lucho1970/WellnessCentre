# Reviewed SMS provider evidence

Implemented on `feature/provider-reconciliation`, following migration 056 and the automated
communications branch. Migration **057_provider_evidence.sql** adds reviewed evidence batches,
immutable charge receipts and sender-specific STOP records. `PROVIDER_EVIDENCE_ENABLED` defaults
to `false`. No hosted migration, deployment, real provider request, message or account change
was performed for this implementation.

## Operator workflow

On central Administration → Communications, select the clinic and use **Provider evidence**.
Prepare the application's normalized CSV from reviewed provider records. It is not a native
VoIP.ms export format, API synchronizer, webhook or delivery-receipt integration. The public
[VoIP.ms API overview](https://wiki.voip.ms/article/API_Overview) directs users to the signed-in
portal for the complete parameter/response reference. The public WSDL describes `getSMS`
inputs but does not establish the returned per-message financial schema. Native synchronization
requires verification of that authenticated contract and its completeness/retry semantics.

The exact header and synthetic examples are:

```csv
type,reference,sender_did,destination,occurred_at,currency,amount
charge,000123,4165550199,+14165550101,2026-10-28 14:30:00,USD,0.017500
stop,stop-000124,4165550199,+14165550102,2026-10-28 15:00:00,,
```

- UTF-8, optional BOM, at most 50,000 bytes and 500 data rows; total HTTP JSON body 64 KiB.
- Only `charge` and `stop` types. Preserve the provider reference exactly, including leading
  zeroes. References contain 1–120 ASCII letters, digits, colons, underscores or hyphens.
- Sender is ten digits; destination is canonical Canadian `+1` plus ten digits, using the
  application's maintained Canadian area-code validation. No message bodies or extra columns.
- Occurrence is UTC `YYYY-MM-DD HH:MM:SS`; timestamps over five minutes ahead are rejected.
- Charges use CAD or USD, nonnegative decimal amounts with at most six fractional digits.
  STOP rows leave currency/amount empty. Source identities cannot repeat in one file.

Enter a statement/source reference and an audit reason. USD rows additionally require a
reviewed CAD-per-USD rate (six decimals), nonfuture quote date and conversion source/reference.
Use the actual conversion basis for the provider charge, including any approved adjustments;
the system does not fetch or guess a quote. Review every preview row, the conversion and final
CAD cents, then affirm both attestations and apply. Conflicting/unmatched rows block the entire
batch; separate valid evidence into a new reviewed batch if appropriate.

Every charge must match exactly one attempted SMS in the selected clinic, with the same frozen
sender, non-null provider message ID and original destination hash. The occurrence cannot precede
the ledger reservation by more than five minutes. Missing records do not prove no-send and do
not release commitments. A timeout without a known exact provider ID still requires the
existing manual delivery reconciliation workflow.

## Financial behavior and recovery

Source amounts and exchange rates use integer millionths; conversion uses integer arithmetic
and rounds each final CAD charge upward to a whole cent. The submitted estimate is replaced
by the actual amount in its original budget month, even if above the estimate or cap. New sends
remain subject to current committed-plus-actual caps. Number rental, inbound/fixed fees and
other unmatched overhead still require Billing's shared-cost workflow; this importer does not
reconcile a complete provider invoice.

Preview persists normalized evidence and its state fingerprint without changing money or STOP
state. Apply rechecks current central grant, clinic, evidence and ledger state under locks.
Audit, receipt, ledger settlement, STOP changes and idempotency response commit together.
Any changed preview requires a fresh one; failure rolls back the whole application. Unknown
network outcomes freeze the browser's original request for an identical retry. A denied or
stale request clears its approval and requires new review.

Provider source identity is type + sender DID + exact source reference. Repeated evidence,
including reordered CSV lines, never charges twice. Changed source facts or conversion proof
conflict with the immutable receipt. A fresh preview may attach exact equal-cost evidence to
an existing manual settlement without additional spend. To correct an already imported cost,
use the audited manual correction with its expected previous amount; the original evidence
receipt remains and will conflict on re-import rather than silently repricing the correction.
Provider acceptance resolves an uncertain attempt for review purposes; it does not establish
handset delivery or authorize another send.

## STOP and renewed consent

An imported STOP suppresses that sender DID and destination hash across every clinic sharing
the number. Import is limited to a sender currently configured for the selected clinic or
frozen on one of its historical deliveries. Importing a STOP does not disclose other clinics'
contacts, appointments or batches. The stored latest STOP time only moves forward.

To clear provider suppression, explicitly enter the sender, destination and verified UTC
opt-in time, confirm provider clearance, and supply a reason. Consent must be later than the
latest STOP; older late-arriving STOP evidence cannot undo newer verified consent, while a
later STOP blocks again. START rows are unsupported. This action does not clear separate local
manual suppressions. Use verified source event IDs and timestamps; repeated rows with edited
facts conflict instead of overwriting the audit trail.

A connection-scoped sender lock is held through actual SMS transport and through STOP apply/
clear. Clinics sharing a DID therefore serialize these decisions. A STOP committed before a
waiting send's final authorization blocks that send; messages already submitted cannot be
recalled. Lock contention fails closed. Locks are sorted for multi-sender batches and released
on success/error or connection termination. The existing clinic consent locks and pool locks
continue protecting privacy and concurrent budgets.

The import is not a complete provider synchronization. It **does not refresh** the existing
24-hour provider opt-out review gate. Operators must still inspect the provider account and
attest that review in Communications. See the provider's
[SMS documentation](https://wiki.voip.ms/article/SMS) for its separate inbound mechanisms;
none is activated by this feature.

## Data and API

Evidence stores normalized source fields, hashed canonical destinations, source-file hash,
source/FX provenance, immutable receipts and operator/reason audit. It does not store the raw
CSV or SMS body; destination numbers exist transiently during validation. Existing notification
records retain their own recipient data under the existing policy. Reports expose no contact
hashes, raw numbers or internal matching targets. Browser CSV contents stay in component memory,
are cleared on loss of authority, and are never placed in browser persistent storage. A shared
sender's suppression hash does not grant cross-clinic record access.

All endpoints are under `/api/v1/application/clinics/{clinic}/provider-evidence` on the exact
central host, require a verified workforce token and current explicit central grant, and use
the existing JSON envelope. Clinic roles and caller-supplied clinic IDs provide no authority.

| Method/path | Purpose |
| --- | --- |
| GET base path (without trailing slash) | Latest 30 batches for the selected clinic |
| GET `/{id}` | Scoped report with current eligibility |
| POST `/preview` | Normalize, validate, match and freeze a reviewed preview |
| POST `/{id}/apply` | Apply exact `preview_hash` with both review attestations |
| POST `/clear-stop` | Record explicit newer opt-in and provider-clearance review |

Every POST requires a UUID `request_key` and a 5–1,000 character `reason`. Unknown fields are
rejected. Mutations/replays recheck authority. Previous batches remain available for inspection;
the UI paginates up to 500 preview rows in groups of 50.

## Rollout and operational acceptance

1. Review and integrate the prerequisite registration/application/communications branches.
   Back up the database; apply migrations through 056, then additive 057 once.
2. Deploy matching API, worker and all three frontend builds. Keep master SMS controls off
   during this work; configure no production messages through a test fixture.
3. Enable `PROVIDER_EVIDENCE_ENABLED=true` only after schema/source and sender setup acceptance.
   Keep the flag on once relying on imported suppressions: turning it off hides the importer
   and disables these additional STOP checks. To suspend delivery, use `SMS_ENABLED=false`
   or the clinic SMS switch, retaining provider enforcement.
4. Keep `SMS_BUDGET_ENFORCEMENT_ENABLED=true` for the funded beta and use existing automation,
   scheduler, tariff, overhead, consent, membership and subscription setup. Provider evidence
   also forces the guarded worker path even if the older enforcement flag is accidentally off.
   Viewing the UI requires the existing Communications feature gate.
5. Rehearse a synthetic matching charge, duplicates, stale preview, shared-DID STOP and
   explicitly newer clearance on the hosted engine. Verify all actual sending workers use
   this source, release advisory locks and do not fall back to another deployment's old code.
   Confirm scheduler/host/provider permissions independently before live delivery.

Do not roll back by disabling this flag while leaving SMS on. Disable SMS first and retain
financial, consent and audit tables; reverting code does not reverse real provider charges or
erase suppression obligations. No down migration or automatic deletion is supplied.

## Local validation, 29 October 2026

- All three production frontend builds, TypeScript, content checks and 2,520 bilingual strings
  passed. Existing bundle-size notices remain non-blocking.
- Browser regression: 283 main checks, 36 central checks, nine application checks and seven
  invitation checks passed: **335 passed, two optional local-input tests skipped**. Four new
  central checks cover conversion/attestations, exact retry, stale approval cleanup and French
  mobile draft preservation/STOP clearance.
- All 56 standalone PHP scripts passed, including 116 provider CSV/decimal assertions.
  Syntax checks passed for 275 non-vendor PHP files. The two PHP files edited during final
  review were checked again and exercised by the final database runs.
- MySQL 5.7.44 and MariaDB 11.4.8 each passed 36 ledger, 69 application SQL/HTTP, 58
  communications SQL/worker and 49 provider evidence SQL/worker/HTTP checks. The provider suite covers exact case-sensitive IDs,
  ambiguous matches, approved conversion, idempotency, audit/money rollback, manual-settlement
  linking, stale/repriced evidence, shared-DID STOP, renewed consent, late/new STOP ordering,
  preserved local blocks, a real concurrent worker/import process race, enforced guarded
  delivery with the evidence flag alone, and HTTP host/grant/body/clinic boundaries.
- Both PowerShell runners parsed successfully, 146 local documentation links resolved, and
  the staged diff passed whitespace checks. Private assets, environment files and generated
  artifacts remain outside this commit. The branch is for review; main and hosted data remain
  unchanged.

Both portable SQL runners accept `-IntegrationTest provider-evidence.php`; this chains the
prerequisite suites and applies 057 to an isolated synthetic database. Windows RSA fixtures
use the installed Git OpenSSL configuration through `OPENSSL_CONF`. All transport fixtures are
fake and browser APIs are mocked. These checks do not establish hosted database, identity,
scheduler or live provider acceptance.

The first test runs found a nonexistent notification timestamp query and MySQL JSON-key
ordering affecting preview/replay fingerprints. The query now uses the ledger reservation
timestamp and fingerprints/responses canonicalize object keys. Test-only shared-clinic IDs
and a disabled-select browser locator were also corrected before the final reruns.

# Development Roadmap

## Phase 0 — FOUNDATION (in progress)
Establish dedicated repo, approved spec, requirement register, AI handoff, CI, explicit separation from property-platform; import and label proof-of-concept interfaces. CI verification is required before marking complete.

## Phase 0.5 — Website CMS architecture + editor usability (new approved scope)
Formalize owner-configurable website design, typed safe sections and columns, publishing/audit/access policy and page/version/storage model. Test pure layout/revision helpers, then build a practical visual editor integrated into VenueDesk. Owner can move text, images, sections, columns and mobile stacking. Keep business price and booking constraints on backend. See docs/WEBSITE_CMS_DESIGN.md.

## Phase 1 — Secure server foundation
Laravel/Filament/PostgreSQL versions verified; migration foundation, operating company and halls, authentication, roles, MFA, policy tests, tamper-resistant audit.

## Phase 2 — Owner configuration
Sessions morning/noon/evening, times, grace, overtime, buffer, capacity, seating pricing, catering and add-ons; snapshot/versioned pricing.

## Phase 3 — Bookings and universal search (core internal confirmation implementation pending hosted CI)
Color-coded multi-hall calendar, temporary holds, concurrency constraints, customer profiles, enquiries and mobile wizard.
A new PostgreSQL backed INTERNAL owner/admin/assigned-manager/reception confirmation flow consumes a reviewed proposal into a separate confirmed reservation, locks an exclusive hall/date/program claim, and supports same-hall rescheduling and audited cancellation. The calendar projects confirmed occupancy. Release gate VENUE_BOOKING_CONFIRMATION_ENABLED defaults OFF until operator readiness signoff. This is NOT a public intake, full booking management milestone or ready-to-charge booking: new customer onboarding for manager/reception, cross-hall transfers, operator timezone, expiry/holds UX, consent/privacy signoff, production race acceptance, invoice/payment and mobile browser UAT remain open.

## Phase 4 — Finance
Quote, invoice, receipt, deposit, ledger, correction, refund, cash reconciliation, PDFs.

## Phase 5 — Approvals
Owner review center, Telegram notification integration, approvals audit, permissions, emergency delegation and approvals under concurrency.

## Phase 6 — Venue public website
Production-grade Zargul Silver Marquee brand site and integrated enquiry flow, legitimate venue photos/packages.

## Phase 7 — Operations/readiness
Event actual times, setup/cleaning, services, staffing, overtime billing and operational reports.

## Phase 7.1 — Actual event operations (release gated)
Authenticated assigned-hall event start/finish observations, strict optimistic revision checks,
immutable evidence and current owner rules-based overtime/grace/hard-stop preview.
Actual extension remains unauthorized and no invoice or money changes are made automatically.
Operator release flag `VENUE_EVENT_RECORDING_ENABLED` remains OFF until separate venue signoff.
Catering fulfilment, authorized overtime charge adjustments and event closeout reporting follow.

## Phase 7.2 — Actual setup fulfilment and audit
Bridge reviewed preparation tasks from the original confirmed reservation's proposal
into witnessed physical setup only after actual check-in. Staff records a reason and
immutable one-time fulfilment evidence under the current hall/company policy.
A reviewed plan alone never proves service delivery. No automatic invoice,
payment, hall extension, or added customer charge occurs.

## Phase 7.3 — Frozen operational event closeout
Seal current nonbinding grace/overtime/turnover observations together with
reviewed planned-task counts and witnessed performed/unperformed evidence.
Display unresolved services without falsely marking them delivered, do not silently
change customer invoice, reservation or ledger. Actual overtime billing still needs
authorized contractual adjustment and operator release.

## Phase 8 — Controlled pilot
No real launch until finance, isolation, restores, booking concurrency and real user tests are accepted.

## Phase 9 — Commercial licensing and initial setup (approved, not implemented)
Separate VenueDesk Central Control Center; signed license activation/renewal; hosted + self-hosted tenancy; scoped hall/user entitlements; public maintenance at expiry with backend grace; renew/export/reconcile; resumable securely closed owner/hall/pricing/payment setup wizard and existing-installation migration.

## Phase 10 — Public website productization (approved, not implemented)
Owner-selectable structural themes and company/hall profile; exact guest-count pricing brackets; one-hall auto-selection; published footer per-hall maps/contacts/phone and centrally governed per-license optional designer credit; safe 3-screen enquiry and 5% verified-payment hold.

## Phase 11 — Provider payments and complete finance (approved, not implemented)
Tenant-owned JazzCash/Easypaisa/Raast manual QR and approved APIs; optional unified gateway; named bank/wallet ledgers; independent verification; cash two-signature evidence, owner/staff custody and transfer confirmation; individual manager spend ceilings; vendor/payroll/utilities and reporting.

## Phase 12 — Hall inventory and multi-item loans (approved, not implemented)
Hall-owned inventory catalog and stock movements, inline item entry, monthly/quarterly/post-event audits, minimum levels, immutable discrepancy review, independent internal and outside-establishment multiple-item loans, count/return/condition evidence, partial receipts, automatic closure ONLY on independently confirmed full match, numbered PDF and hall reports. Initial draft-only catalog, physical counts, multi-item requests and bounded authorized read-only review of saved loan drafts and physical count evidence are implementation foundations, release gates OFF; actual witnessed dispatch/acceptance, inventory balance verification and reconciliation, returns and completion receipts remain NOT implemented/released. Preserve booking/finance gates.

## Phase 13 — News, contextual Help, support and Smart Support (foundation in progress; not production released)
Central editorial news and versioned manuals/videos, contextual help buttons; full central agent/customer bidirectional ticket replies, status tracking, unread counters/banners, support after license expiry, safe queued manual/video/approved prior-solution suggestions without auto-closing ticket and no cross-tenant raw-ticket exposure. Foundation added on the protected development branch: separately encrypted client support outbox and inbox, append-only HAFZATECH ticket and MFA-scoped reply schema, Ed25519 signed request and optional reply-page envelope codecs, default-OFF isolated five-minute support delivery/polling, owner/admin company-scoped inbox page and overview unread link. These are **not live**: central production API routes, staff MFA login, independent KMS key custody, HTTPS/DNS, customer notifications and end-to-end operator acceptance remain open. Require exact hosted CI and release signoff.

## Phase 14 — Performance and commercial release certification (approved, not implemented)
Measure owner/manager/mobile booking/report latencies, indexes/queues/caching, tenant isolation, license offline grace, provider callbacks, financial correctness, real inventory reconciliation/loan concurrency, accessibility and self-hosted upgrade/restore. Green exact-SHA CI and staged pilot required.

## Deferred beyond approved commercial scope
Nonessential extensions only; inventory, wages, vendor finance and reporting are now approved product work, not a generic later bucket.

## Phase 9.1 — HAFZATECH central control contracts (2026-10-17 approved; not implemented)
Implement `docs/CENTRAL_CONTROL_CENTER_V1.md` and `docs/FAST_DELIVERY_PLAN.md`: `lichall.hafzatech.com` trusted client bootstrap, customer/install identity, signed license 6m/12m, automatic 15-day status verification plus owner manual Verify, early paid renewal same license ID, 15-day warning, public maintenance on expiry, seven-day backend grace, audited grace/revocation. Central HAFZATECH staff roles with least privilege, per-customer vendor bank/Easypaisa/international/WU/eligible future PayPal receiving accounts, verified invoice payment before license signing, encrypted optional issuance, separate client operating ISO currency/IANA timezone per hall and support sync. Keep existing VenueDesk functionality untouched and license release OFF until CI and operator release gates.

## Phase 9.2 — Central operator staff portal implementation checkpoint (October 18, 2026)
The dated Phase 9/9.1 "not implemented" baseline above is historical. Current code and
`AI_HANDOFF.md` have implemented isolated installation enrollment, paid-term
and signed status/grant foundations, staff MFA/CSRF sessions, internal
role-scoped read-only vendor dashboards, immutable billing/support audit and
self-password change. Last fully green operator source before this batch:
`e34e9b8`, GitHub Actions 39165362826, all six jobs passed.

Next gated development includes an INTERNAL vendor operator application
composition with injected external secret-manager/MFA and native cookie writers;
pending-only staff account provisioning with no roles or usable credentials.
These new candidate features require their own exact-SHA hosted CI. They DO NOT
constitute a deployed staff website. DNS/TLS/proxy, KMS-backed credentials,
bank settlement verification, real HSM signer, operator recovery and
production release certification are still blockers. Never switch on the
production toggles merely because development CI passes.


### Phase 12 verified checkpoint — read-only loan drafts
- Verified source `4fa961e063de40133df1c4534367e393c0f1f8ce`: [CI 39242040957](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39242040957) all six SUCCESS. Owner/assigned-manager hall draft history with item snapshots is implemented but default-disabled for production.
- Read-only physical stocktake evidence history is being verified separately; no book-stock adjustments, owner approvals, independent handover acknowledgments, external evidence, partial returns or completion PDF are released.


### Phase 12 purchase claim implementation checkpoint (October 19, 2026)
- Independently default-OFF immutable purchased-stock claim request, read-only scoped review and owner/manager inventory form verified through source `843ce833`, [CI 39271792091](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39271792091), all six jobs green.
- This records *unverified supplier claim and evidence references only*. Physical receiving, independent acceptance and atomic owned usable position credit remain NOT implemented; do not use as booked stock or financially settled purchase.


### Phase 12 next inventory verticals: validation pending (2026-10-20)
- Independent owned condition variance adjudication: gated immutable physical-count-to-book reclassification with zero net change to total owned, independent owner and separate financial loss resolution; test repair VERIFIED with all six GitHub CI jobs 39378225973 at `fe251f107d1486c711d3250fc426464170e0b99c`.
- Prepared next integrated backend/owner interface batch (NOT YET VERIFIED): SQL-enforced scope for condition-review-to-line-to-book movement, source-owner internal multi-item handover with private evidence, original existing independent destination receipt / partial returns, discoverable strict clean loan final approval and integrity-pinned numbered PDF download.
- STILL UNFINISHED: external source/destination custody, damage/missing/dispute adjudication into authorized financial obligations and recovery, partial liability settlement and final non-clean closure, end-to-end external loan receipts, multi-owner reporting, full approved acceptance coverage and production release review. All release gates remain OFF.

- Phase 12 EXTERNAL_IN physical delivery candidate prepared: separate hall manager draft, different physical receiving signer, independent owner approval after signed partner document integrity re-check, exact item borrowed-only book movements with no finance. Still requires external outward, partial external returns, damage/dispute financial obligations and complete case closure. OFF until acceptance.

- Parent `80102c503cc92fba618a08d12370bc151d0398cf` CI 39427134021 VERIFIED (all six). Internal source physical dispatch owner/admin page, strict SQL same-hall condition adjudication bindings and numbered clean-loan PDF owner controls verified. External borrowed intake successor separately CI pending. Production controls all OFF.


## Phase 12 external partner-return candidate (2026-10-20; CI PENDING)
- Parent ab146a61bdfdea23bc963895de75798a6592af22 is VERIFIED (GitHub Actions 39433026329: six successes). Next independent vertical includes default-disabled witnessed multi-item partial return batches for accepted outside borrowed stock, per-loan original-quantity ceilings, individually hashed signed handovers, immutable stock movement into borrowed transit, and separate owner/admin-attested partner receipt evidence/counts before transit is reduced. Repeat batches idempotent. Cross-hall and same-actor authorization fail closed.
- Not a return closure or payment: damaged/missing/disputed stock, partner-transit shortfalls, outside-lending outbound flows, financial obligations/recovery and final numbered non-clean closeout still need implementation, full tests and operator acceptance. Production gates remain OFF.


- Phase 12 partner return reconciliation follow-on candidate: clear per-loan/per-item accepted, returned-to-partner, partner-confirmed, still-in-hall and pending-in-transit aggregates; full physical return has a distinct NOT FINANCIALLY SETTLED status and no false overdue flag. No financial or closing authority. Retain all inventory release gates OFF; separate exact SHA CI required.


## Phase 12 external clean partner-return closure (candidate)
- Numbered immutable partner clean-return PDF, complete per-item original intake / witnessed partial batch / partner-signed receipt matching, multi-actor separation and role-specific owner confirmation + download. Explicitly no financial payment/penalty/claim assignment. Default OFF independent release flag; requires six CI successes on exact SHA. Disputed/damaged/missing financial resolution, nonclean closure, external_out and launch review still pending.

- VERIFIED: external_in borrowed intake at ab146a61 CI 39433026329; external partial return partner physical batch approval at ab75cd0f CI 39448663572; per-item/loan custody reconciliation 00f7efbc CI 39450008056 — all six jobs green each. Production OFF. External borrowed clean numbered return PDF is the NEXT separately gated candidate.


## Phase 12 external_out partner lending owned-stock dispatch candidate (CI PENDING)
Default-OFF outside lending from hall-owned inventory with manager draft separate witnessed partner signed handover and independent owner/admin original-exact quantity approval. Stock shifts owned_usable to owned_on_loan only; no borrowed or financial posting. Hall/loan/item SQL provenance and immutable movement/evidence; role-scoped Livewire controls and PostgreSQL checks. Return, condition assessment, financial liability, disputed/nonclean closure and production activation are NOT completed.


## Phase 12 external_out partial physical return / condition verification (separate candidate)
Three distinct duties across draft, outside dispatch and return review: partner-signed partial receipt witnessed by assigned staff, independent owner good/damaged/unresolved physical condition approval. Only good and damaged quantities may move original OWNED-on-loan back to OWNED physical buckets; unresolved remains on loan and is not implicitly written off. Exact per-loan cumulative returns cannot exceed immutable original dispatch. Company/hall/item binding in PostgreSQL, signed files hashed, multi-batch retry-safe. No financial obligation/settlement, disputes or completion PDF yet; feature OFF and exact six-job CI/pilot pending.


## Phase 12 external_out owner incidents and independent review (candidate)
Reuse immutable incident/independent decision lifecycle for separately approved outside-owned loan custody with direction-aware release gates. Damage alleged only within owner independently inspected physical damaged amounts; missing/dispute only against outstanding unaccounted source dispatch. No automatic invoices, financial liabilities or write-offs. Authorized owner/admin reports, distinct reviewer independently substantiates or denies; PG/Livewire tests and hosted CI pending, OFF.


## Phase 12 external_out all-clean returned owned loan closure (candidate, CI pending)
Single company-scoped serial-numbered EOL-series PDF certificate, only after independently recorded FULL GOOD exact original item return. Every signed physical evidence document must pass hash checks; rejects missing/damaged/disputed/unreviewed amounts, creator or return physical witness self-closure, foreign hall and repeated receipt. A certificate is not a debt settlement or inventory loss write-off. EOL feature gate remains OFF until hosted CI six jobs and operator acceptance.


## Phase 12 non-clean NCL final receipt (unreleased implementation, CI pending)
Independent original-hall Owner/Admin closeout now has a dedicated company-scoped numbered NCL record and immutable private SHA256-pinned PDF snapshot. A transaction locks the company and original external_out loan, checks evidence-bound zero on-loan custody, approved damage/missing disposal, no outstanding financial obligation, no pending/disputed claims, and distinct closing authority. Reprint checks the original PDF checksum. The management page includes finalization and protected download controls behind VENUE_INVENTORY_NONCLEAN_FINAL_CLOSURE_ENABLED=false. Existing physical writeoff, contractual waiver and money recovery mutations reject already finalized loans. This is not approved for production: targeted PostgreSQL concurrency, complete six-job CI, and operator acceptance remain mandatory, along with full Phase 12 register reconciliation.


## Phase 12 NCL closeout CI recovery and independent certificate signing (2026-10-20 ET)
- Previous exact HEAD 0640d2c39db99b84cc1b93fd427dd7ad4377a217 failed GitHub Actions 39533213061 only in laravel-postgres: foreign-hall certificate probe returned ConflictHttpException rather than the expected authorization denial. Retain the original PostgreSQL negative test. Commit fb6fc5bccd7aafb30c5df3c5ae89fad92ce693fd now checks original loan company/hall/direction before numbered PDF lookup, preserving cross-hall privacy.
- Commit ef6c75df5145f6ee61604c48bf4893369d9860be additionally prohibits independent final NCL signing by the actor who approved a contractual financial waiver for the loan. Commit 7a93437030009d2f992bde162e152bf650331ed6 adds regression refusing previous disposition/waiver approver as final signer. Neither change relaxes physical, finance, role, evidence or production gates. Latest exact-SHA six-job CI 39534800447 requires full completion and assessment; no staged pilot/release approval yet.
- Remaining acceptance includes real two-connection PostgreSQL numbered issuance races, full abnormal inventory/financial lifecycle across roles and all approved Phase 12 feature reconciliation; do not declare production ready or advance Phase 13.


## Phase 12 NCL verification and SQL source-hardening checkpoint (2026-10-20 ET)
- 5f965410dde5eb3fe0dd1321078c3d69944aaf6d / GitHub Actions 39535178995 SUCCESS all six required jobs for cross-hall numbered PDF authorization, segregated waiver signoff, replay/immutable NCL closure and post-closure fixture correction.
- 103f5f3737d9bd777e3e1c61e2b241c4ee5ff76c failed hosted CI because converting the entire long-lived external-owned lifecycle test to DatabaseMigrations allowed immutable test records to leak into later tests (51 unrelated fixture failures). Recovery separated PostgreSQL company-lock concurrency into its own minimal DatabaseMigrations test, preserving original RefreshDatabase lifecycle. Corrected source b15ca51e0da9e1e9abc11e51d6cc4074a1efc77f / CI 39535802293 SUCCESS all six jobs.
- Additive SQL origin guard candidate ede17499fb01b72e531e253c2939523e92621b03 enforces company+loan+original lending hall compound FK and external_out-only NCL direction trigger; regression rejects forged same-company foreign-hall certificate. Successor 71a10d7123cd2f5055a11c1f699bd82817fb0e63 also rejects an internal loan's forged NCL certificate in PostgreSQL. Latest CI 39536263888 remains PENDING full exact six-job verification; do not call this additive SQL guard VERIFIED before passing.
- All inventory/finance production release flags remain OFF. Outstanding Phase 12 certification: exact final CI, operator/staging pilot, full physical/financial reconciliations across internal/external-in/out including abnormal loans, missing old approved feature-register reconciliation and any remaining issue fixes; no Phase 13 promotion.


## Phase 12 final NCL integrity release review — October 21 UTC (prepared dependent documentation)
- Exact verified base: `d9c22608cbf8111878cb2fddd8f8e3c96970bbb7`, GitHub Actions [39540327969](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39540327969), all six jobs SUCCESS. Includes original-hall/direction PostgreSQL NCL source guard, physical writeoffs, partial waivers, cash-based recovery/reversal, owner UI and NCL PDF lifecycle regression. Production flags remain OFF.
- Subsequent staged code: `5afb3fb6` prevents NCL storage overwrite on occupied UUID; `7e5f4b15` freezes partner-response write/review after NCL under company lock and preserves existing private files; `62461f3f` prohibits financial obligation invisibility when partner response controls are off. Each must pass its own six-job exact-head hosted CI before verified.
- Remaining: full accepted Phase 12 feature-by-feature review, real concurrent multi-loan issuance certification, staging operator acceptance of physically abnormal internal/external-in/external-out cases and backup recovery, explicit security/release approval. DO NOT enable inventory/finance production flags or promote Phase 13 based on a code-only green run.


## Phase 12 additional queued safety regressions (October 21 UTC)
- Candidate `ba62dec6` reorders original physical-incident final NCL refusal to run *after* obtaining the same immutable source loan row lock used by the final issuer. This prevents TOCTOU where the existence check could precede a competing closeout while the incident waits for the loan. Regression denies new incident after final and preserves historical count.
- Candidate `30617b57` refuses final NCL issuance while any loan-bound immutable counterparty document still awaits separate owner/admin integrity review. Regression covers the pending blocker, premature final rejection, independent review, then fully reconciled close. No production release gates enabled.
- Both candidates must be published only by exact expected-SHA fast-forward after EACH parent commit has six-green hosted CI. Never mark verified or release based on a detached object.

## Phase 12 concurrent NCL verification and issuer-evidence follow-on (2026-10-21 UTC)
- Independently approved external_out NCL two-loan serial lock race VERIFIED: `49c363e5`, [CI 39552677040](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39552677040), all six jobs SUCCESS. The second real committed-fixture PostgreSQL worker demonstrably waited on the company serial lock; successful original NCL-1/NCL-2 were scoped to different loans with immutable PDF hashes and no invented finance journal.
- Cross-loan UUID-collision and physical/financial no-double-post regressions VERIFIED `5324c1bf`, [CI 39551382495](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39551382495), all six SUCCESS.
- Additional original certificate issuer-account/UTC timestamp evidence is pending exact full hosted CI, not yet VERIFIED. The next unfinished functional areas remain abnormal INTERNAL and EXTERNAL_IN final reconciliation, all Phase 12 feature/UI/permissions/audit/retry review, operator staging/restore/security acceptance. Production inventory/finance release OFF; no Phase 13 until accepted.

## Phase 12 INTERNAL abnormal damage partial acceptance (2026-10-21)
- True source-hall owner custody UI and revocation verified `0481a43f` CI 39667565865 six SUCCESS.
- Independently reviewed physical-owned damaged retirement, immutable physical evidence-proposal-third owner decision, CAS movement and full Owner UI regressions VERIFIED `3d4342d9` [39671584251](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39671584251), six SUCCESS, after real repair of historical parse failure `afa2438c` CI 39671406699.
- Security follow-on exact-loan form binding, authorization/replay postclosure hardening requires its own six-job CI before verification. This is NOT financial settlement or loan completion. INTERNAL missing custody and independently evidenced money/counterparty disputes as well as EXTERNAL_IN abnormal partner return are OPEN. Pilot/security/backup/release approvals not evidenced. Production flags OFF, no phase promotion.

## Phase 12 internal physical balance after independently approved damage retirement
- d7669b4c [CI 39672712150](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39672712150) six SUCCESS: bound original internal loan choices, immutable financial/physical scope gating and source-hall postclosure safety.
- Next read-only Owner UI reports original externally witnessed damaged return, separately approved physical retirement, outstanding damaged disposition and integrity mismatch without manufacturing financial settlement. This follow-on requires its own exact CI. No acceptance or production release inferred.

## Internal damage integrity follow-on
- `f5804884` / [39675049673](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39675049673) six SUCCESS: real independently approved physical damaged-stock retirement is visibly segregated from original damaged quantity and unresolved financial/final closure status. Historical `969f59bf` [39673758671](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39673758671) failed solely due prematurely placed third-owner test, fixed in f580.
- Movement provenance hardening successor requires own exact six-job verification; no staged operator signoff, settlement, external partner acceptance or production flag activation.

## Phase 12 internal original borrower statement & independent review (2026-10-21)
- Verified previous `495903b3` CI39682074643 six SUCCESS: destination book only aggregate, never testimony.
- Successor: default-OFF actual signed borrowing manager original-loan missing/dispute evidence, per-original loan/item evidence SHA256, immutable independent source-hall decision, Filament actions, retry/isolation tests. Its own CI pending at publication. Financial obligation, cash, physical stock removal and final abnormal closure deliberately remain blocked. Pilot/restore/security approval pending.

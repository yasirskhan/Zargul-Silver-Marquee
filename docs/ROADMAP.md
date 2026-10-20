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

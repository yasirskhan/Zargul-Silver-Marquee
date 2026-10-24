# Development Roadmap

## Phase 0 — FOUNDATION (code complete and technically accepted; operator release separate)
Establish dedicated repo, approved spec, requirement register, AI handoff, CI, explicit separation from property-platform; import and label proof-of-concept interfaces. CI verification is required before marking complete. Acceptance evidence: docs/PHASE0_ACCEPTANCE.md at `7ab6ff8`, CI 40020880262 all six success; final integrated baseline `df1e5b8`, CI 40058788632 all six success.

## Phase 0.5 — Website CMS architecture + editor usability (code complete and technically verified; real Owner/staging acceptance separate)
Formalize owner-configurable website design, typed safe sections and columns, publishing/audit/access policy and page/version/storage model. Test pure layout/revision helpers, then build a practical visual editor integrated into VenueDesk. Owner can move text, images, sections, columns and mobile stacking. Keep business price and booking constraints on backend. See docs/WEBSITE_CMS_DESIGN.md and docs/PHASE0_5_ACCEPTANCE.md at `63c6b7a`, CI 40050039945 all six success; final integrated baseline `df1e5b8`, CI 40058788632 all six success.

## Phase 1 — Secure server foundation (technically accepted; external email/backup/operator acceptance separate)
Laravel/Filament/PostgreSQL versions verified; migration foundation, operating company and halls, authentication, roles, MFA, policy tests, tamper-resistant audit. Acceptance evidence: docs/PHASE1_ACCEPTANCE.md at `4024399`, CI 40064675256 all six success.

## Phase 2 — Owner configuration (technically accepted; booking/quote and production separate)
Sessions morning/noon/evening, times, grace, overtime, buffer, capacity, seating pricing, catering and add-ons; snapshot/versioned pricing. Acceptance evidence: docs/PHASE2_ACCEPTANCE.md at `39a0315`, CI 40072138788 all six success.

## Phase 3 — Bookings and universal search (technically verified; live confirmation/operator acceptance separate)
Color-coded multi-hall calendar, temporary holds, concurrency constraints, customer profiles, enquiries and mobile wizard.
A new PostgreSQL backed INTERNAL owner/admin/assigned-manager/reception confirmation flow consumes a reviewed proposal into a separate confirmed reservation, locks an exclusive hall/date/program claim, and supports same-hall rescheduling and audited cancellation. The calendar projects confirmed occupancy. Release gate VENUE_BOOKING_CONFIRMATION_ENABLED defaults OFF until operator readiness signoff. This is NOT a public intake, full booking management milestone or ready-to-charge booking: new customer onboarding for manager/reception, cross-hall transfers, operator timezone, expiry/holds UX, consent/privacy signoff, production race acceptance, invoice/payment and mobile browser UAT remain open. Acceptance evidence: docs/PHASE3_ACCEPTANCE.md at `bf288cb`, CI 40075891670 all six success; full browser hold/confirm remains gate-off/operator.

## Phase 4 — Finance (technically verified; provider/bank/operator separate)
Quote, invoice, receipt, deposit, ledger, correction, refund, cash reconciliation, PDFs. Acceptance evidence: docs/PHASE4_ACCEPTANCE.md at `1d4afb3`, CI 40090557799 all six success; all production finance/payment gates remain OFF.

## Phase 5 — Approvals (technically verified; Telegram pilot/operator separate)
Owner review center, Telegram notification integration, approvals audit, permissions, emergency delegation and approvals under concurrency. Acceptance evidence: docs/PHASE5_ACCEPTANCE.md at `ef6217b`, CI 40113341018 all six success; live Telegram credentials and real financial application remain OFF.

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

## Phase 12 — Hall inventory and multi-item loans (historical scope baseline; guarded source now implemented)
Hall-owned inventory catalog and stock movements, inline item entry, monthly/quarterly/post-event audits, minimum levels, immutable discrepancy review, independent internal and outside-establishment multiple-item loans, count/return/condition evidence, partial receipts, automatic closure ONLY on independently confirmed full match, numbered PDF and hall reports. Initial draft-only catalog, physical counts, multi-item requests and bounded authorized read-only review of saved loan drafts and physical count evidence are implementation foundations, release gates OFF; actual witnessed dispatch/acceptance, inventory balance verification and reconciliation, returns and completion receipts remain NOT implemented/released. Preserve booking/finance gates.


**Phase 12 current technical/operational disposition (2026-10-23):** Main approved inventory flows have source/UI/audit/regression evidence. Six-job green code baseline 0a08a8eb/39895044762, and additional two-original-loan partial-return race test e6965049/39896741281 six green; separate staging bootstrap and live operator guide published but no verified server URL or operator credentials. Full 59-ID mapping and unresolved 17 code-present / 2 foundation pilot gates in docs/PHASE12_FINAL_ACCEPTANCE_REPORT.md and docs/PHASE12_59_REQUIREMENT_EVIDENCE_MATRIX.csv. Infrastructure staging + PostgreSQL/document-store restore, signed partner/bank evidence and independent Owner/Manager/security acceptance remain BLOCKED EXTERNAL. Phase 12 stays OPEN, production inventory and finance OFF. Do not promote Phase 13.

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

## Phase 12 original INTERNAL jointly witnessed missing/disputed physical finding
- New source candidate builds immutable per-original-loan signed borrower + substantiated lender incident matching, source signed proof, independent second source reviewer, bounded unreconciled physical quantity and private authorized evidence access, audit and Livewire controls. Confirmation is ONLY a physical finding, not return, write-off, settlement or closure. Production gate OFF; six-job exact SHA and staged physical/retry/operator testing required. EXTERNAL_IN and finance/abnormal close OPEN.

## Phase 12 INTERNAL exact original-loan joined finding verified 244d4eaf
- [39703264721](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39703264721) six SUCCESS including real PostgreSQL and authenticated browser. Borrower/lender signed per-item findings are immutable scoped review evidence only; no source physical retirement, money, or final certificate. Further signed original borrower proof access hardening is additive/CI PENDING. Physical return reconciliation and financial/abnormal closure still OPEN. Production inventory and finance OFF.

## Phase12 original loan subsequent witnessed returns safety follow-on
- Prepared immutable original-loan return-aware physical history, marks historically confirmed findings stale when actual later good/damaged returns reduce current open count. Exact receipt/acceptance joins; no shared destination catalog aggregate treated as per-loan. New signed original loan regression exercises ten good returns against stale three-missing finding. CI PENDING; unchanged production OFF.

## Phase 12 immutable INTERNAL physical finding correction (source candidate)
- Historical confirmed source findings never silently edited or overwritten. Signed source PDF/photo amendment proposal requires an independent THIRD current source owner/admin and append-only exact FK-bound review. Approved correction supersedes documentary finding, but cannot clear physical original-loan custody, create cash/debt, waive debt or issue final receipt. Current live return ledger is separately shown. Include PostgreSQL two-connection loan lock and actual Livewire UI. New exact SHA all-six CI required; production OFF.


## Phase 12 original INTERNAL late physically witnessed arrival (2026-10-22, verification pending)
- Source batch e6b7f47: separate independent source Owner/Admin witnessed proof for newly located units that remained unresolved in an earlier reviewed internal return batch. Never silently change the original acceptance or turn a documentary correction into physical stock; one exactly bound source item per event, quantity under immutable original unresolved cap, source owned-on-loan / borrowed transit dual-sided CAS, private SHA256 evidence and immutable audit, default-OFF Livewire UI. Exact GitHub run 39716255948 must reach all six SUCCESS before verified.
- Follow-on caea98a1 is only a detached prepared candidate until successfully fast-forward published, then requires its own six-job exact SHA. It checks each new physical return record's full immutable movement provenance and prior-proof integrity, including forgery regression.
- Phase 12 is OPEN. Missing separate INTERNAL abnormal liability and settlement, final independently signed abnormal original-loan number/PDF, EXTERNAL_IN missing/damage/dispute disposition and financial treatment, full security/restore/operator acceptance. Do not promote Phase 13 or enable production inventory/finance flags.


### Phase 12 later-original-return end-to-end hardening and concurrency evidence (2026-10-22 UTC)
- VERIFIED e6b7f47 exact run 39716255948 SIX jobs success, new genuine source-observed returned-in-transit stock with no payment/automatic closure.
- Follow-on 383d7650 exact run39717167809 pending Laravel PostgreSQL at last check (five other jobs success), independently verifies all late physical movement provenance with authenticated source-only private proof download and register/handoff updates. Must not claim green until backend completes.
- Separate detached candidate through 17c586c0 adds true two-connection original-loan lock contention regression, occupied storage failure, complete UI count/signature submission, immutable replay and per-batch overflow proof checks. Await parent source six-job CI before safe expected-SHA publish and new exact six-job CI. Next approved missing workflow is internal anomalous financial obligation and genuinely independently resolved abnormal closure, then external_in discrepancies. Original physical statements NEVER mint stock or money; all production OFF.

- Exact 383d7650 hosted run 39717167809 subsequently completed ALL SIX SUCCESS, including real Laravel PostgreSQL and authenticated UI suites. Next transaction-lock/failure/real Livewire source candidate remains independently CI pending before any claim of verified.

## Phase 12 external borrowed partner short/damaged return evidence (VERIFIED 2026-10-22)
- `9b4db002e357738e506740173ff35f167c928faa` / [run 39718936025](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39718936025) ALL SIX SUCCESS. Default-OFF immutable EXTERNAL_IN partner short/damaged return counts: every shipped line requires the partner-signed received and damaged quantity, the evidence is a private PDF/photo pinned by SHA256 and byte length, and a different current borrowing-hall owner/admin independently decides substantiated/rejected/requires-more-evidence. Exact loan/batch/shipped-item caps, immutable SQL and trigger history, scoped audit, and Owner Livewire evidence/review controls.
- A pending or substantiated discrepancy refuses normal exact partner-receipt confirmation, so a signed shortfall cannot be silently overwritten. The discrepancy is evidence only: borrowed transit is not cleared, no stock is retired, no liability/journal/payment/waiver is created and no numbered closure is issued. PostgreSQL and Livewire regression passed on the exact SHA.
- `641100be24955a3d17cd92086261e23d51d3ec4c` / [run 39722040547](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39722040547) ALL SIX SUCCESS. The per-loan custody read model now exposes partner-reported received/damaged/missing totals from pending or substantiated counts (rejected counts excluded), pending or requires-more-evidence review status and an integrity warning without changing transit, stock, finance or closure. Rejected-only discrepancies do not block a later exact partner receipt.
- `39f28c99e86af9271054e5bf6fc2269decfd00cc` / [run 39725278812](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39725278812) ALL SIX SUCCESS. After independent substantiation, a different current owner/admin clears exactly the partner-received units from borrowed transit through immutable `confirm_borrowed_return` movements; missing units remain in transit. SQL guards bind the exact discrepancy, decision, item, quantity and movement; replay and conflicting identifiers fail. Read-model outstanding subtracts cleared units; damaged or missing quantities keep the loan non-clean. No money, liability or closure. The first attempt `c21f91b` failed only on a wrong expected outstanding total and migration rollback order; corrected at `39f28c9`.
- `c872af4da08f520a0b6b133a1fad4c307b9b3be0` / [run 39727336870](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39727336870) ALL SIX SUCCESS. ADR-0008 and default-OFF provisional external-in partner loss obligation: only a substantiated discrepancy, quantity basis = missing + damaged, manual ISO currency/minor amount, one immutable obligation per discrepancy, separate current owner/admin decision, exact composite FKs, audit and replay denial. No invoice, journal, payment, stock movement, partner admission or closure. Owner UI and verified payment-out settlement remain OPEN.
- `45d9eb75077c97d329feac63cb43f56520b5b9b0` / [run 39729151047](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39729151047) ALL SIX SUCCESS. Owner Hall Inventory proposal and independent review UI for the provisional obligation, with amount/basis/status display and Livewire regression. Still no invoice, journal, payment, settlement or closure; verified payment-out settlement and non-clean closure remain OPEN.
- `d6c9157510a966d49792c11e52f607b4d4045bbb` / [run 39732210760](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39732210760) ALL SIX SUCCESS. Default-OFF partner payment evidence: only an approved obligation, currency-bound and amount-capped, cash/bank reference and paid date, private PDF/photo SHA256+size, separate current owner/admin verification. Immutable evidence/review; no journal, cash mutation, settlement or closure. Owner UI, balanced ledger posting and reversal remain OPEN. The failed `935b7cc` run remains historical (wrong decision-table lookup), fixed at `d6c9157`.
- `64aa7d64b62df80f59efee9cb487a5262c1f159f` / [run 39734053941](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39734053941) ALL SIX SUCCESS. Owner Hall Inventory record/independent-review UI for partner payment evidence, with amount/reference/date/proof and status display plus Livewire regression. Still evidence only: no journal, cash mutation, settlement or closure; balanced ledger posting/reversal and non-clean closure remain OPEN.
- `40ab4ce65138a07621eedba58e3a6302ed9457a5` / [run 39737410547](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39737410547) ALL SIX SUCCESS. When finance posting is enabled, independent verification of a partner payment posts a balanced `inventory_partner_payment` journal (debit `inventory_partner_loss`, credit `cash_on_hand`/`bank_clearing`); a different owner/admin can post an immutable reversal that swaps the lines. No cash mutation, settlement or closure. Historical red `8350424` remains historical (entry_type varchar(24) too narrow), fixed at `40ab4ce`.
- `21c13425a036c9fa1aa38d12d05b08eb6817d65b` / [run 39739681884](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39739681884) ALL SIX SUCCESS. Owner Hall Inventory reversal UI for a verified partner payment with reason, independent actor separation, exact status display and Livewire regression. Cash, settlement and closure remain unchanged.
- `e009c415537a6985b6f39394123b783c3efde7f4` / [run 39774276958](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39774276958) ALL SIX SUCCESS. Read-only EXTERNAL_IN non-clean closure readiness: physical units at hall, missing transit vs substantiated partner missing, pending discrepancies/obligations, fully settled and unreversed obligations, and verified ledger posting, with explicit blockers and no stock/money/closure. Historical red `cc041cd` remains historical (test expected zero physical units while two borrowed-usable units legitimately remained), corrected at `e009c41`.
- `f4f2546100ddc6067ff0daad1e0467e26e22fb29` / [run 39779366263](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39779366263) ALL SIX SUCCESS. Final external-in non-clean closure only from green readiness: writes off exactly the settled missing borrowed transit, preserves owned stock, issues one company-serial `ENCL-<n>` numbered immutable private PDF (SHA256/size) with audit and Owner action/status. No cash mutation or new financial posting.
- `26fa63ec680c2571f68f45a235b3c5df781fe71f` / [run 39782634798](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39782634798) ALL SIX SUCCESS. Provisional INTERNAL abnormal loss obligation foundation: confirmed physical finding only, finding quantity basis, manual ISO currency/minor amount, one immutable obligation per finding, separate source owner/admin decision excluding proposer/finding proposer/finding reviewer, composite FKs, audit/replay denial. No journal, cash, stock retirement or closure; Owner UI, settlement/ledger and numbered certificate remain OPEN.
- `f76bde71dcbb04bf3da69942a239dc64ad203547` / [run 39787096473](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39787096473) ALL SIX SUCCESS. Owner/manager UI for the provisional INTERNAL abnormal loss obligation: propose from a confirmed finding, manual currency/minor amount, independent review by a different source owner/admin, status display and Livewire regression. No journal, cash, stock retirement or closure; settlement/ledger and numbered certificate remain OPEN.
- `695d5bd4c60ddb136c92d90e8ad99af23e260f29` / [run 39791495105](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39791495105) ALL SIX SUCCESS. Private internal settlement evidence: approved obligation only, currency/amount bounds, cash/bank reference/paid date, real PDF/JPEG/PNG SHA256, one immutable settlement, independent source-owner verification excluding recorder and obligation proposer, audit/replay denial. No journal, cash mutation by this layer, stock retirement or closure; settlement UI, ledger and certificate remain OPEN.
- `ef326a4171ea0bda757de764477e6d7cdf4ffefa` / [run 39797843118](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39797843118) ALL SIX SUCCESS. Owner UI for private internal settlement evidence and independent verification: record currency/amount/method/reference/paid-date/proof and verify as a different source owner/admin, with explicit no-journal/no-cash-mutation/no-stock-retirement/no-closure status. Ledger/reversal, write-off and numbered certificate remain OPEN.
- `303975edde401f62659638eba1f50b5c5113e4dc` / [run 39803376087](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39803376087) ALL SIX SUCCESS. Read-only internal abnormal closure readiness: confirmed findings, physical units open, approved obligations, verified settlements, and explicit blockers for the accounting/ledger decision and the unreleased authorized write-off/certificate. `ready` false; no journal, stock retirement or closure.
- `84ff55f59a59b437b3c23eac66a44d9a7262b047` / [run 39818374871](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39818374871) ALL SIX SUCCESS. ADR-0009 Option A internal abnormal closure: separate source owner/admin write-off authorization, exact confirmed `owned_on_loan` write-off, full verified settlement coverage, company-serial `IAC-<n>` numbered immutable private PDF, audit/replay denial, **no company-level journal**. Owner UI for authorization/closure remains OPEN.
- `96099f1251a17b0b07a04f73ab9690031564c632` / [run 39821644051](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39821644051) ALL SIX SUCCESS. Owner UI for internal abnormal write-off authorization, numbered certificate issuance and private download; readiness aligned to Option A; certificate display verified by Livewire. All production flags OFF; operator acceptance OPEN.

## Phase 12 acceptance status register (2026-10-22)
- Consolidated verified evidence and exact open gates in docs/PHASE12_ACCEPTANCE_STATUS.md. External-borrowed evidence through closure is verified; INTERNAL abnormal obligation is a backend foundation with UI, accounting decision, settlement/ledger, write-off and numbered certificate OPEN. Operator/security/restore acceptance remains external. Phase 12 stays OPEN and Phase 13 is not promoted; all production flags OFF.
- ADR-0009 (internal abnormal settlement accounting) is **ACCEPTED: Option A** (owner decision 2026-10-22). Internal settlement is an evidence-backed inter-hall allocation; no company-level journal. Authorized physical write-off and numbered certificate are the remaining implementation, with all production gates OFF.
- OPEN: Owner UI for the provisional obligation, verified cash/bank payment-out settlement and reversal, non-clean external-in closure, INTERNAL abnormal financial/closure, and operator/restore/security acceptance. All production flags remain OFF.

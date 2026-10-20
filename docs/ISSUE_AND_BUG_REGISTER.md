# VenueDesk / HAFZATECH — Issue and Bug Register

Maintain this alongside [QUALITY_AND_BUG_REMEDIATION_POLICY.md](QUALITY_AND_BUG_REMEDIATION_POLICY.md). This document tracks **observed, reproducible defects**, not general unfinished features. Historical CI regressions and repairs are recorded in AI_HANDOFF.md and archives. This new register is not evidence of zero existing issues; current live CI and the handoff always need review. Never put passwords, financial secrets or customer personal information in issues.

## New observations
No NEW defect was discovered by this documentation-only policy change. Existing CI failures and open externally blocked items still require live verification against handoff and relevant logs before accepting a release.

## For each issue create a dated entry
**BUG-YYYYMMDD-### — short title**

- Affected feature ID / requirement:
- First detected / source SHA / CI run:
- Severity: BLOCKER / HIGH / MEDIUM / LOW
- Actual vs expected / exact reproduction:
- Security, financial, tenant and release impact:
- Root cause:
- Status: NEW / REPRODUCED / FIX IN PROGRESS / FIX PUBLISHED — CI PENDING / VERIFIED / BLOCKED EXTERNAL
- Corrective source SHA / regression test:
- Exact verified CI run and required jobs:
- Safe rollback or default-OFF release gate:
- Accountable next action, follow-up and immutable issue history:

## BUG-20261018-001 — Clock rollback suppressed central license verification

- Affected feature ID / requirement: CENT-002; fifteen-day automatic verification, signed local grants, outage retry and clock rollback safety.
- First detected / source SHA / CI: code review of verified baseline `1c94d1f74a08eb355c07bd9cbbec510c58d9bafd`, run 39186728319 SUCCESS. Existing `CentralLicenseCheckSchedule::isDue` considered a future `next_retry_at` before checking if the local clock had moved more than five minutes behind the last accepted signed central response.
- Severity: HIGH (integrity check delayed during clock rollback; no evidence local signature verification itself was bypassed).
- Actual vs expected / exact reproduction: after signed success Oct 1, failed due check Oct 16 generates a future retry. Evaluate auto check at Sep 30 with rollback: prior order returned false due to stale future retry, though rollback must cause prompt online verification. Naively always overriding retry can cause repeated requests after a persistent rollback.
- Impact: potential delayed central check-in, not forged paid entitlement; released feature flags remain OFF.
- Root cause: retry backoff evaluated before rollback, with no distinction between an OLD future retry and one already attempted in the rolled-back time window.
- Status: VERIFIED — published release-gated fix `68ab7574a8a6c19228fbb7227ca1de67cc374c66` passed exact hosted six-job CI [39187718384](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39187718384). Original detached draft `b0b43858` was superseded by the published fix.
- Corrective source / regression: `backend/app/Services/CentralLicenseCheckSchedule.php` evaluates first rollback promptly but respects bounded retries once a rollback-era attempt exists. `backend/tests/Feature/CentralLicenseCheckScheduleTest.php` reproduces first rollback, subsequent backoff and retry boundary, alongside existing 15-day/manual behavior.
- Exact verified CI: [39187718384](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39187718384) SUCCESS, all six required hosted jobs, source `68ab7574a8a6c19228fbb7227ca1de67cc374c66`. Local PostgreSQL regression remains in the suite.
- Safe rollback / release gate: `central_control.activation_enabled` and `scheduled_checks_enabled` default OFF; previous confirmed source `1c94d1f7` and immutable signed local license remain intact until a verified safe update.
- Next: retain rollback/retry coverage in regular releases and verify the signed customer license experience before enabling production signing. Original failed CI history remains in AI_HANDOFF archives.
- Regression isolation: original first draft mixed rollback failure state into the existing normal cadence test; corrected separate RefreshDatabase test in `b0b43858` preserves all legacy assertions. Neither initial draft published.


## BUG-20261019-002 — Synthetic invoice test counted unrelated settlements
- Affected requirement: CENT-005; independent vendor billing and invoice creation regression.
- First detected / source SHA / CI: `4873f21a915e8c3e159635842d9394e15b9b84ad`, [CI 39191227308](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39191227308); central-postgres FAILED at the no-false-settlement assertion in `central/tests/staff_vendor_invoice_issuance.php`.
- Severity: LOW, test-isolation defect; production invoice service checks and immutable transaction passed before assertion; new issuance service remained DEFAULT OFF.
- Actual vs expected: prior central tests seeded unrelated independently verified settlements and signed revisions in the shared isolated PostgreSQL test database. The new test erroneously counted all rows instead of only those belonging to the newly issued synthetic invoice.
- Root cause: global fixture count instead of invoice-scoped query. Repair commit `86d57bf799d8dc6c0a5a5fbdce73da94466a9447` scopes settlement and paid-revision checks to exact invoice ID.
- Status: VERIFIED. Corrected source `86d57bf799d8dc6c0a5a5fbdce73da94466a9447` passed [run 39191319746](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39191319746) with all six hosted jobs SUCCESS, including central-postgres and full laravel-postgres. Unrelated fixture rows remain protected.
- Security/finance: no authorization, billing permission, financial state, signed-issuance or release gates were weakened.
- Next action: verify exact 86d57bf CI, preserve the original failed run, then verify follow-on protected internal staff invoice route/renderer separately.


## BUG-20261019-003 — Commercial plan version price/entitlements lacked database immutability
- Affected feature: CENT-005, vendor six/twelve-month plans, independent invoice issuance, signed license price/feature preservation.
- Detected: schema review of `central/schema/004_vendor_payment_eligibility.sql`, no UPDATE/DELETE block on `central_vendor_plan_versions`, despite immutable plan-version pricing requirements and later reads by paid-license preparation.
- Severity: HIGH data integrity risk if a privileged writer edits a previously quoted plan; no evidence that this occurred in production. Production invoice issuance remains disabled and the portal unprovisioned.
- Reproduction: a privileged PostgreSQL UPDATE of a synthetic plan version price or entitlement would previously be accepted, altering what the same version means after issuance. Normal staff HTTP clients have no such edit route. Risk comes from internal writes/migrations, not direct customer input.
- Remedy: DETACHED schema `central/schema/028_vendor_plan_versions_immutable.sql` adds trigger using the established immutable audit rejection function. Regression UPDATE and DELETE attempts require SQLSTATE P0001; new versions may still be inserted normally. No retroactive data changes.
- Status: VERIFIED. Source `3c19e3ba3f59193bf201b5181aeec2070600a61c`, [CI 39194922044](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39194922044) SUCCESS all six hosted jobs including immutable plan update/delete and legitimate new-version tests. Operator releases remain default-OFF.


## BUG-20261019-004 — Superseded plan remains selectable for new vendor invoice
- Affected: CENT-005; latest published immutable price and subscription plan-version selection.
- Discovery: code review of `CentralStaffVendorInvoiceIssuer::offers` and `issue`; offer query included older versions and new invoices could issue lower legacy quotes after a newer same-code plan existed.
- Severity: MEDIUM commercial integrity risk; HAFZATECH commercial live release gate OFF, no evidence of production exposure.
- Reproduction: create `plan_code=issuer_test_6` version 1 price 12500 and version 2 price 13000; original logic offered both and issued fresh version1 invoice.
- Remedy PREPARED DETACHED: show only maximum version per plan_code, reject fresh obsolete plan while preserving idempotent same-request previously issued invoices, assert original/new prices; never alter paid settlement or signed grants.
- Status: VERIFIED. Source `39e132ad28da40eb6ab508a60a80ff3bdd824629`, [CI 39195553866](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39195553866) SUCCESS all six hosted jobs. Old request replay and latest-only new invoice selection tested; commercial live release remains OFF.


## BUG-20261019-005 — Vendor directory search accepts invalid UTF-8 into PostgreSQL
- Affected: protected staff customer name directory; CENT-004.
- Severity: LOW, validation/availability robustness; no observed customer leakage and operator vhost OFF.
- Source detection: `CentralStaffCustomerDirectory::search` validated byte length and control bytes but did not explicitly check input encoding before passing a bound search prefix to PostgreSQL's UTF-8 string operations. Malformed user input could cause an unhandled database character-sequence error rather than typed 422.
- Reproduction fixture: submit bytes `C3 28` (invalid UTF-8) in a synthetic MFA billing staff directory search. Expected typed `invalid_customer_directory_request`; original parser would let bytes reach the database.
- Remedy: validate complete UTF-8 using `preg_match('//u',$prefix)===1` before parameterized query, and add PostgreSQL negative regression. Leave SQL bind, current role rechecks, 20-result bound, MFA, CSRF and default-OFF operator settings unchanged.
- Status: VERIFIED at source `c4b6e8f036fb6235d6bf29b3529498b1d03541e4`, [hosted CI 39241117684](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39241117684) SUCCESS, all six required jobs. Default-OFF operator release remains unchanged.


## BUG-20261019-006 — Immutable purchase-test assertion aborts PostgreSQL outer transaction
- Affected: new Phase 12 purchase claim regression, source `3d30a276`, CI [39268321434](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39268321434) Laravel failed, five other jobs passed.
- Reproduce: intentional immutable-row UPDATE raises QueryException during RefreshDatabase test; catching error without rollback leaves PostgreSQL outer transaction aborted and a later hall insert fails with SQLSTATE 25P02.
- Root cause: regression test did not isolate the intentional failed SQL in a nested transactional savepoint.
- Fix: nest the expected rejected UPDATE in DB::transaction so Laravel rolls back the inner savepoint before subsequent assertions. No changes to immutable database trigger, production authorization, money, quantity posting, or release flags.
- Status: VERIFIED at repair source `e937c9a1907c318f802e2e1c9bd6bedad87368f4`, [CI 39269559226](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39269559226) SUCCESS all six. Subsequent history/UI sources also six green.


## BUG-20261019-006 — Receiving relationships lack database-level hall/purchase scope
- Affected requirement: Phase 12 immutable receiving/review integrity.
- Discovery: Code review of the committed receiving and review migrations: each referenced parent ID was a valid single-column FK, but the child company/hall/item or purchase request columns were not all tied to that parent by a composite FK.
- Severity: HIGH defense-in-depth data-integrity risk for privileged SQL imports/recovery, not an observed production exploit. Release gates remain OFF.
- Reproduction: attempt a new valid purchase request and directly insert its receiving evidence with another hall ID, keeping a matching catalog item ID where possible; PostgreSQL should reject mismatched parent scope. Similarly a review with another hall or purchase ID must be rejected.
- Repair: additive composite unique constraints and scoped composite foreign keys; negative PostgreSQL tests use a distinct valid purchase request to avoid a false positive from the unique one-receipt-per-request guard.
- Status: FIX PUBLISHED — CI PENDING. Release OFF. Exact commit and six-job CI must be recorded before VERIFIED.


## BUG-20261019-007 — Scope-FK negative test could pass for the wrong constraint
- Affected: Phase 12 INV-012-FK PostgreSQL regression test.
- Root cause: initial attempted cross-hall forged receipt used an item only catalogued in the original hall, so the existing item FK could reject it before the newly added scoped purchase FK was tested.
- Impact: false confidence in defense-in-depth regression coverage, not evidence that new production constraints are ineffective. All release switches remain OFF.
- Fix: test attempts mismatched item under the SAME valid hall, with a second previously unused purchase request, and mismatched review purchase binding without foreign-hall mismatch.
- Status: FIX PUBLISHED — CI PENDING. Do not mark VERIFED until all six jobs pass on corrected SHA.


## VERIFIED correction to BUG-20261019-007
- Corrected source `2db2241936eef62092aaa7c68902f092789275de`, [CI 39304414245](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39304414245) SUCCESS all six jobs. Database composite-scope test no longer relies on catalog-item mismatch or duplicate request constraints. Production posting remains OFF.


## QA-20261019-OPS-MFA — Intermittent operations browser MFA challenge not visible
- Source/CI: `a4050b7d0714337de0791555e992b3f994d4ad12`, first run [39312723278](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39312723278) attempt 1, `authenticated-panel-e2e` failed waiting for operations TOTP input. Five other jobs were green.
- Retry of the same job on attempt 2 passed without code change. Root cause NOT established; not proof of a product MFA bypass. Continue diagnostics before any browser acceptance stability remediation. Never skip MFA, hide assertion or loosen authentication. Production inventory flags remain OFF.
- Status: INTERMITTENT / UNDER INVESTIGATION. Severity pending reproduction; no claim of corrected source.


## 2026-10-19 — Read-only destination loan review milestone closed
- New INV-013-VIS and INV-013-UI did not relax owning hall permissions, did not post inventory/finance, and received six-job CI success at `39b85575` (39315865530) and `67292f8f` (39316926171).
- Previous intermittent browser operations MFA challenge issue remains NOT root-cause resolved. Prior retry passing is not evidence of a repaired product defect. Do not skip or relax TOTP.


## BUG-20261019-008 — Dispatched internal loan incorrectly shown as DRAFT / NOT DISPATCHED
- Affected: Phase 12 hall loan history and owner/manager inventory interface.
- Severity: HIGH operational custody misstatement: `HallInventoryLoanDraftReview::recent` returned `draft_only` and `stockMoved=false` unconditionally, even after `HallInventoryInternalLoanDispatchService` posted source stock to `owned_on_loan`. Blade UI independently hardcoded DRAFT / NOT DISPATCHED.
- Reproduction: create two-item internal draft, independently source-dispatch with actual evidence, then view lending or receiving hall history; old history/UI incorrectly reported no physical dispatch.
- Root cause: read-model omitted immutable `hall_inventory_loan_dispatches` scoped join and view ignored status.
- Repair: scoped left join, distinguish `source_dispatched_receipt_pending` from `draft_only`, flag actual source movement without falsely claiming destination confirmation, and make UI show server-derived stage. Add PostgreSQL source/destination regression assertions to dispatch test.
- Status: FIX PUBLISHED — CI PENDING. Source `bd1b247c` [CI 39322244021](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39322244021); UI `d4e8f847` [CI 39322329883](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39322329883). Six-job exact-SHA required. No production switches enabled.

## BUG-20261019-008 — Resolved false DRAFT label after dispatched stock
- Source `2bff6f3d099a454ad91df0311a93e0f0317ebb20`, CI [39323304935](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39323304935) SUCCESS all six. Source movement now shown as actually dispatched, never incorrectly marked received or completed.
- The initial syntax regression and outdated Livewire heading assertion from that repair were reproduced and corrected in the same verified source; no test was removed.

## QA-20261019-INTERNAL-RETURN — Review join cross-loan isolation regression
- Discovery: draft read-only return history joined immutable return lines to draft line snapshots by tenant and catalog item alone. A repeated source catalog item in a second unrelated loan could duplicate the displayed original batch line.
- Fix: new join binds via the immutable batch's exact loan ID as well as company/item. Dedicated PostgreSQL regression creates another draft with the same item and checks the original return report remains a single line.
- Source `d5883f9fc13234e25a34c62c0f1509e6ea96e58d`, CI [39326982956](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39326982956) PENDING six-job verification. No production gate enabled.

## BUG-20261019-009 — Accepted return history can masquerade as unshipped DRAFT
- Affected: Phase 12 INV-013 loan status read model and HallInventory private history.
- Reproduction: independently ship and source-acknowledge all internal return batches while some original stock remains borrowed or unresolved. Prior read model always reported `partial_return_in_transit_source_ack_pending`; after new server status update, unmatched Blade fallback could report `DRAFT / NOT DISPATCHED`, and the receiving hall could lose the option to send the remaining usable stock.
- Impact: HIGH: incorrect physical custody interpretation and blocked legitimate partial return workflow. No automatic money posting or loan close observed.
- Prepared repair: count source-acknowledged batches within exact loan/company and display `RETURN ACKNOWLEDGED / OUTSTANDING RECONCILIATION REQUIRED` instead of DRAFT; allow another physically witnessed return only where server evidence proves returnable borrowed usable; PostgreSQL and Livewire regressions for outstanding/damaged counts.
- Status: REPAIR PREPARED, CI PENDING. Production loan gates stay disabled until exact six-job verification. Do not claim loan closure or settlement.

## QA-20261019-RETURN-RECONCILIATION — Outstanding custody transparency
- Scope: INV-013-OUTSTANDING. Existing immutable return/acceptance lines provide correct counts but owner/manager loan history does not yet combine quantities per loan to expose physical reconciliation outstanding across batches.
- Risk: operational misreading of unreturned borrowed stock, return-in-transit, accepted damaged and unresolved physical amounts as a completed loan. Keep controls OFF and never infer financial settlement.
- Prepared change: sum ONLY exact loan and company-scoped independent physical receipt and return evidence; expose total received, shipped, good, damaged, source acknowledgement pending, unresolved and not-yet-shipped counts; flag integrity mismatches; no stock or money writes. Test actual one-loan two-batch good/damaged/unresolved scenario.
- Status: FEATURE PREPARATION, NOT A VERIFIED BUG FIX. Existing production loan release remains OFF.

## BUG-20261019-010 — Literal escaped newlines comment out production inventory controls
- Affected: Phase 12 purchased evidence upload/posting and opening-stock release flags.
- Discovery: literal backslash-n bytes inside PHP `//` comment lines in `backend/config/inventory_controls.php` made subsequent configuration entries part of the comment until a real newline. Tests calling `config()->set(...,true)` masked the absence of parsed keys.
- Impact: HIGH availability/correctness for operator-approved future switches; no evidence that production posting was activated. Existing production controls remain OFF.
- Repair: replace escaped markers with real line breaks; regression verifies all fourteen approved keys are parsed booleans in both PHP array and Laravel configuration.
- Status: FIX PUBLISHED — CI PENDING. Require all six hosted jobs green on exact SHA before VERIFIED.

## BUG-20261020-011 — Hall book-report Livewire page double namespace slash parse error
- CI detection: source `c3fc20e9ad4590365a01edefea43497f0fbfa04d`, run [39367189812](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39367189812) failed `licensing-postgres`, `laravel-postgres` and `authenticated-panel-e2e` during Composer package discovery.
- Root cause: newly added `reloadPositionReport()` was authored with two literal namespace separator backslashes in a PHP class reference, producing `ParseError: unexpected token backslash` on line 712. Dependency downloads succeeded; the displayed Composer step name was incidental.
- Impact: HIGH availability on this unreleased branch. No evidence of production activation, stock movement or financial posting. All release flags remain OFF.
- Repair: single valid fully qualified PHP class namespace separator. Existing targeted Livewire report regression and full exact-SHA six-job CI must run; original failed run remains a diagnostic record.
- Status: REPAIR PUBLISHED, CI PENDING.

## QA-20261020-CLEAN-RECEIPT-READ-AUDIT — Private numbered receipt download lacked read audit
- Phase 12 clean internal loan closeout already verifies actor source-hall authorization and pinned immutable PDF SHA256. But the owner/administrator's private Livewire download action did not record the successful document read or prohibit caching explicitly.
- Prepared repair: audited `hall_inventory.clean_loan_receipt_downloaded` event only after the private PDF integrity verifier succeeds, `private, no-store` and `nosniff` download headers; real Filament/Livewire download test checks authorized file response and exactly one scoped audit entry. Do not expose tenant data, disable authentication, or write financial/stock records.
- Status: PREPARED / CI PENDING. Publish only after verified previous source and confirm all six hosted jobs on final exact SHA.

## Verified remediation closeout — configuration and report PHP syntax
- BUG-20261019-010 corrected parseable default-OFF inventory release controls at `b7234c27`; [CI 39366602667](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39366602667) passed all six.
- BUG-20261020-011 corrected Livewire class namespace syntax at `ab38325a`; [CI 39367373687](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39367373687) passed all six. The original Composer-step failure was source syntax, not external dependency network failure.
- Inventory report CSV and complete journal-chain verification PASSED six-job [CI 39368066878](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39368066878) at exact `30878317`; production switches OFF.


## BUG-20261020-012 — Variance acknowledgment UI lacks durable reviewed state
- First detected: source `e4fbf0d6824468fc7673a779d6a642dceb184c89`, [CI 39371679196](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39371679196) laravel-postgres failed at HallInventoryStocktakeVariancePreviewTest.php:167. Five other jobs passed.
- Issue: the page's transient success label was absent from rendered Livewire output after review. A read-only persisted status indicator is needed so review survives reopening and duplicate form actions are hidden.
- Recovery: join immutable acknowledgment scoped by company/hall/stocktake into variance preview; reflect durable reviewed status and update page upon review; require PostgreSQL and Livewire checks for persisted visibility, no second review form, and unchanged inventory/finance.
- Status: FIX PUBLISHED — CI PENDING. Never mark feature verified until exact six-job green.


## BUG-20261020-013 — Expected immutable update aborts entire PostgreSQL test fixture
- Source `f12ba9a766dcbe5d828d18f9b1f623d2b7e74171`, hosted CI 39377437941: laravel-postgres failed `HallInventoryVarianceReclassificationTest.php:103` with SQLSTATE 25P02 after deliberately rejected immutable UPDATE. Five other jobs succeeded.
- Root cause: the new negative test caught the expected QueryException without a nested DB transaction/savepoint. PostgreSQL left the surrounding test transaction aborted, so a subsequent safe audit SELECT failed.
- Repair: wrap ONLY the deliberate invalid UPDATE in DB::transaction so Laravel rolls back its savepoint, retaining the trigger, full assertion and subsequent finance/stock checks. No service, migration or production flag weakening.
- Status: REPAIR PUBLISHED, NEW EXACT-SHA ALL SIX CI PENDING.


## INV-UX-20261020-014 — Backend internal handover and clean PDF closeout unavailable from operator page
- Problem observed at `fe251f107d1486c711d3250fc426464170e0b99c`: internal source dispatch service writes audited physical movements and clean closeout service stores numbered, verified PDFs, but neither operator action was reachable in the HallInventory Blade page. Destination receiving and partial return controls were already implemented; source workflow remained inaccessible without lower-level service invocation.
- Candidate fix (PREPARED, CI NOT VERIFIED): add source-owner/admin-only handover action with 5 MB physical evidence and revision-locked service call; show clean final approval only after independently acknowledged full-good returns; provide private owner-only PDF download, preserving original service authorization and tamper checks. Existing clean closure full PostgreSQL test executes Livewire action; new multi-line source dispatch Livewire test verifies roles, stock and financial isolation.
- Release switches stay OFF. Close this issue only after exact-SHA six-job CI.

- BUG-20261020-013 RESOLVED / VERIFIED: exact commit `fe251f107d1486c711d3250fc426464170e0b99c`, CI run `39378225973`, six required jobs SUCCESS. The immutable trigger remains enforced; only the test fixture savepoint was corrected.


## BUG-20261020-016 — PG RefreshDatabase replay duplicate variance guard function
- Failing CI `39379060119` @ `8d12a19b`, PostgreSQL SQLSTATE[42723], 611 failures caused by migration trying to CREATE already-present `venuedesk_guard_variance_reclassification` across test database rebuilds. Use CREATE OR REPLACE to keep the same guard enforced while allowing replay. No weakening foreign keys or dropping guard. FIX PUBLISHED, CI PENDING.


## BUG-20261020-017 — Two incorrect negative regression assertions, legitimate stronger denials
- CI 39425980820 @ 7d55c6d0, 5/6 PASS, laravel-postgres 627 pass/2 fail/3 risky. Failure 1: revoked user gets Filament route-level 404 not Livewire action 403. Failure 2: immutable forged variance provenance is rejected by SQL BEFORE INSERT guard P0001, not later FK name. Correct tests to assert route NotFound and recognized SQL guard-or-FK rejection respectively, retaining all negative security checks. Product code unchanged; six-job CI pending.


## INV-EXT-20261020-015 — External loan drafts previously lacked physical receipt and approved borrowed-book arrival
- Before candidate: external_in could be drafted with encrypted partner identity, but not physically counted or book posted by independent actors. Status misleadingly always remained DRAFT.
- Candidate repair: default-OFF real witness receipt with bounded signed evidence/hash, independent owner acceptance, exact per-item borrowed_usable CAS movement with immutable SQL parent binding/audit; distinct actor checks and Livewire/PG tests. Never increases owned inventory or silently settles money. Await exact six-job CI verification. External-out and external returns remain open.


## VERIFIED closeout — Phase 12 CI migration replay and negative assertions
- BUG-20261020-016 and BUG-20261020-017 verified resolved at exact `80102c503cc92fba618a08d12370bc151d0398cf`, CI 39427134021 all six jobs SUCCESS. CREATE OR REPLACE keeps provenance function strict and migration replayable. Negative tests preserve route-level revoked staff denial and SQL cross-count provenance rejection. Owner source-dispatch and clean PDF UI verified; external borrowed intake still CI pending.


## BUG-20261020-018 — External receipt SQL trigger delimiter malformed
- CI 39428306646 @ b38cc3e8 blocked migration in laravel-postgres and authenticated-panel-e2e (SQLSTATE 42601). Cause: function body delimiter singular `$` instead of `$$`. Repair both delimiter sites without dropping or weakening guard. All six jobs must pass on new exact SHA. Production release OFF.

- Follow-up BUG-20261020-018: prior purported fix was docs-only because JS replacement-text `$$` collapses to `$`. Corrected using replacement callback, verified old/new blob SHA mismatch and direct SQL source bytes; six-job CI still required.


## BUG-20261020-019 — External borrowed returns were unavailable after independently accepted intake
- Confirmed missing Phase 12 functional vertical: outside-sourced borrowed inventory could enter physical custody but never leave by auditable partial shipment/independent partner acknowledgement; outstanding assets stayed as perpetual borrowed_usable.
- Candidate: feature-gated witnessed, immutable per-loan partial dispatch, linked send_borrowed_back movements, physical signed partner receipt with independent approval and exact counts, confirm_borrowed_return, cross-hall and same-actor negative tests, per-batch audit and no finance/owned posting. Any shortage remains transit and requires separate resolution. Requires new exact six-job CI SUCCESS; production return gate remains OFF.


## BUG-20261020-020 — Externally borrowed items falsely remain labeled pending after full physical return
- Existing immutable intake review showed the same return-pending status forever, even after physically witnessed and independently confirmed partial partner batches; overdue warning also stayed on full physical reconciliation. Prepared follow-on per-item aggregate plus clear not-closed state and Livewire tests. Gate remains OFF; requires exact hosted CI.


## BUG-20261020-021 — External borrowed loan lacked immutable final clean-return closure
- Existing independently confirmed physical return did not authorize a final closeout or legally independent signed receipt. Candidate adds owner-only verified exact-return finalization, private number/hash-linked immutable EX-series PDF and independent download, blocks missing/tampered evidence or reopened/duplicate closeout. Explicitly no money postings or case settlement. Still needs own exact hosted green CI; release switch OFF.


## VERIFIED 2026-10-20: BUG-019/020 external borrowed return and per-loan custody display
- External partial partner dispatch and independent count CI 39448663572 at ab75cd0f: all six SUCCESS, preserving five successes from earlier failed selector-only CI and repairing the two-hall owner UI regression. Per-loan item aggregate and accurate outstanding/in-transit/confirmed states CI 39450008056 at 00f7efbc: all six SUCCESS. Closure candidate still separate/CI PENDING. Default production controls all OFF.


## INV-EXT-OUT-20261020-022 — Outside lending draft had no physical dispatch workflow
- Prior behavior: external_out draft only; no real signed dispatch and owned book movement despite inventory physically leaving hall. Adds default-OFF independently witnessed multi-item counterparty handover, immutable exact draft provenance constraints and separate owner/admin signed-proof approval. Two-actor self-dealing and short count block stock; financial posting never occurs. Await six-job exact-SHA hosted CI; release controls OFF. Partner returns, incidents, settlement and non-clean closure remain unfinished.


## INV-EXT-OUT-20261020-023 — Outside partner return and damaged condition workflow missing
- After verified external_out dispatch CI 39455907097, hall-owned assets had no complete signed partial partner return or independent owner condition-review workflow. New default-OFF implementation records witnessed multiple batch custody receipts and separately verified good/damaged/unresolved conditions, maintaining per-loan receipt ceiling and immutable SQL link to original loan/dispatch. Unresolved stays owned_on_loan and is NOT monetized; damaged remains hall-owned. CI pending; all six exact jobs required. Financial obligations/disputes/nonclean closure and commercial operator acceptance remain pending.


## INV-EXT-OUT-20261020-024 — Damage/missing/dispute reporting previously internal-only
- Previous source refused all external_out loan incidents despite physical outside partner returns. Candidate extends the same immutable evidence/decision contract with strict original lending-hall approved external dispatch and per-item witnessed condition/outstanding caps; source-owner reporter and independent different reviewer. Does not assess money, move stock, settle debt or finalize loans. Exact six CI jobs and pilot required. Release flags OFF.


## INV-EXT-OUT-20261020-025 — Outside owned loan falsely remained physically outstanding after clean returns
- Candidate reconciles per-loan original external_out physical owned custody from immutable handover, partial physical receipts and independently reviewed good/damaged/unresolved lines instead of global hall inventory totals. Avoids false overdue when every unit is returned good; adds owner-only immutable EOL number/hash-pinned PDF closeout gated OFF. Rejects missing or altered physical partner documents, open incidents and outstanding custody. NO financial settlement, six-job CI pending.


## INV-FIN-20261020-026 — Provisional loss claim service lacked operator forms
- At verified baseline `9130e6ed` the SQL/service claim assessment was available but Hall Inventory owners could not enter or independently assess a claim through the operator page.
- Implemented candidate: hall-scoped owner/admin claim selection from independently substantiated incidents, explicit minor-unit currency entry, bounded amounts, persisted pending and reviewed history and different-actor review. Server-side selection is revalidated and service locks continue to prevent overlapping financial claims. No receivable, journal, payment, partner admission or non-clean closure.
- Status: IMPLEMENTED, HOSTED CI PENDING on successor SHA. All production flags remain OFF.


## INV-FIN-20261020-027 — No independent private counterparty documentary position or review
- Follow-on candidate to provisional owner claim desk: append-only outside-partner correspondence with exact company/hall/loan/claim/currency SQL binding, private 5 MB PDF/photo SHA-256 evidence, owner-recorded claimed acceptance/dispute/counteroffer positions, independent owner integrity review and hash-checked no-store download. Duplicate replay and self-review blocked. Never infer signer identity, legal debt, settlement, payment or finance journal from recorded correspondence.
- Status: PREPARED DETACHED; six-job hosted CI required after predecessor verification. All production controls remain OFF. Actual obligation/collection/nonclean closure pending.

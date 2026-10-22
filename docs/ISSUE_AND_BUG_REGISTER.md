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
- Status: VERIFIED at 19dfc07d, hosted CI 39486099346 six jobs SUCCESS. All production flags remain OFF.


## INV-FIN-20261020-027 — No independent private counterparty documentary position or review
- Follow-on candidate to provisional owner claim desk: append-only outside-partner correspondence with exact company/hall/loan/claim/currency SQL binding, private 5 MB PDF/photo SHA-256 evidence, owner-recorded claimed acceptance/dispute/counteroffer positions, independent owner integrity review and hash-checked no-store download. Duplicate replay and self-review blocked. Never infer signer identity, legal debt, settlement, payment or finance journal from recorded correspondence.
- Status: PUBLISHED CI PENDING after exact predecessor verification; six-job hosted CI required on successor. All production controls remain OFF. Actual obligation/collection/nonclean closure pending.


## BUG-20261020-029 — Partner document private Cache-Control header order test mismatch
- Hosted CI 39487452294 @ f92372fb: laravel-postgres 634 passed, 1 failed, 3 risky; five other required jobs success. At ExternalOutIncidentTest.php:281 the secure Symfony response returned `no-store, private`, not the asserted `private, no-store`. Both required directives were present. This is a brittle assertion, not a privacy regression.
- Repair candidate independently asserts `private` and `no-store` cache directives regardless of order, and leaves production code, headers, access checks and all negative security tests unchanged. Exact-SHA six-job CI pending; production OFF.


## INV-FIN-20261020-028 — Missing independently authorized documented loss obligation
- Candidate after partner documentary response: separate manual partner-identity check reference and original approved claim/currency/amount binding; only independently reviewed acceptance or counteroffer may be proposed. Separate owner authorization rejects self approval, conflicting replay and simultaneous authorizations by unique PostgreSQL partial index. Readable owner proposal/decision queue and real regression flow. A merely disputed document cannot become a debt. No accounting receivable, collections, reversal, write-off, loan closure, or production activation yet.
- Status: PREPARED DETACHED pending exact six-job CI on preceding documentary source and new candidate.

- Verification closeout for header order repair: `5df565fdb681a3fda0fd432091e4d6fd29ee5570`, GitHub Actions 39488870650, six required jobs SUCCESS. Partner document evidence and independent integrity review now VERIFIED; later obligation approval and collection work remain unverified.


## INV-FIN-20261020-030 — Evidence-only claims without verified cash recovery, partial balances or corrections
- Prepared candidate implements default-OFF owner-recorded private bank/cash evidence (never counted as received before independent owner verification), caps combined pending/posted amounts under company transaction lock and exact obligation, blocks bank reference replay across existing venue-invoice deposit subledger, posts two-line balanced cash-basis inventory recovery journal only with finance release and independently verified proof, permits another owner to post immutable linked reversal, presents full evidence/verification/reversal status and remaining balance in Hall Inventory Livewire UI. Does NOT create accrual receivable, a customer invoice, liability waiver, physical writeoff or non-clean closure.
- Status: DETACHED candidate, not tested/verified on hosted CI. Production release OFF. New full six-job exact SHA CI required after predecessor success.

- Exact CI closeout: `f07f144914f5ec00a5016d242655747264bad6da` / run 39490232685 SUCCESS all six jobs for independently authorized loss obligations. Payment recovery release still requires its own exact-SHA CI and all financial production switches remain OFF.


## BUG-20261020-031 — Provisional claim Livewire regression assumed obsolete financial journal text
- Exact failure: `4ad6ea837423ee09dcbec2368ec10dc9e85f1b94`, hosted CI `39491859440`: `laravel-postgres` FAILED in `HallInventoryExternalOutIncidentTest` at an obsolete `Financial journal posted: NO` UI assertion; the other five jobs passed. The physical incident, claim service and recovery page had not silently posted a journal by creating a claim.
- Root cause: after the independent partial-recovery/journal interface was added, provisional claims were correctly described as a distinct nonposting stage with a separate later recovery ledger. The test still checked removed static wording, which would falsely imply the entire loan never had a verified recovery journal.
- Repair: assert the exact truthful provisional claim sentence, `Provisional claim alone does not post money`, while retaining direct database `journalPosted === false` and `paidMinor === 0` tests for this uncollected claim, plus separate independently verified recovery/journal regressions. Never restore a loan-wide false negative. No finance or inventory release flags changed.
- Status: REPAIR PUBLISHED, hosted six-job CI for new exact SHA PENDING. Do not mark VERIFIED until all six succeed.


## BUG-20261020-032 — Payment self-approval test ran before separate finance journal release
- Source `2130f21fa4aebe1f5cd09f08b52d914e3f858d3e`, hosted CI `39503830518`: five jobs passed; `laravel-postgres` FAILED because the existing real recovery workflow test attempted a confirmed payment while `finance_controls.posting_enabled=false`. The production `HallInventoryFinancialRecoveryService::gate(true)` correctly threw ConflictHttpException before self-approval authorization, whereas the fixture expected AuthorizationException.
- Fix: test explicitly asserts finance-release-OFF rejects any posting, then enables ONLY the synthetic test config and asserts the original payment reporter is denied self-approval by authorization; the separate owner reviewer still verifies real evidence, journal balance, reversal, tenant privacy and idempotence. No production service, balance, permission or default finance/inventory gate weakened.
- Status: REPAIR PUBLISHED, new exact-SHA six-job hosted CI pending.


## INV-EXT-OUT-20261020-033 — Non-clean physical summary must match immutable posted movements
- Detection: verified Phase 12 read-only reconciliation tracked witnessed signed return/review quantities and ensured non-null movement IDs for good/damaged, but did not verify that those IDs were exact original company/hall/item/batch, event kind, quantity, approving actor and return reason. A privileged inconsistent movement linkage could misstate returned owned book custody in a read-only operator report; there is no evidence of exploitation. Feature remained default OFF.
- Hardened service refuses zero-quantity movements and confirms each positive independently reviewed GOOD or DAMAGED physical condition against its exact immutable posted stock event. Preserves original return evidence SHA checks, owner/company/hall scope, negative manager/hall/tamper regressions and no financial or closure postings.
- Status: IMPLEMENTED CI PENDING; validate exact new six-job SHA before VERIFIED. Overdue calculation awaits venue IANA timezone, no server-time guess.


## INV-EXT-OUT-20261020-034 — Non-clean damaged and missing stock lacked independent authorized write-off
- Detached Phase 12 batch adds immutable original-hall/loan/item/incident-scoped proposals, independent owner authorization excluding physical reporter and independent incident reviewer, an explicit approved stock movement for damaged or truly unreturned missing owned units, hall position CAS revision locking and no financial journal/waiver/closure side effects. Includes Livewire owner action and dual physical test cases. Rejects self-approval, conflicting final decisions, over-cap units and missing evidence. Gate VENUE_INVENTORY_NONCLEAN_WRITEOFFS_ENABLED defaults OFF.
- Status: PREPARED DETACHED; exact-SHA all-six CI pending after publication and operator certification. Current verified syntax repair 14d3970d / run 39515777574 passed all six jobs. Separate financial waiver, final nonclean PDF, overdue dispute closeout and pilot release remain open.


## INV-FIN-20261020-035 — No independent partial contractual waiver separate from cash recovery
- Detached Phase 12 candidate adds signed original authorized monetary obligation scoped waiver proposals, current owner finance permission and distinct decision actor, company lock and limit against confirmed recoveries and other pending waivers, append-only immutable decisions and auditable balance with separate paidMinor/waivedMinor/pendingMinor fields. Contractual debt forgiveness does not create a cash journal, reverse actual money, dispose stock or close a loan. Livewire owner controls and PostgreSQL negative/replay regressions. All production switches OFF.
- Status: PREPARED DETACHED pending six-green parent 39517326833 and its own six-job hosted CI.


## INV-NONCLEAN-20261020-031 — Final closeout readiness not surfaced in owner review
- New owner-only read-only blocker taxonomy surfaces pending incident and return-condition review, outstanding custody, undisposed owned damage, pending independent financial verification, remaining authorized obligation, or disabled financial reconciliation. Production flags remain OFF, no financial receipt or final closure is issued by these diagnostics.
- Status: IMPLEMENTED, exact hosted all-six CI PENDING. Source regression added to HallInventoryExternalOutIncidentTest.php and owner Blade reconciliation display. Next: independent immutable nonclean final closure transaction and numbered PDF + authenticated acceptance; no final closure authorized.


## INV-NONCLEAN-20261020-032 — Final non-clean closure and private numbered certificate
- Implemented candidate migration and independently gated NCL final closure service, scoped owner-only operator actions, zero remaining custody/financial balance checks, separate writeoff/waiver approvals, serial numbering under company transaction lock, immutable SHA256 private PDF and audit, plus duplicate/replay/tamper regressions. Stock and finance proposal/review services now refuse post-certificate mutations. No environment flags enabled.
- CI status: PENDING, not ACCEPTED or production ready. Remaining: verify exact six-job hosted CI, resolve failures, test actual concurrency using two PostgreSQL connections, independent operator pilot and any unreconciled Phase 12 acceptance items. Do not mark Phase 12 complete.


## Phase 12 NCL closeout CI recovery and independent certificate signing (2026-10-20 ET)
- Previous exact HEAD 0640d2c39db99b84cc1b93fd427dd7ad4377a217 failed GitHub Actions 39533213061 only in laravel-postgres: foreign-hall certificate probe returned ConflictHttpException rather than the expected authorization denial. Retain the original PostgreSQL negative test. Commit fb6fc5bccd7aafb30c5df3c5ae89fad92ce693fd now checks original loan company/hall/direction before numbered PDF lookup, preserving cross-hall privacy.
- Commit ef6c75df5145f6ee61604c48bf4893369d9860be additionally prohibits independent final NCL signing by the actor who approved a contractual financial waiver for the loan. Commit 7a93437030009d2f992bde162e152bf650331ed6 adds regression refusing previous disposition/waiver approver as final signer. Neither change relaxes physical, finance, role, evidence or production gates. Latest exact-SHA six-job CI 39534800447 requires full completion and assessment; no staged pilot/release approval yet.
- Remaining acceptance includes real two-connection PostgreSQL numbered issuance races, full abnormal inventory/financial lifecycle across roles and all approved Phase 12 feature reconciliation; do not declare production ready or advance Phase 13.


## Phase 12 NCL verification and SQL source-hardening checkpoint (2026-10-20 ET)
- 5f965410dde5eb3fe0dd1321078c3d69944aaf6d / GitHub Actions 39535178995 SUCCESS all six required jobs for cross-hall numbered PDF authorization, segregated waiver signoff, replay/immutable NCL closure and post-closure fixture correction.
- 103f5f3737d9bd777e3e1c61e2b241c4ee5ff76c failed hosted CI because converting the entire long-lived external-owned lifecycle test to DatabaseMigrations allowed immutable test records to leak into later tests (51 unrelated fixture failures). Recovery separated PostgreSQL company-lock concurrency into its own minimal DatabaseMigrations test, preserving original RefreshDatabase lifecycle. Corrected source b15ca51e0da9e1e9abc11e51d6cc4074a1efc77f / CI 39535802293 SUCCESS all six jobs.
- Additive SQL origin guard candidate ede17499fb01b72e531e253c2939523e92621b03 enforces company+loan+original lending hall compound FK and external_out-only NCL direction trigger; regression rejects forged same-company foreign-hall certificate. Successor 71a10d7123cd2f5055a11c1f699bd82817fb0e63 also rejects an internal loan's forged NCL certificate in PostgreSQL. Latest CI 39536263888 remains PENDING full exact six-job verification; do not call this additive SQL guard VERIFIED before passing.
- All inventory/finance production release flags remain OFF. Outstanding Phase 12 certification: exact final CI, operator/staging pilot, full physical/financial reconciliations across internal/external-in/out including abnormal loans, missing old approved feature-register reconciliation and any remaining issue fixes; no Phase 13 promotion.


## BUG-20261021-036 — Final NCL client UUID collision could overwrite existing PDF before unique-row failure
- Root cause: local immutable document path derived from untrusted requested UUID was written before SQL insert; a conflicting source file could be replaced and then deleted on insert failure.
- Repair source: `5afb3fb6`; fail closed when path exists, reuse current company serial lock, verify original bytes unchanged and no extra receipt in authenticated PostgreSQL lifecycle. Hosted CI run 39541773468 requires final six-job confirmation before VERIFIED. No flags enabled.

## BUG-20261021-037 — Private partner correspondence could mutate after NCL and overwrite a prior evidence blob
- Candidate source: `7e5f4b15`; acquire company lock before partner record/review, forbid mutations after final loan certificate, refuse occupied immutable storage keys. PostgreSQL/Livewire lifecycle regression asserts post-final rejection, evidence file preservation, no additional documents or reviews. Detached until predecessor six GREEN; own CI required.

## BUG-20261021-038 — Partner gate disabled suppressed recognized financial obligation balances
- Candidate source: `62461f3f`; reconciliation now reports financial_reconciliation_unavailable when required independently authorized partner/obligation controls are disabled; NCL issuer requires partner response gate. Full lifecycle test prevents issuance under disabled partner evidence verification. Detached until its parent six GREEN; no production release.


## BUG-20261021-039 — Incident reporting had non-atomic final-NCL existence check
- Root cause: original incident posting checked the NCL ledger BEFORE locking the original loan, allowing a window where it could wait behind an issuer then continue on stale evidence. Candidate `ba62dec6` moves the check after source-loan row lock; post-NCL reports remain refused and audited history unchanged. Detached, exact six-job CI still required.

## BUG-20261021-040 — Unreviewed late partner documents were omitted from NCL closure blockers
- Candidate `30617b57` adds company/loan-scoped pending signed-document independent review blocker; Livewire/PostgreSQL full lifecycle demonstrates premature final rejection and proper review before close. Detached, own exact six-job CI pending. This does not authenticate external payment and does not turn inventory release ON.

## INV-NCL-20261021-041 — Cross-loan positive company-wide issuance certification
- `18c694b9e88f7ee4fbe0932f9a120d1cf1bf4daa` [CI 39549530413](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39549530413): six SUCCESS, distinct invalid loan IDs blocked by the same company serial lock across PostgreSQL connections, no fabricated closure.
- `81c5230f327e2128d9916a40398532add055df33` [CI 39550494027](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39550494027): six SUCCESS, separate fully evidenced external-out abnormal loans close sequentially with two unique original-scoped numbered PDF certificates and unchanged inventory/finance journal counts.
- Follow-on: regression for second eligible loan reusing first loan's UUID, original bytes preservation, retry without consuming a serial, explicit full-waiver versus cash segregation. Requires its own exact six-job hosted CI. Actual simultaneous two-connection successful close() race, staging operator UAT, backup/restore and release approval remain OPEN. No Phase 13 or production flags.

## INV-NCL-20261021-043 — Genuine two-process numbered issue serialization verified; authenticated issuer evidence follow-on
- `5324c1bf6e90d62878dfb7f3753a54488cc8b984`, [CI 39551382495](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39551382495): all six SUCCESS for cross-loan certificate UUID storage collision, original byte/hash preservation, financial paid=0 versus waived balances, and no extra movement/journal on retry.
- `49c363e5008c2ee4f76a1c614ed5367e3a710a89`, [CI 39552677040](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39552677040): all six SUCCESS. Two fully reconciled eligible external_out loans persisted through independent authorization; a second real PostgreSQL backend was observed WAITING ON THE COMPANY LOCK while the first finalized. Distinct serial NCL-1/NCL-2, company/hall/loan bindings, immutable private PDF bytes and no cash journal fabrication checked; DatabaseMigrations cleanup prevents immutable fixture leakage. This is code-level concurrency certification, not staging/operator approval.
- Additive follow-on pins the authenticated issuer account and server UTC issue time in NEW immutable original PDF bytes and source row creation timestamp; old certificates continue to return original stored SHA-verified bytes. This follower requires its own exact six-job CI. Phase 12 stays OPEN, production gates OFF. Abnormal INTERNAL and EXTERNAL_IN cases and full feature/register/operator acceptance remain unfinished.

## BUG-20261021-044 — Outside-owned incident decision misclassified as INTERNAL in immutable audit
- Source: HallInventoryLoanIncidentDecisionService::decide was consistently persisting action `hall_inventory.internal_loan_incident_reviewed` for both INTERNAL and EXTERNAL_OUT substantiated owner assessments. This is incorrect historical provenance, especially for outside-owned partner damage or missing incidents. Existing immutable events are not rewritten.
- Fix candidate: retain the original internal audit action for INTERNAL; emit `hall_inventory.external_owned_loan_incident_reviewed` for EXTERNAL_OUT according to trusted server-side locked loan direction, with an end-to-end PostgreSQL regression on actual independently reviewed outside-owned incidents and assertion against misclassification.
- This source successor requires its own all-six exact-SHA hosted CI; no balances, release flags, partner liability or approval duties changed. The wider abnormal INTERNAL and EXTERNAL_IN disposition/financial closure work remains open.


## INV-INTERNAL-20261021-045 — Source-scope abnormal internal custody reconciliation
- Current verified predecessor 3002a268 / run 39554429867 SUCCESS all six.
- New staged implementation: source Owner/Admin read-only exact loan per-item dispatched, good, damaged, unresolved, pending review and unreturned counts. Explicit integrity warning and closure-blocked/no-money semantics; UI on existing incident review screen. Never reclassifies damage as good or posts financial liability or stock. Cross-role and physical conservation regression added. Full independently approved physical disposition, finance/settlement and final abnormal closure still OPEN; production flags OFF.

## BUG-20261021-046 — Abnormal internal owner panel gate and client-state isolation
- Original failures: [39637790365](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39637790365) and [39639269216](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39639269216): owner Livewire view never activated while the integration fixture omitted the separate `catalog_enabled` page access gate. `internalAbnormalCustody.0.dispatched` returned null. An early green run `a8ac773e` [39642197515](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39642197515) deleted the failing UI assertions, so it did NOT verify the visible custody panel. Restoring owner screen assertions at `d2dd660d` exposed the same omitted gate: [39663947357](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39663947357) backend FAILED on `incidentLoanId` null.
- Real repair `7dd71bbe8bfc89be45cdfe142f278fb4e4a49363` [39665310369](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39665310369) SIX SUCCESS: test enables required staging-only inventory catalog page flag, mounts Filament Owner Livewire using original company/hall and actual loan, sees dispatched 12, good 6, damaged 1, closure BLOCKED/no money, and denies borrowing manager. All production gates remain OFF. Existing backend source custody remains independently scoped and audited.
- Separate following regression hardens `internalAbnormalCustody` as `#[Locked]` server-derived state, resets custody when changing hall/company selection, and verifies that no stale original lending hall figures remain after selection change. This successor needs exact six-job CI; do not pre-mark verified. Independent physical disposition, financial settlement and final internal abnormal closure are STILL OPEN.

## INV-INTERNAL-20261021-047 — Source-owned damaged inventory physical retirement
- Verified baseline `0481a43f3c4a1bb2a70b2eeabaa0c4f51616b694` [39667565865](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39667565865): six SUCCESS, 639 passed and 3 existing risky; original lending-hall Owner screen, locked custody snapshot, membership revocation.
- Initial implementation `afa2438c70774e62055f6f13e0ae4cbd8463947d`, [39671406699](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39671406699): FAILED PHP namespace-token parse, historical failure preserved.
- Actual repair `3d4342d99465ffe29fdc3b8e95703a2f923b95c0`, [39671584251](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39671584251): SIX SUCCESS. Real independent company/hall loan incident physical DAMAGED report, different owner independent review, third Owner physical write-off approval; immutable proposed/decided SQL source scope and revision-CAS owned_damaged decrement; actual Owner Livewire proposal and signer decision; no external money or journal. Production OFF.
- Next hardening: server-locked original-loan proposal/decision selectors and negative forged choice regressions; post-clean-internal-closure and disabled-original-custody gate cannot bypass signed write-off history. This successor requires its own exact six-job CI. INTERNAL missing/unreturned custody, financial settlement and numbered abnormal loan closure remain OPEN; EXTERNAL_IN disputed/short partner physical return closure still OPEN. No invented settlement or operator UAT.

## INV-INTERNAL-20261021-048 — Retired and pending physical damage quantities remain independently visible
- Verified source `d7669b4c07fde2eb16f4dae33a1eff4c444d35dd` [CI 39672712150](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39672712150): SIX SUCCESS for original INTERNAL incident/proposal source, forged-ID UI denials, original custody gate and clean-closure safeguards, zero cash posting.
- Successor reads exact same-company original lending-hall loan immutable approved write-off decisions/movement-bound proposals, aggregates approved physical damaged-owned retirement per item, compares to original physically accepted damaged quantity, reports damage still awaiting disposition, and flags overretirement as integrity warning. Livewire source owner sees physical retirement independently of financial status; even fully disposed damage remains closure BLOCKED and moneyPosted=false. Relevant real multi-actor Livewire/PG regression added. Requires its OWN exact six-job CI. Abnormal missing custody, independent obligation/settlement, final internal abnormal certificate and EXTERNAL_IN disputes OPEN.

## INV-INTERNAL-20261021-049 — Verified physical damage disposal balance and exact movement provenance
- New balance display at `969f59bf818a90092288334b14bbb5cd86ff09e3`, [CI 39673758671](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39673758671): five jobs SUCCESS, backend FAILED due test block inserted ahead of third Owner fixture (undefined variable). Historical failed run preserved.
- Actual test-placement repair `f5804884b6836b6f40a11cf2e99d42561a5e00cc`, [39675049673](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39675049673): SIX SUCCESS. Source Owner/Admin dashboard after real independent approved internal damage write-off displays original returned damage 1, separately approved retired 1 and remaining pending disposition 0, while loan closure remains BLOCKED and finance journals 0; original source physical books conserved. Old test assertion placement corrected, no source production regression.
- New backend follow-on checks each immutable approved decision's physical movement exists with matching company/hall/item, event kind, quantity, original proposal reason, decision actor and fingerprint before presenting a retirement total. Rejects inconsistent privileged ledger references instead of silently declaring physical units retired. Pending own six-job exact-SHA CI. No new capability to charge borrower, waive payment, or close abnormal INTERNAL loan. Production flags OFF.

## INV-INTERNAL-20261021-050 — Independently reviewed missing/unreturned custody evidence
- Verified predecessor `aa2fb869838b88dab863f50a5f563239338da441`, run [39676230895](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39676230895): SIX SUCCESS, existing owned damaged retirement, original pending/retired report, movement provenance and negative tampering checks. Historical `969f59bf` run39673758671 FAILED regression placement, real `f5804884` run39675049673 repaired; historical failures stay red.
- New candidate separates original unreturned/in-transit physical discrepancy from substantiated missing and disputed claims. Original lending hall Owner can report evidence using existing immutable incident service; independent different Owner/Admin decision required. Per-original item new read-only authenticated UI shows pending review, substantiated missing, separately substantiated disputes, and physically unsettled units, with overstatement integrity warning. The source stock remains unchanged and no money or final loan closure is inferred. Test uses actual original loan, real new missing evidence and reviewer, negative borrower authorization, no cash. Exact six CI for this successor required.
- Remaining: complete borrower/lender independently verified physical resolution, financial obligations/settlement and final abnormal numbered certificate; external borrower evidence cannot be inferred from source claim. Abnormal EXTERNAL_IN disputes likewise OPEN. Production inventory and finance OFF.

## INV-INTERNAL-20261021-051 — Signed custody versus mapped destination-item aggregate
- Initial source missing incident/review `38fe7b5b` [39678171613](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39678171613) backend FAILED because expanded fixture has two evidence reports; actual repaired `ed5e18b28bdb06461869c8488e881f25a5e64898` [39679437961](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39679437961) SIX SUCCESS, report-ID-specific review and no money/stock movement.
- Source-to-destination mapped borrower book and pending more-evidence review `8068931925d79bbfefae9e2c8cc5adc28bbafb44` [39680732971](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39680732971) SIX SUCCESS. Read-only posted destination item 4 borrowed usable/1 transit/0 damaged; this may include other loans using the same destination catalog item and MUST NOT be treated as borrower admission or exact per-loan settled balance.
- Additive successor corrects UI label/qualifier explicitly to aggregate destination item buckets, adds actual rendered Owner screen assertion. Its own six-job CI must pass before marking verified. Real signed borrowing-hall incident corroboration, independent source review, stock settlement, finance and original abnormal numbered closure remain OPEN. EXTERNAL_IN disputed physical returns OPEN; production flags OFF.

## INV-INTERNAL-20261021-052 — Borrower-signed original-loan missing/disputed statement
- Predecessor `495903b315f7514a5ab048f0be5928b57d703a19`, [39682074643](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39682074643): SIX SUCCESS, original source testimony, independent review and aggregate destination book honestly labelled; no signed borrower admission inferred.
- New separately default-OFF internally signed borrower evidence service, immutable original-loan/receipt/item FK-scoped statement with private PDF/photo SHA256 size/path and current receiving-hall authorization. Original source Owner/Admin independently classifies corroborated / disputed / requires_more_evidence with immutable one-decision restriction. Deterministic original-loan lock, conflicting replay denied, foreign-hall rejection, immutable audit, no position/journal/loan completion posting. Actual Filament borrower and source controls and PostgreSQL/Livewire fixture regression added. This successor requires its OWN SIX exact-SHA CI, never mark verified while pending.
- Next: strengthen borrower statement receipt/return provenance, encrypted or source-proof access, concurrent/stale/revoked source role and tampering tests; then certified joint physical resolution and separately approved financial claims/payment/closure only with independently verified evidence. External partner acceptance and operator UAT remain blocked; Phase12 OPEN and production inventory/finance OFF.

## INV-INTERNAL-20261021-052 — Original signed borrower proof history integrity and lock ordering
- Exact verified predecessor `d0b1af7b230d2a448c5738dc7680aa95412706d4` [39695935832](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39695935832) SIX SUCCESS. Default-off original INTERNAL borrowing manager per-loan signed missing/disputed PDF/photo statement; immutable original receipt/line FK, SHA256, private file, independent current lender owner decision, hall isolation, fingerprint replay, owner/manager UI and no stock or cash effects.
- New source `8970278bd59da30a08a28bbcfd82971f628e358a` [39698470691](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39698470691) requires exact CI: original private proof bytes/size/hash now checked on authorized HISTORY reads, not merely during decision; borrower submit and owner review lock loan before statement to avoid opposing lock order.
- Additive PostgreSQL/Livewire test intentionally corrupts original private proof and confirms history fails closed, then restores original bytes and confirms read succeeds, with existing zero-cash/physical-conservation checks. Requires its own exact six CI before acceptance.
- No witnessed resolved missing return, independent finance obligation, bank settlement or abnormal loan closure has been proven. EXTERNAL_IN partner dispute workflow and operator/staging restore/security release remain OPEN. Production flags OFF.

## INV-INTERNAL-20261022-053 — Joint original-loan discrepancy decision (implementation checkpoint)
- Previous fcdcc5d [39698916554] SIX SUCCESS, borrower original signed source testimony and independent source review. New candidate: matching corroborated borrower statement and substantiated lender incident for exact loan/item/kind/qty; additional signed source proof with original hash and scoped access; independently reviewed, append-only, quantity-reserved physical finding with positive no-finance/no-custody checks and Livewire forms. All production controls default OFF.
- VERIFY six hosted jobs at exact new SHA. OPEN: retracted/amended findings, return-after-confirmation reconciliation, real separately authorized finance and abnormal numbered closure, EXTERNAL_IN partner dispute, real operator/concurrency/security/restore acceptance. Do not treat signed facts as return or retire unreturned stock.

## INV-INTERNAL-20261022-054 — Original signed borrower proof read access and replay storage length
- Joint original-loan finding commit 244d4eaf [39703264721](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39703264721) ALL SIX SUCCESS. Original borrower signed history/review verifies hash and length but operator previously had no private document download and existing signed upload replay did not separately compare actual stored byte length to immutable recorded evidence_size.
- New candidate adds hall-authorized borrower and source immutable proof fetch with exact private path and bytes/hash/length checks, download audit and no-store Livewire header, plus expected missing/tampered/replayed negative assertions. CI PENDING. No source stock/money/closure touched; flags OFF.

## QA-20261022-055 — Corrupted-proof regression fixture used wrong authenticated actor
- New 5e8e799d [CI 39704250262](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39704250262): FIVE passed, laravel-postgres FAILED at original source history permission; fixture had switched current Auth to destination manager to test tampered retry then forgot to restore Auth to source owner. Production access denied correctly. No evidence of stock/money or credential issue.
- Repair restores owner Auth BEFORE authorized original source history, retains tampered proof/replay, two-hall denial, audit and conservation assertions. Await exact new six-job run. Do not relabel original red run green. Release OFF.

## INV-INTERNAL-20261022-056 — Historical confirmed finding may become stale after later physical return
- Current historical finding displays immutable corroborated quantity but does not recompute actual subsequent original-loan independently accepted good/damaged return batches. Could mislead reviewer into treating an old signed three-unit discrepancy as presently unreturned after ten good units actually return. Need exact per-loan live read model and confirmation-time original custody guard, independent actor and physically conserved quantitative regression. New prepared source computes live data from original dispatch and accepted batches without money/stock changes; requires six-job exact SHA. External_in and abnormal finance/closure remain OPEN.

## INV-INTERNAL-20261022-057 — Signed historical finding correction and contemporaneous reservation safety
- Exact source 05061d0e run39705468588 all SIX SUCCESS. Historical signed finding remains immutable, but future later independently accepted custody returns can make its original open-quantity assertion stale. A documented error requires a separately signed correction with different source approver and audit, not UPDATE of the old decision, forgiveness or inventing a good return.
- Candidate adds third independent source owner correction, original loan/item scope composite FKs, immutable signed PDF/photo SHA256 length, private authorized download, review and UI with no book/journal effects, excluded superseded findings from new quantity reservations. Separate true PostgreSQL original-loan lock contention test. Current CI PENDING; financial and external_in still OPEN, production OFF.

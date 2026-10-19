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

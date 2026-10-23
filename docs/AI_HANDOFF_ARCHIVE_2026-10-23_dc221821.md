# Zargul Silver Marquee — Phase 12 technical certification (2026-10-23 UTC)

**Allowed:** yasirskhan/Zargul-Silver-Marquee branch chatgpt/phase0-foundation only, SAME normal Chat, ONE writer, expected_sha fast-forward force=false. Never use Work, create branches, edit main/property-platform or activate production.

## Start on every continuation
1. Read full root AGENTS.md, this concise handoff, docs/SESSION_CONTROL.md, docs/PHASE12_FINAL_ACCEPTANCE_REPORT.md, docs/PHASE12_ACCEPTANCE_STATUS.md, 59 Phase 12 records in docs/FEATURE_REGISTER.csv, docs/INVENTORY_IMPLEMENTATION_PLAN.md, and relevant actual service/tests. Recheck LIVE branch HEAD, branch-filtered GitHub Actions runs and all six named job conclusions for **that exact SHA**; documentation can lag.
2. Required jobs: prototype-checks, central-postgres, licensing-postgres, laravel-postgres, editor-browser-e2e, authenticated-panel-e2e. Pending/skipped/fail is not verified; read failing log and fix underlying failure first without skipping tests, weakening auth/stock/finance, or deleting immutable history.
3. Respect one authorized writer. If branch moved, read the new diff and stop stale publication; do not recover already included source as missing. No second writer or force push.

## Verified baseline and continuing work
- **Last complete all-green before new reversal-race additions:** HEAD 32b598b21369845b167bfb0bb2010dd3da248149, GitHub Actions 39868467542, SIX SUCCESS. Covers real two-process distinct-original-loan ENCL sequence/stock conservation, IAC/NCL concurrency tests and repair series. Historical failed CI remained failed but source was repaired by the later green exact SHA.
- **First new original-loan reverse-versus-ENCL race:** commit f4e3024e7a91ae7abd76c9eb2ccd2012ac3655fd published safely; GitHub Actions 39893330180 was IN PROGRESS when drafted. Tests closing first while a separately connected PostgreSQL backend tries partner settlement reversal, checks lock contention, issued certificate integrity, one transit write-off and no post-final reversal.
- **Second complementary test included in the next source candidate:** independent allowed reversal acquires company lock first while another PostgreSQL process attempts ENCL. Requires actual all-six hosted CI to claim PASS; no fabricated human confirmation.
- **Code/source matrix:** docs/PHASE12_FINAL_ACCEPTANCE_REPORT.md maps all 59 IDs; 40 explicitly labeled HOSTED CODE TESTS PASSED, 17 CODE PRESENT / LEGACY LABEL RECONCILED, 2 SCOPE/PILOT UNACCEPTED. These are NOT equivalent to 59 individually executed real UAT scenarios. One migration evidence filename was corrected. See docs/PHASE12_ACCEPTANCE_STATUS.md.
- **Operator acceptance runbook:** docs/PHASE12_OPERATOR_ACCEPTANCE_CHECKLIST.md covers signed owner/manager/source/partner UAT, bank original proof, staging fault injection, document+PostgreSQL restore and security signoff. It is a checklist only, none performed by repository CI.

## Current technical/acceptance status
- Source flows present: INTERNAL clean/IAC, EXTERNAL_OUT EOL/NCL, EXTERNAL_IN clean/ENCL, partial returns, independent findings/disputes/corrections, write-offs, waiver/recoveries, payment proof/reversal, private PDF integrity and audit. ADR-0009 Option A internal settlement: NO company-level cash journal.
- Selected real two-process PostgreSQL lock and serial/conservation tests pass on the baseline. Broader cross-workflow synchronized matrix remains PARTIALLY VERIFIED, not every combined borrowing/partial return/finding/write-off/reversal/serial interleaving.
- Simulated storage integrity, partial upload, occupied-key, DB-trigger rollback/orphan cleanup tests are in hosted suite. Real staging network/object store outage, interrupted backup and **actual coordinated PostgreSQL/document restore** are not possible using this GitHub-only integration and are **BLOCKED EXTERNAL**.
- Actual owner/admin plus two independent hall managers and external partner signed physical cases, original bank verification, independent TLS/security/monitoring, real role-revocation operator/mobile UAT and **written owner production-release decision** are BLOCKED EXTERNAL. No invented signatures, payments, operational results or backup logs.
- Inventory and finance release toggles remain OFF. **Phase 12 OPEN / NOT ACCEPTED. Phase 13 not promoted.**

## Next exact actions
1. Verify current latest branch SHA and all six CI jobs. Fix any genuine failing backend/browser job and rerun exact new SHA before expanding.
2. Review latest synchronized reversible-finance/ENCL race tests and remaining internal/EXTERNAL_OUT interleaving coverage, add bounded meaningful tests without repeating proven cases.
3. Reconcile final 59-ID matrix, preserve historical failed-run notes, and write final exact SHA, run/job results and defects into report/status/handoff. Current handoff is intentionally a snapshot, not proof of its own later documentation CI.
4. Keep operator-external gates BLOCKED until Owner, two managers, partner, infra and security personnel execute and sign the runbook. Production OFF. No Phase 13 until true Phase 12 acceptance.

**Previous complete handoff preserved verbatim:** docs/AI_HANDOFF_ARCHIVE_2026-10-23_32b598b2.md. Retain earlier archives unchanged.

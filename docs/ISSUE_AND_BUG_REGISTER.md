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
- Status: FIX IN PROGRESS — detached fix `b0b43858e701f7419c5e4b60bc828d06bcc45170` is PREPARED, NOT PUBLISHED or CI-verified.
- Corrective source / regression: `backend/app/Services/CentralLicenseCheckSchedule.php` evaluates first rollback promptly but respects bounded retries once a rollback-era attempt exists. `backend/tests/Feature/CentralLicenseCheckScheduleTest.php` reproduces first rollback, subsequent backoff and retry boundary, alongside existing 15-day/manual behavior.
- Exact verified CI: PENDING. Do not mark VERIFIED until the complete six hosted jobs pass for the final published SHA.
- Safe rollback / release gate: `central_control.activation_enabled` and `scheduled_checks_enabled` default OFF; previous confirmed source `1c94d1f7` and immutable signed local license remain intact until a verified safe update.
- Next: verify intervening documentation commit `501d8aba04e76ee77b40bfdc9b9212a1e7f85231` CI, publish with expected SHA, inspect all six jobs, update this entry with source/CI and preserve chronological evidence.
- Regression isolation: original first draft mixed rollback failure state into the existing normal cadence test; corrected separate RefreshDatabase test in `b0b43858` preserves all legacy assertions. Neither initial draft published.

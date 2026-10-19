# VenueDesk + HAFZATECH — Mandatory Quality, Bug Detection and Repair Policy

**Owner-approved: 2026-10-18. Applies to EVERY approved feature, all business modules, and the separate HAFZATECH Central Control Center. This is a process requirement, not proof existing code is bug-free.**

## Non-negotiable: find bugs AND FIX them
A developer must proactively try to break the implementation, reproduce any defect, identify and repair the root cause, add a regression test, and verify the **exact fixed source SHA** by required hosted CI. **Finding, documenting or reporting a bug is not completion.** Failing CI means the affected milestone remains NOT VERIFIED. Never silence, delete, weaken or skip tests; disable authorization, MFA, tenant guards or audit; fabricate source data; or turn on a default-OFF feature just to pass.

## Prevent features from going missing
Before each batch cross-reference the original specification, docs/COMMERCIAL_PRODUCT_EXPANSION.md, docs/CENTRAL_CONTROL_CENTER_V1.md, roadmap, feature register, AI_HANDOFF and subsequent owner approvals. Trace **feature ID → backend and UI → permissions + audit → error/retry behavior → tests → exact CI → acceptance**. No previously approved feature may silently disappear. A safe backend primitive is VERIFIED FOUNDATION, not COMPLETE end-to-end product functionality.

## Required bug-finding coverage, targeted by changed behavior
- Static analysis, lint, type checks, dependency/security audits and robust code review.
- Unit, integration and real PostgreSQL tests, including tenant/hall isolation, all staff roles, disabled/revoked users, permission escalation, SQL constraints, immutable audit and idempotent retries.
- Concurrency, double-booking, transaction rollback, payment/receipt reconciliation, signed-license forgery/replay/expiry, inventory stock conservation and external-provider failure handling.
- Browser/mobile authenticated end-to-end customer and staff workflows including accessibility, invalid or stale inputs, offline/timeout cases, daylight-saving/timezone boundaries and zero/three-decimal currency precision.
- Relevant backup, restore, upgrade/replay, queue and performance checks. After production deployment, monitored exceptions, security alerts and real workflow failures enter the same correction loop. Automated background bug repair may be claimed only when an actual running service does it.

## Mandatory fix loop
1. Detect via tests, security review, monitored error or user reproduction.
2. Reproduce and record stable BUG ID, affected requirement, severity, exact source/CI, expected vs actual and release impact in docs/ISSUE_AND_BUG_REGISTER.md.
3. Identify root cause and make the smallest COMPLETE correct fix without sacrificing security, functionality or data history.
4. Add regression coverage demonstrating the original failure and repaired result where feasible. Test affected neighboring features and cross-tenant/finance boundaries.
5. Commit only on authorized branch, run hosted CI, inspect EVERY required job and failing log at exact commit SHA. Repeat fix → tests → CI until green. Do not claim a CI-pending commit VERIFIED.
6. Update bug register (fix SHA, specific tests, exact successful CI, acceptance) and feature register / AI_HANDOFF, then continue next bounded milestone.

## Severity and release response
| Severity | Examples | Required action |
| --- | --- | --- |
| BLOCKER | Unauthorized data access; leaked secrets; forged license; wrong money posting; double-booking; loss/corruption; bypassed MFA/approval | Immediately stop affected release, keep flag OFF or revert safely, reproduce, fix, test and verify. |
| HIGH | Incorrect balances, broken inventory custody, missing critical audit, inconsistent booking/renewal, broken authenticated operation | Repair before affected milestone or production release. |
| MEDIUM | Reproducible normal-path defect, time-zone/currency discrepancy, broken error handling, serious usability/accessibility issue | Repair before feature acceptance; record external blockers with mitigation. |
| LOW | Cosmetic or low-impact improvement | Track with acceptance priority; still fix if required by approved feature. |

**Do not silently defer known critical defects.** A genuine externally blocked dependency (provider contract, legal payment eligibility, domain DNS/TLS, hardware signing key, approved bank account, operator secrets) is documented as BLOCKED EXTERNAL and affected production capability stays OFF; do not invent a fix, data, mock settlement or API.

## Phase completion gate
Feature can be called COMPLETE only after the real owner/manager/staff/customer experience and backend work end-to-end, tenant/hall and security tests and audit/financial integrity pass, all required CI jobs at exact SHA succeed, known blockers are disclosed and required deployment/restore/operator signoff is obtained. Distinguish code-complete from released. No claim of guaranteed zero bugs.

# Repository development instructions

## Scope
- Repository: `yasirskhan/Zargul-Silver-Marquee`.
- ONLY authorized branch: `chatgpt/phase0-foundation`.
- Use normal Chat for the owner's development sessions. Do not start Work mode.
- Never modify main, create another branch, force push, or edit `yasirskhan/property-platform`.
- A supervising session may coordinate both projects only under the owner's explicit authorization. A development worker remains restricted to this repository.

## Start and sources
- Read the ENTIRE concise root AI_HANDOFF.md, then the current specifications/registers it lists and relevant source/tests.
- Verify live branch HEAD, the latest branch-filtered GitHub Actions runs and every required job before writes.
- GitHub source and exact test results take precedence over stale handoff claims. Historical "prepared"/"detached" notes do not establish publication or verification.
- Read archived handoffs only for a related missing detail. Preserve archives and all approved requirements.
- Follow docs/SESSION_CONTROL.md for supervision and session recovery.

## Development and safe publication
- Maintain one active writer for this repository. Check current file blobs and branch HEAD; reconcile unexpected changes before retrying.
- Use expected_sha with force=false for branch publication. Preserve every concurrent change; never blind-overwrite or reset.
- Implement coherent backend + actual UI + authorization + durable audit + error/retry behavior and relevant tests.
- Fix failing CI and regression causes before proceeding to another feature. Preparing independent work while CI runs is allowed.
- Do not remove, skip or weaken tests, permissions, tenant/hall isolation, financial safeguards, immutable audit or release gates to obtain green CI.
- Run focused checks when available, then the complete required hosted workflow on the exact new SHA. Failed/skipped/pending jobs are NOT VERIFIED.
- A green run verifies its covered source, not an entire unfinished phase. Reconcile all approved requirements before phase completion.
- Keep production switches OFF until existing acceptance and operator-release requirements are met. Do not fabricate provider delivery, payments, source contracts, credentials or acceptance.

## Continuity and stopping
- Continue successive meaningful approved implementation batches within the active execution limits. A push, green run or documentation update alone is not the stopping condition.
- Keep root AI_HANDOFF.md current and concise; replace stale current state rather than append history. Preserve old text verbatim in an archive.
- Save actual code/drafts and identify their location before ending when possible. Distinguish published source, saved candidates, verified source and unknown unsaved work.
- Update existing roadmap, feature and issue registers honestly. Do not silently drop requirements, label foundations end-to-end complete, or invent local test execution.
- Report a genuine tool/usage/external blocker precisely. Never claim background development without a confirmed persistent mechanism.

## Zargul-specific acceptance
- Follow docs/DEVELOPMENT_PROTOCOL.md, docs/QUALITY_AND_BUG_REMEDIATION_POLICY.md and docs/DEVELOPMENT_EFFICIENCY.md.
- All six jobs are required: prototype-checks, central-postgres, licensing-postgres, laravel-postgres, editor-browser-e2e, authenticated-panel-e2e.
- Preserve complete VenueDesk public website/CMS, bookings, finance, approvals, staff/Telegram/audit and HAFZATECH central scope.
- Finish Phase 12 acceptance before Phase 13. Keep inventory/finance production release OFF.
- Preserve approved license terms, including 15-day automatic verification and independently authorized payments/signing; external production portal/KMS/provider obligations remain separately gated.

## Whole-project continuation after phase acceptance
- The owner's objective is the ENTIRE approved project roadmap, completed phase by phase. The current phase is a checkpoint, not the final stopping target.
- Before closing a phase, reconcile every approved requirement with working backend AND UI, authorization/isolation, audit, migrations, failure/retry behavior and meaningful acceptance tests. All six required hosted CI jobs must pass at the exact source SHA; required operator/security/restore acceptance must also be evidenced.
- Save the phase's verified source SHA, CI run/jobs, requirement evidence and unresolved blockers in the existing roadmap/feature/issue registers. Replace the concise handoff with the completed phase and the NEXT approved unfinished phase/task.
- Once all existing phase-exit requirements pass, immediately start the next approved phase in documented dependency order on the SAME authorized branch. No additional owner prompt is needed for already approved scope. Current-phase "do not advance"/"no next phase" shorthand means UNTIL the current phase is fully accepted, not a permanent stop after acceptance.
- Reconcile incomplete earlier approved phases and dependencies without repeating already verified work. Exclude deferred/unapproved scope; never invent a new phase.
- If a phase remains externally blocked, keep it OPEN/BLOCKED with the exact missing input. Continue other approved work only where the existing dependency rules permit it. Never fabricate provider credentials, payments, operator acceptance or release approval.
- Keep one writer per repository, expected_sha with force=false, normal Chat, current security/financial safeguards and production-release gates. Phase acceptance does not grant permission to deploy or activate production.
- Continue until ALL approved project requirements and cross-module acceptance are verified, or a genuine tool/usage/external blocker prevents further authorized progress. A phase-complete report alone is not the stopping condition; never promise uninterrupted execution beyond actual limits.

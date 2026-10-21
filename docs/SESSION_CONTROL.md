# Development session control

## Authority and scope
The owner authorized a supervising chat to monitor and direct development and to start replacement normal Chat sessions for Zargul and Property Platform. Each development session may edit ONLY its own repository and authorized branch. This authorization does not permit new branches, force pushes, main changes, production activation, weakened safeguards, or messaging other people.
This document describes the operating procedure. It is not a running service, a lock, or proof that monitoring has been scheduled.

## One writer per repository
- Track the exact worker conversation URL, current task and last saved source. Treat a new session as read-only until the previous writer is confirmed stopped or idle.
- Read recent conversation activity and the actual composer state. A past duration label, quiet chat or unchanged GitHub HEAD alone does not establish a hang. Allow real implementation, tests and CI to finish.
- Before replacement, recover published source and any saved draft/patch; record unsaved or inaccessible work as unknown. Never claim recovery of work that cannot be inspected.
- Once the prior turn is confirmed ended, start one replacement in normal Chat mode using the startup instructions below. Retire the old worker from further development. If old/new workers overlap, stop the replacement's writes and reconcile before continuing.
- A new session must reread live source and use an expected-HEAD fast-forward. A handoff or this document is not an enforceable distributed write lease.

## Monitor each run
1. Read the worker's recent activity; fetch the exact allowed branch HEAD and branch-filtered Actions runs and jobs. Account for pull-request test merge SHAs and verify the source commit associated with each run.
2. Compare changes with the active requirement, tests, root handoff, roadmap and issue/feature registers. Verify actual implementation; never infer whole-phase completion from a green workflow.
3. If CI failed, read failing logs and send a concrete repair instruction. Failed and skipped jobs are not green. Historical failed runs remain red after a later fix; record original failure -> repair commit -> successful exact-SHA run.
4. If the worker ended early with actionable requirements open, send a bounded implementation instruction to that same worker. Require code, relevant regression coverage and exact hosted verification, then the next approved milestone. Avoid repeated status-only prompts and commits.
5. Replace the session only for a confirmed full/broken/unusable session or an explicit coordinated transfer. Do not start another writer merely because a timer expired.
6. Report verified milestones, persistent failures, stopped work or an access/usage blocker. Do not send repetitive unchanged reports.

## Recovery startup
Identify the exact repository and ONLY branch. Read root AGENTS.md, the ENTIRE concise root AI_HANDOFF.md and the required current specification/register files. Verify live HEAD and CI. Read archived handoffs only for a related unresolved detail.
Fix current CI failures first, then implement the next unfinished approved requirement end-to-end. Preserve permissions, tenant/hall isolation, financial integrity and audit trails. Use expected_sha and force=false. Run every required CI job at the exact new SHA. Update requirements and the concise current checkpoint; continue meaningful work within the active execution limits.
Do not promise background coding unless a persistent execution mechanism is confirmed running. Usage limits, unavailable tools and operator-only acceptance remain real blockers.

## Checkpoint discipline
Keep root AI_HANDOFF.md approximately 60-100 lines where practical. Replace current facts instead of prepending/appending another historical report. Record UTC observation time, source/verified SHA, exact CI/run/jobs, current task, saved candidate locations, failures, next executable action and external blockers.
Archive prior text verbatim before removing history. Preserve requirement IDs, full specifications, feature/issue registers and every older archive. Never bulk-reread archives on every start.
Do not create a commit only for a heartbeat or routine polling. Save continuity with substantive work or a necessary recovery checkpoint.

## Automation boundaries
Scheduled checks are periodic, not a continuous process. The automation's actual success response and recorded runs determine whether it is active. Keep its current worker URLs in the automation prompt or next substantive checkpoint.
Before claiming automated session recovery works, verify that the scheduled execution environment can access the chat controls or authenticated browser and can start a normal Chat conversation. If it cannot, continue authorized GitHub checks and report that session intervention is blocked; do not silently create duplicate workers or claim a restart.

## Whole-project continuation after phase acceptance
- The owner's objective is the ENTIRE approved project roadmap, completed phase by phase. The current phase is a checkpoint, not the final stopping target.
- Before closing a phase, reconcile every approved requirement with working backend AND UI, authorization/isolation, audit, migrations, failure/retry behavior and meaningful acceptance tests. All six required hosted CI jobs must pass at the exact source SHA; required operator/security/restore acceptance must also be evidenced.
- Save the phase's verified source SHA, CI run/jobs, requirement evidence and unresolved blockers in the existing roadmap/feature/issue registers. Replace the concise handoff with the completed phase and the NEXT approved unfinished phase/task.
- Once all existing phase-exit requirements pass, immediately start the next approved phase in documented dependency order on the SAME authorized branch. No additional owner prompt is needed for already approved scope. Current-phase "do not advance"/"no next phase" shorthand means UNTIL the current phase is fully accepted, not a permanent stop after acceptance.
- Reconcile incomplete earlier approved phases and dependencies without repeating already verified work. Exclude deferred/unapproved scope; never invent a new phase.
- If a phase remains externally blocked, keep it OPEN/BLOCKED with the exact missing input. Continue other approved work only where the existing dependency rules permit it. Never fabricate provider credentials, payments, operator acceptance or release approval.
- Keep one writer per repository, expected_sha with force=false, normal Chat, current security/financial safeguards and production-release gates. Phase acceptance does not grant permission to deploy or activate production.
- Continue until ALL approved project requirements and cross-module acceptance are verified, or a genuine tool/usage/external blocker prevents further authorized progress. A phase-complete report alone is not the stopping condition; never promise uninterrupted execution beyond actual limits.

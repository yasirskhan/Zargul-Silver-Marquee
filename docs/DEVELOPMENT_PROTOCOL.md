# Repository Execution and Session-Safety Protocol
Status: REQUIRED FOR ALL DEVELOPMENT SESSIONS — 2026-10-15

## Repository and branches
Repository: yasirskhan/Zargul-Silver-Marquee
Only current development branch: chatgpt/phase0-foundation. Never touch yasirskhan/property-platform. main stays protected until a reviewable tested release is authorized. No force-push or unreviewed merges.
Authoritative state: GitHub branch HEAD, tree content, CI conclusion for the exact SHA, requirement register, roadmap and handoff. Conversation memory is advisory, never the single source of truth.

## Cold-start procedure
1. Read ENTIRE AI_HANDOFF.md, docs/PROJECT_MASTER_SPEC.md, docs/FEATURE_REGISTER.csv, docs/ROADMAP.md, docs/WEBSITE_CMS_DESIGN.md, and relevant current code/tests.
2. Retrieve current development-branch HEAD SHA and associated full GitHub Actions runs, jobs and results.
3. Reconcile handoff claims with actual source tree and commits since last verified SHA. Never assume handoff is current.
4. Check any open failures, conflicting commits and unfinished tasks, choose smallest next unblocked requirement, and inspect dependencies.
5. Never repeat an already verified feature; never call mock UI a production service.

## Safe write protocol
- One active editor per workstream when possible. Before write, fetch current branch HEAD and target file blob SHA. Existing files MUST be fetched then updated against current blob SHA; never create-file over an existing path.
- Use bounded, cohesive changes with tests. Prefer PR/review with CI rather than destructive direct edits. If direct connector file commits are used, recheck HEAD after each batch.
- Do NOT use blind force updates, hard resets, or replace entire source directories. For lower-level Git refs use expected_sha/lease; if changed, stop and reconcile before retrying.
- Documentation updates cannot truthfully verify tests for a different SHA. Update AI_HANDOFF.md last, then verify its own exact SHA or clearly identify its unverified status.
- Concurrency: if another session/agent pushed, compare divergence, preserve both changes, rebase or reconcile deliberately. Do not overwrite another workstream's changes. If possible maintain lease lock through protected branch & PR review workflow.
- Never work on main, no new development branches without an explicit recorded decision. Do not push secrets or real customer data (repository is currently public).

## Mandatory implementation cycle
verify live HEAD+CI -> inspect NEXT requirement -> implement migration/service/UI/tests -> locally test if available -> commit -> hosted CI -> diagnose/fix red -> verify exact SHA -> update feature register and handoff -> continue next bounded requirement while execution environment allows.

## Verification matrix
- Code quality: formatting/lint/static analysis and dependency security checks.
- Backend: migrations on clean PostgreSQL and upgrade fixtures; auth, hall isolation, real booking concurrency, invoices and ledger reconciliation; notification deduplication; audit append-only guarantees.
- Frontend: desktop/mobile browser workflows, accessibility, screen sizes and keyboard interactions.
- Website CMS: revision conflict, drafts/published isolation, media upload validation, drag/reorder persistence, rollback and publishing authorization.
- After deployment: backup restore drill, monitoring, error alerts, manual acceptance and operational pilot.
CI SUCCESS for unit tests is not proof of browser/DB/production readiness. Unknown/unverified stays NOT VERIFIED.

## Truthful handoff template
Repository and branch
Timestamp UTC
Live HEAD + latest CI run ID/status (exact SHA)
Last VERIFIED source SHA and jobs
Files modified, pending commits/CI
Completed requirements and evidence (IDs)
Current task and precise next command/tool action
Failures/blockers and how to reproduce
Outstanding approvals/decisions
Anti-footguns: main untouched, data/secrets restrictions, incomplete UI/backend caveats

## Scope and decisions
Every approved requirement has an ID in docs/FEATURE_REGISTER.csv. New requests must be added without dropping earlier requirements. Changes to finance, tenancy, pricing semantics or security require explicit ADR in docs/decisions/. Pending choices remain tracked, not guessed into production.

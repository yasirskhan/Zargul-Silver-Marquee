# AI Handoff — Zargul Silver Marquee / VenueDesk

Last updated: 2026-10-15 (UTC)
Repository: yasirskhan/Zargul-Silver-Marquee
Working branch: chatgpt/phase0-foundation
Default branch: main
Related project: yasirskhan/property-platform — STRICTLY OFF LIMITS

## Confirmed GitHub access
- Repository read and write are functioning. Initial README commit on main: 83ee684f8b08e65bd673231e71f7d6ad6e817080.
- Development branch was created from that commit. Project files are being added on development branch.
- Hosted CI verification PENDING until the workflow is committed and run.
- Never mark Phase 0 complete merely because the files exist.

## Scope
Read docs/PROJECT_MASTER_SPEC.md, docs/FEATURE_REGISTER.csv and docs/ROADMAP.md. Preserve the entire approved product scope. User prioritizes mobile-first simplicity for owner/manager/staff, three programs daily, hall seating/add-ons and owner-controlled grace/overtime, strict booking/payment reliability, in-app owner approval inbox with private Telegram alert and emergency delegation, and searchable audit. Public site branded Zargul Silver Marquee; management app named VenueDesk.

## Current implementation
- README on main documents the new repository.
- package.json, assets/core.mjs and tests/core.test.mjs implement a DEMONSTRATION ONLY pure logic foundation; they do not persist bookings, enforce secure policies or record money.
- Master spec, feature register, roadmap are committed to the development branch.
- The prior session also generated a richer standalone static starter ZIP for UI reference, not yet committed. It must be reviewed/imported separately, without mistaking fake role buttons for secure authentication.

## Phase 0 next exact task
1. Add .github/workflows/ci.yml and verify that Node tests pass on the EXACT development-branch commit SHA.
2. Record CI run ID, conclusion, and product SHA once actually verified. Handoff commit itself requires reconciliation at the next session.
3. Review the previously generated UI prototype, add website/admin demo assets in a bounded tested batch if available.
4. Build the actual Laravel/Filament/PostgreSQL backend foundation on a new verified batch; do not treat browser mocks as production.
5. Maintain requirement status, tests, and this handoff in GitHub. Continue unblocked tasks within the active session.

## Constraints
- No changes to property-platform or any unrelated repository.
- Do not merge unverified changes to main.
- User-created repository is PUBLIC. Never commit secrets, customer data, live payment info, Telegram bot token, or unauthorized venue imagery.
- Do not claim a live backend exists. Release requires secure server-side authorization, database constraints, auditable ledger, recoverability and tests.
- Session loop: verify live HEAD/CI → inspect next uncompleted task → implement → tests → commit → hosted CI → fix reds → verify SHA → update requirement status and handoff → continue where possible.
- A green run for one SHA never validates a different SHA.

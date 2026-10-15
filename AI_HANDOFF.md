# AI Handoff — Zargul Silver Marquee / VenueDesk

Last updated: 2026-10-15 (UTC)
Repository: yasirskhan/Zargul-Silver-Marquee
Working branch: chatgpt/phase0-foundation
Default branch: main
Related project: yasirskhan/property-platform — STRICTLY OFF LIMITS

## Confirmed GitHub access
- Repository read and write are functioning. Initial README commit on main: 83ee684f8b08e65bd673231e71f7d6ad6e817080.
- Development branch was created from that commit. Project files are being added on development branch.
- Hosted CI: run 38800899043, SUCCESS for exact product SHA a8336f3d8f5465f9a62fbb2689121e442de59866. Node tests, required-file checks, and JS syntax passed. This is a Phase 0 PROTOTYPE check only.
- Do not assume the documentation-only handoff update commit is covered by prior CI; confirm the new SHA and check run before closing the batch.
- Phase 0 foundation for specifications and demo rules is verified; full website/admin mockup import and real Laravel backend remain NOT STARTED.

## Scope
Read docs/PROJECT_MASTER_SPEC.md, docs/FEATURE_REGISTER.csv and docs/ROADMAP.md. Preserve the entire approved product scope. User prioritizes mobile-first simplicity for owner/manager/staff, three programs daily, hall seating/add-ons and owner-controlled grace/overtime, strict booking/payment reliability, in-app owner approval inbox with private Telegram alert and emergency delegation, and searchable audit. Public site branded Zargul Silver Marquee; management app named VenueDesk.

## Current implementation
- README on main documents the new repository.
- package.json, assets/core.mjs and tests/core.test.mjs implement a DEMONSTRATION ONLY pure logic foundation; they do not persist bookings, enforce secure policies or record money.
- Master spec, feature register, roadmap are committed to the development branch.
- The prior session also generated a richer standalone static starter ZIP for UI reference, not yet committed. It must be reviewed/imported separately, without mistaking fake role buttons for secure authentication.

## Phase 0 next exact task
1. Verify the documentation-only handoff update CI against its exact SHA (prior product checkpoint a8336f3d8f5465f9a62fbb2689121e442de59866 is green in run 38800899043).
2. Import/review the previously generated responsive public website and admin mobile UI prototype in the next bounded batch; add appropriate smoke and browser tests; do not claim it is production-ready.
3. Inspect any pre-existing Laravel/Filament Phase 1 foundation before reuse; establish the secure PostgreSQL/backend architecture next.
4. Record every commit, actual CI run, acceptance evidence and task status in this handoff and docs/FEATURE_REGISTER.csv.
5. Continue on chatgpt/phase0-foundation without touching main or property-platform.

## Constraints
- No changes to property-platform or any unrelated repository.
- Do not merge unverified changes to main.
- User-created repository is PUBLIC. Never commit secrets, customer data, live payment info, Telegram bot token, or unauthorized venue imagery.
- Do not claim a live backend exists. Release requires secure server-side authorization, database constraints, auditable ledger, recoverability and tests.
- Session loop: verify live HEAD/CI → inspect next uncompleted task → implement → tests → commit → hosted CI → fix reds → verify SHA → update requirement status and handoff → continue where possible.
- A green run for one SHA never validates a different SHA.

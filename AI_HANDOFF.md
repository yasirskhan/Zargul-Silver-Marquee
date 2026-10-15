# AI Handoff — Zargul Silver Marquee / VenueDesk

Last updated: 2026-10-15 (UTC)
Repository: yasirskhan/Zargul-Silver-Marquee
Working branch: chatgpt/phase0-foundation
Default branch: main
Related project: yasirskhan/property-platform — STRICTLY OFF LIMITS

## Confirmed GitHub access
- Repository read and write are functioning. Initial README commit on main: 83ee684f8b08e65bd673231e71f7d6ad6e817080.
- Development branch was created from that commit. Project files are being added on development branch.
- Latest verified UI product SHA: 03e5191f165f8a8769be871ac0a986d580cfdeaa. Hosted CI run 38801706148: SUCCESS (prototype tests, UI structure tests, project-file verification, JS syntax). Earlier Phase 0 foundation SHA a8336f3d8f5465f9a62fbb2689121e442de59866 verified by run 38800899043. These verify only DEMO code.
- Do not assume the documentation-only handoff update commit is covered by prior CI; confirm the new SHA and check run before closing the batch.
- Responsive website and staff dashboard prototype now committed: index.html, admin.html, assets/styles.css, assets/site.mjs, assets/admin.mjs, tests/ui.test.mjs. Backend remains NOT STARTED; this UI has fictional data, editable cosmetic roles and no persistence.

## Scope
Read docs/PROJECT_MASTER_SPEC.md, docs/FEATURE_REGISTER.csv and docs/ROADMAP.md. Preserve the entire approved product scope. User prioritizes mobile-first simplicity for owner/manager/staff, three programs daily, hall seating/add-ons and owner-controlled grace/overtime, strict booking/payment reliability, in-app owner approval inbox with private Telegram alert and emergency delegation, and searchable audit. Public site branded Zargul Silver Marquee; management app named VenueDesk.

## Current implementation
- README on main documents the new repository.
- package.json, assets/core.mjs and tests/core.test.mjs implement a DEMONSTRATION ONLY pure logic foundation; they do not persist bookings, enforce secure policies or record money.
- Master spec, feature register, roadmap are committed to the development branch.
- The prior session also generated a richer standalone static starter ZIP for UI reference, not yet committed. It must be reviewed/imported separately, without mistaking fake role buttons for secure authentication.

## Phase 0 next exact task
1. Verify the current handoff/documentation commit's GitHub CI result against its exact SHA. Last successfully verified UI product source: 03e5191f165f8a8769be871ac0a986d580cfdeaa, hosted run 38801706148 SUCCESS.
2. Review site and staff demo in real desktop/mobile browsers; add Playwright accessibility/responsive interaction E2E. Static HTML and Node structure tests alone are insufficient for UI acceptance.
3. Inspect any pre-existing Laravel/Filament Phase 1 foundation before reuse; build real backend on isolated feature batches: tenant/hall permissions, Owner/Admin MFA, append-only audit and PostgreSQL tests.
4. Strengthen UI demo with monthly multi-hall calendar, itemized invoice preview and all required add-ons (current daily slot and quote previews are partial only).
5. Continue verification/feature register updates without touching main or property-platform.

## Constraints
- No changes to property-platform or any unrelated repository.
- Do not merge unverified changes to main.
- User-created repository is PUBLIC. Never commit secrets, customer data, live payment info, Telegram bot token, or unauthorized venue imagery.
- Do not claim a live backend exists. Release requires secure server-side authorization, database constraints, auditable ledger, recoverability and tests.
- Session loop: verify live HEAD/CI → inspect next uncompleted task → implement → tests → commit → hosted CI → fix reds → verify SHA → update requirement status and handoff → continue where possible.
- A green run for one SHA never validates a different SHA.

## Verified Phase 0 UI checkpoint (2026-10-15)
- CI run: https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/38801706148
- SHA: 03e5191f165f8a8769be871ac0a986d580cfdeaa
- Conclusion: SUCCESS.
- No production Laravel application, PostgreSQL data persistence, user authentication, verified Telegram integration or real audit ledger exists.
- User-facing design should remain responsive and premium; production hall photos/prices must be sourced from owner.

# AI Handoff — Zargul Silver Marquee / VenueDesk

Last updated: 2026-10-15 (UTC)
Repository: yasirskhan/Zargul-Silver-Marquee
Working branch: chatgpt/phase0-foundation
Default branch: main
Related project: yasirskhan/property-platform — STRICTLY OFF LIMITS

## Confirmed GitHub access
- Repository read and write are functioning. Initial README commit on main: 83ee684f8b08e65bd673231e71f7d6ad6e817080.
- Development branch was created from that commit. Project files are being added on development branch.
- Current verified prototype product SHA: 8282af5805efc940471731d9916584bfc5ebc581. Hosted CI run 38803758311: SUCCESS for that exact SHA. These verify only demo code; later documentation commits need independent CI confirmation.
- Do not assume the documentation-only handoff update commit is covered by prior CI; confirm the new SHA and check run before closing the batch.
- Responsive website, employee dashboard AND website editor prototype committed. Backend remains NOT STARTED; fictional data, purely visual roles and no persistent page or invoice writes.

## Scope
Read docs/PROJECT_MASTER_SPEC.md, docs/FEATURE_REGISTER.csv and docs/ROADMAP.md. Preserve the entire approved product scope. User prioritizes mobile-first simplicity for owner/manager/staff, three programs daily, hall seating/add-ons and owner-controlled grace/overtime, strict booking/payment reliability, in-app owner approval inbox with private Telegram alert and emergency delegation, and searchable audit. Public site branded Zargul Silver Marquee; management app named VenueDesk.

## Current implementation
- README on main documents the new repository.
- package.json, assets/core.mjs and tests/core.test.mjs implement a DEMONSTRATION ONLY pure logic foundation; they do not persist bookings, enforce secure policies or record money.
- Master spec, feature register, roadmap are committed to the development branch.
- The prior session also generated a richer standalone static starter ZIP for UI reference, not yet committed. It must be reviewed/imported separately, without mistaking fake role buttons for secure authentication.

## Latest verified product checkpoint (2026-10-15)
- Branch: chatgpt/phase0-foundation; main and yasirskhan/property-platform are OFF LIMITS.
- Site/dashboard/CMS prototype product commit: 8282af5805efc940471731d9916584bfc5ebc581.
- Hosted GitHub Actions run: 38803758311, SUCCESS for this EXACT source SHA.
- Passed: Node pure logic and CMS layout tests; prototype page/owner editor structural checks; required files; JavaScript syntax.
- GitHub paths: website-editor.html, assets/website-editor.mjs, assets/editor.css, assets/cms-schema.mjs, tests/cms-schema.test.mjs, tests/website-editor.test.mjs.
- Earlier responsive site/dashboard: index.html, admin.html, assets/styles.css, assets/site.mjs, assets/admin.mjs.
- Required new specification: docs/WEBSITE_CMS_DESIGN.md. Multi-session rules: docs/DEVELOPMENT_PROTOCOL.md. Proposed architecture decision: docs/decisions/ADR-0001-platform-and-cms.md.
- FEATURE_REGISTER.csv now tracks 33 requirements including detailed CMS IDs CMS-001 through CMS-008.
- CRITICAL: These are demonstration/prototype screens only. No PostgreSQL, authenticated users, production CMS publishing, Telegram integration, finance ledger or real bookings yet. The owner's on-screen publish button is explicitly simulated; no changes appear on public live website.

## Exact next bounded implementation task
1. Recheck live HEAD and latest CI after this handoff commit; do not assume product SHA or CI applies to subsequent doc commits.
2. Review and validate the visual Website Editor behavior in real desktop and mobile browsers with Playwright; ensure section and column reorder controls, logo preview, safe field edits and keyboard/mobile interaction. Resolve any red CI or functional defect.
3. Bootstrap REAL Laravel 13 + Filament 5 + PostgreSQL project in a bounded batch. Before pinning packages verify versions on clean Composer install in CI and record composer.lock; preserve mockups separately, do not overwrite source blindly.
4. Establish tenant/company/hall identity, owner/admin roles and MFA, staff authorization, permissions, migration/audit foundation. Company scoped website page revisions and CMS media/publish rights should be integrated here.
5. Build actual CMS page/revision/media migrations, server-side validation, safe renderer, draft optimistic locks and authenticated publication with audit; then connect public venue website dynamic blocks.
6. Work per requirements and update FEATURE_REGISTER.csv and this handoff with exact commits/run IDs, then continue to reservation/finance/approval modules in docs/ROADMAP.md.
7. No force push, no blind overwrite; fetch current file blob before update, reconcile HEAD drift. Do not ever mark demo tests as production acceptance.

## Constraints
- No changes to property-platform or any unrelated repository.
- Do not merge unverified changes to main.
- User-created repository is PUBLIC. Never commit secrets, customer data, live payment info, Telegram bot token, or unauthorized venue imagery.
- Do not claim a live backend exists. Release requires secure server-side authorization, database constraints, auditable ledger, recoverability and tests.
- Session loop: verify live HEAD/CI → inspect next uncompleted task → implement → tests → commit → hosted CI → fix reds → verify SHA → update requirement status and handoff → continue where possible.
- A green run for one SHA never validates a different SHA.

## Earlier verified Phase 0 UI checkpoint (2026-10-15)
- CI run: https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/38801706148
- SHA: 03e5191f165f8a8769be871ac0a986d580cfdeaa
- Conclusion: SUCCESS.
- No production Laravel application, PostgreSQL data persistence, user authentication, verified Telegram integration or real audit ledger exists.
- User-facing design should remain responsive and premium; production hall photos/prices must be sourced from owner.

# AI HANDOFF — Zargul Silver Marquee / VenueDesk

Last verified at: 2026-10-15 UTC
Repository: yasirskhan/Zargul-Silver-Marquee
ONLY working branch: `chatgpt/phase0-foundation`
Default `main`: untouched since README initialization at `83ee684f8b08e65bd673231e71f7d6ad6e817080`
STRICTLY OUT OF SCOPE: yasirskhan/property-platform and all other repositories.
GitHub connector: repository read and write successful.

## MUST-READ ON EVERY NEW SESSION
Read this ENTIRE file; docs/PROJECT_MASTER_SPEC.md; docs/FEATURE_REGISTER.csv; docs/ROADMAP.md; docs/WEBSITE_CMS_DESIGN.md; docs/DEVELOPMENT_PROTOCOL.md; docs/decisions/ADR-0001-platform-and-cms.md. Then read relevant source and tests. Verify LIVE development branch HEAD + exact SHA CI/jobs before writing. Actual repo/CI takes precedence over old handoff state.

## Last fully VERIFIED PRODUCT checkpoint
- **Source commit:** `51c839a9d6201f206c46fd47441b7f286dc8a564`
- **Hosted CI:** `38807212590`, SUCCESS.
- Exact GitHub Actions: https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/38807212590
- Both jobs SUCCESS: `prototype-checks`, `laravel-postgres`.
- PHP 8.3 clean Composer package resolution of Laravel 13 + Filament 5 succeeded.
- PostgreSQL 16 migrations succeeded, including versioned website and tenant identity tables.
- Backend `11 passed (21 assertions)`: tenant/hall access, delegated website edit vs publish checks, cross-tenant composite FK rejection, invalid role rejection, isolation of draft/published revision, cross-page published revision rejection, immutable revisions/audit records.
- Database **CMS migration rollback and replay** succeeded.
- Node prototype logic and UI structure checks and JS syntax passed.
- **Locked dependency product checkpoint is verified** at the above SHA. Composer lock downloaded from the verified PHP 8.3 artifact, vetted (141 packages, content hash f199e7dfdf44e33a642f6ac51604217e), committed at 8c1e01e2; hosted CI now uses `composer install`. One-time lock-promotion workflow was removed. This documentation change must itself pass CI.

## What is committed and what is NOT
**Prototype UI only** (root):
- `index.html`, `assets/site.mjs`, `assets/styles.css`: premium public venue website preview and customer enquiry demonstration.
- `admin.html`, `assets/admin.mjs`: mobile-friendly owner/manager/staff dashboard, example calendar, customer pricing/finance/approval/search data. Role switch is COSMETIC.
- `website-editor.html`, `assets/website-editor.mjs`, `assets/editor.css`, `assets/cms-schema.mjs`: interactive in-tab website editor demo; reorder sections/columns, desktop/mobile layout, logo/theme preview, demo draft/publish snapshots. NOTHING is saved to a server.
- Node tests: `tests/core.test.mjs`, `tests/ui.test.mjs`, `tests/cms-schema.test.mjs`, `tests/website-editor.test.mjs`.

**New REAL server foundation** (isolated under `backend/`):
- `composer.json`, `artisan`, `bootstrap/`, `public/index.php`, Filament `AdminPanelProvider`, Laravel `User` and company/hall/membership/access models.
- `app/Services/TenantAccess.php`: per-company & hall checks, owner/admin roles, delegated website edit and publish flags, basic booking-management access. This is an INITIAL policy foundation, NOT complete API-level authorization or tenant security.
- `database/migrations/2026_10_15_000000_create_identity_tables.php`: users, companies, halls, company memberships, hall assignments; company-matched FK constraints and role check.
- `database/migrations/2026_10_15_000100_create_website_revision_tables.php`: websites, site media, pages, revisions, publication events and audit events; composite FK protections, update/delete-protecting PostgreSQL triggers, reversible schema.
- `tests/Feature/TenantAccessTest.php`, `tests/Feature/SiteVersioningTest.php` with REAL PostgreSQL-backed verification.
- `.github/workflows/ci.yml`: Node and PHP + PostgreSQL 16 jobs; schema rollback/replay check.

**Do NOT call this production-ready:** No actual booking persistence/atomic allocation, invoicing or payment ledger, owner MFA setup, Telegram, customer intake API, server-side CMS page validator, approved content publishing service, media upload pipeline, real interactive Filament resources or complete browser E2E. No live customer data or production deployment. Filament sign-in shell exists but its auth/authorization is NOT ready for real business use. Public repo: no production secrets or customer information.

## Critical dependencies/known technical debts
1. **Composer dependency lock completed and verified.** `backend/composer.lock` committed from verified artifact `12005937405`; CI now uses `composer install` and no longer requires lock generation or a write-permission bootstrap. Renovate/update dependencies only via a separately tested change.
2. Model defaults `is_active` and optional permission booleans were set on fresh Eloquent models; do not remove without tests. CI formerly failed until fixed.
3. PostgreSQL `venuedesk_immutable_history` function is `CREATE OR REPLACE` to support test-database refresh; dropping and replaying CMS schema is tested. App DB owner/superuser can still bypass ordinary trigger protection; enforce least-privilege database roles, secure external audit export and retention before production.
4. Browser E2E responsive/accessibility tests for root mockups have NOT been executed.
5. Current website images and capacities are illustrative; replace with owner-authorized real business details before public launch. No fake reviews/metrics.
6. The current `backend/` is separate from static root prototypes. Do not replace/overwrite root mockups; connect them only after secure server services and verified UI paths exist.
7. Phase 1 is **IN PROGRESS**, not complete: initial identities, migrations, basic access and tests are verified, but MFA/auth flows, production policies, scoped site publishing and audit writer are pending.

## REQUIRED NEXT BOUNDED TASK — immediately implement after cold-start
- Step 1: verify LIVE head and CI for **this handoff commit**, reconcile any intervening commits.
- Step 2: COMPLETE/VERIFIED at SHA `51c839a9d6201f206c46fd47441b7f286dc8a564`, CI `38807212590`: locked Composer install and one-time bootstrap removal.
- Step 3: NEXT UNFINISHED TASK: implement typed **server-side page block validation** (mirror the stricter accepted CMS block schema), company-scoped SitePage/SitePageRevision models and *transactional authenticated* draft-save & publish service with optimistic concurrency, immutable revisions and audit records. Test two-editor stale revision rejection, unauthorized publish, no draft content leakage, and rollback.
- Step 4: complete company-aware authentication, MFA for owner/admin, resources and least-privilege policy enforcement. Add email verification/session expiry as required by approved spec.
- Step 5: add Playwright browser tests for website editor on desktop/mobile and preserve existing mockups.
- Step 6: continue Phase 2 configurable halls/session times/capacity/add-ons and Phase 3 no-double-booking concurrency. Then finance ledger, approval center/Telegram, website CMS publishing/media, operations and pilot per docs/ROADMAP.md.
- One bounded batch at a time: **verify live SHA → inspect next requirement → implement & tests → commit → hosted CI → fix red → verify exact SHA → update FEATURE_REGISTER.csv and AI_HANDOFF.md → immediately inspect next unblocked task**. Continue as long as the active execution environment permits; no claim of continuous background operation.
- Never force-push, create new branches without agreement, blindly replace existing files, assume old CI verifies new HEAD, mark prototypes as complete production modules, touch `main` or touch property-platform.
- When another session edits a file, fetch current blob SHA, compare differences and reconcile; do not overwrite concurrent work. Record unresolved blockers exactly.

## Defects repaired during Phase 1
- Run `38804939995`: backend PHPUnit could not run because Mockery development dependency absent. Added `mockery/mockery`.
- Run `38805071892`: fresh user/hall Eloquent models initially omitted in-memory `is_active` defaults, resulting in false access denials; corrected model defaults.
- Earlier CMS run `38805587443`: PostgreSQL test refresh kept PL/pgSQL function; corrected migration to `CREATE OR REPLACE FUNCTION`.
- Latest verified run `38807212590` passes ALL required checks for SHA `51c839a9d6201f206c46fd47441b7f286dc8a564`. Future CI failures are not resolved by this historical green run.

## Truthfulness and handoff update rule
If current doc-only commit is verified, record that exact SHA and CI run only after GitHub reports completion. Last verified **product** source SHA above remains the source baseline until a later product change is tested. CI is evidence of specific jobs, not a zero-bugs guarantee or a live production release.

## 2026-10-15 session checkpoint: Composer lock promoted
- Artifact from `38805803519` verified by SHA-256 `2952e25b1e7befaf6c22c8a14b84a78eb588b1d0db2aad75ba7f14973923fe3d`; `backend/composer.lock` locked Laravel v13.36.0, Filament v5.10.2 and 141 packages.
- Promotion workflow `c438b4f` ran successfully and GitHub Actions committed lock at `8c1e01e`. Standard CI changed from `composer update` to `composer install`; bootstrap workflow deleted.
- Exact-source `51c839a9d6201f206c46fd47441b7f286dc8a564`, run `38807212590`, completed SUCCESS with `prototype-checks` and `laravel-postgres`.
- Next task: strict PHP CMS schema validation, authenticated tenant-scoped transactional draft/publish operations, stale-editor and immutable-history tests. Do not mark those features implemented until green exact-SHA CI.

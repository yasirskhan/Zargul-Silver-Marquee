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
- **Source commit:** `cf2bc68be8a1d40dc6434b8bb98183e4dad325ba`
- **Hosted CI:** `38808404906`, SUCCESS.
- Exact GitHub Actions: https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/38808404906
- Both jobs SUCCESS: `prototype-checks`, `laravel-postgres`.
- PHP 8.3 clean Composer package resolution of Laravel 13 + Filament 5 succeeded.
- PostgreSQL 16 migrations succeeded, including versioned website and tenant identity tables.
- Backend `11 passed (21 assertions)`: tenant/hall access, delegated website edit vs publish checks, cross-tenant composite FK rejection, invalid role rejection, isolation of draft/published revision, cross-page published revision rejection, immutable revisions/audit records.
- Database **CMS migration rollback and replay** succeeded.
- Node prototype logic and UI structure checks and JS syntax passed.
- **Locked dependency and initial transactional CMS services are verified** at the above SHA. Composer lock downloaded from the verified PHP 8.3 artifact, vetted (141 packages, content hash f199e7dfdf44e33a642f6ac51604217e), committed at 8c1e01e2; hosted CI now uses `composer install`. One-time lock-promotion workflow was removed. This documentation change must itself pass CI.

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

**Do NOT call this production-ready:** No actual booking persistence/atomic allocation, invoicing or payment ledger, owner MFA setup, Telegram, customer intake API, CMS HTTP/controller integration, media upload pipeline, real interactive Filament resources or complete browser E2E. Backend page validator and draft/publish service exist but do NOT provide a production-ready website. No live customer data or production deployment. Filament sign-in shell exists but its auth/authorization is NOT ready for real business use. Public repo: no production secrets or customer information.

## Critical dependencies/known technical debts
1. **Composer dependency lock completed and verified.** `backend/composer.lock` committed from verified artifact `12005937405`; CI now uses `composer install` and no longer requires lock generation or a write-permission bootstrap. Renovate/update dependencies only via a separately tested change.
2. Model defaults `is_active` and optional permission booleans were set on fresh Eloquent models; do not remove without tests. CI formerly failed until fixed.
3. PostgreSQL `venuedesk_immutable_history` function is `CREATE OR REPLACE` to support test-database refresh; dropping and replaying CMS schema is tested. App DB owner/superuser can still bypass ordinary trigger protection; enforce least-privilege database roles, secure external audit export and retention before production.
4. Browser E2E responsive/accessibility tests for root mockups have NOT been executed.
5. Current website images and capacities are illustrative; replace with owner-authorized real business details before public launch. No fake reviews/metrics.
6. The current `backend/` is separate from static root prototypes. Do not replace/overwrite root mockups; connect them only after secure server services and verified UI paths exist.
7. Phase 1 is **IN PROGRESS**, not complete: identities/migrations/basic access/transactional draft-publish/audit inserts verified; MFA/auth flows, model-level policies, CMS HTTP integration, audited rollback workflow, media approval and audit export are pending.

## REQUIRED NEXT BOUNDED TASK — immediately implement after cold-start
- Step 1: verify LIVE head and CI for **this handoff commit**, reconcile any intervening commits.
- Step 2: COMPLETE/VERIFIED at SHA `51c839a9d6201f206c46fd47441b7f286dc8a564`, CI `38807212590`: locked Composer install and one-time bootstrap removal.
- Step 3: Typed CMS validator and authenticated company-scoped transactional draft-save and publish service COMPLETE/VERIFIED at `cf2bc68be8a1d40dc6434b8bb98183e4dad325ba`, CI `38808404906` (after test fix). Tested stale revision rejection, delegation denial, published snapshot isolation, idempotent publish, audit/rollback failure handling. **NEXT:** company-scoped Website/SitePage/SitePageRevision models, policies and safe routes/CSRF/authentication. Publishing service itself is not yet wired to public or Filament routes.
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
- Latest verified run `38808404906` passes ALL required checks for SHA `cf2bc68be8a1d40dc6434b8bb98183e4dad325ba`. Future CI failures are not resolved by this historical green run.

## Truthfulness and handoff update rule
If current doc-only commit is verified, record that exact SHA and CI run only after GitHub reports completion. Last verified **product** source SHA above remains the source baseline until a later product change is tested. CI is evidence of specific jobs, not a zero-bugs guarantee or a live production release.

## 2026-10-15 session checkpoint: Composer lock promoted
- Artifact from `38805803519` verified by SHA-256 `2952e25b1e7befaf6c22c8a14b84a78eb588b1d0db2aad75ba7f14973923fe3d`; `backend/composer.lock` locked Laravel v13.36.0, Filament v5.10.2 and 141 packages.
- Promotion workflow `c438b4f` ran successfully and GitHub Actions committed lock at `8c1e01e`. Standard CI changed from `composer update` to `composer install`; bootstrap workflow deleted.
- Exact-source `51c839a9d6201f206c46fd47441b7f286dc8a564`, run `38807212590`, completed SUCCESS with `prototype-checks` and `laravel-postgres`.
- Next task: strict PHP CMS schema validation, authenticated tenant-scoped transactional draft/publish operations, stale-editor and immutable-history tests. Do not mark those features implemented until green exact-SHA CI.

## 2026-10-15 CMS validator verified
- `backend/app/Services/SiteContentValidator.php` rejects raw markup, unsafe script URLs, unsupported typed blocks/fields, duplicate IDs, invalid responsive column widths/orders, and excessive sections/depth. Tests: `backend/tests/Feature/SiteContentValidatorTest.php`.
- Exact source commit `52b52ef6d9d2c58800f634ca8bba83eb09188684`, hosted CI `38807658607`: both jobs SUCCESS. Validator is a backend foundation, not a public CMS persistence API.
- Prepared but uncommitted Git blob objects for NEXT service batch: `backend/app/Services/SitePagePublishingService.php` blob `08e7f7db74974d9bf7d054aa882cfde832a2b7a8`, tests blob `55a9afa12652b63e337478ca39591ab2991dbf01`. Review against latest HEAD before committing; blobs are NOT tested or verified and may be discarded if intervening changes conflict.

## 2026-10-15 CMS draft/publish service verified
- `backend/app/Services/SitePagePublishingService.php`: authenticated actor check, live tenant edit/publish authorization, strict typed content validation, tenant-owned media checks, PostgreSQL row-lock and optimistic revision compare, immutable draft revisions, atomic publish pointer/events and audit inserts, idempotent publish retries, public published-only read method.
- `backend/tests/Feature/SitePagePublishingServiceTest.php`: owner publish, second editor stale conflict, delegated edit without publish, cross-company denial, unsafe content rollback, missing media, injection of audit failure to verify rollback of draft and published pointer.
- First run `38808158908` failed a TEST ordering assumption: PostgreSQL JSONB canonicalizes object keys. Repaired two structural-content assertions in `cf2bc68`; final hosted CI `38808404906`, both jobs SUCCESS at exact SHA `cf2bc68be8a1d40dc6434b8bb98183e4dad325ba`.
- NEXT models batch is PREPARED AS UNCOMMITTED GIT OBJECTS (not verified): Website.php `dac81a6b803c2ce24ffe7c4bb0d92373c577163c`, SitePage.php `343827be53e1b7e112079f5ae9c3567953869b34`, SitePageRevision.php `dbd75b0e9bdb07ec5e129b89e705cc59ae89dae8`, SiteModelsScopeTest.php `3ff1786a892ec3e455e80af204789b10b9cd07aa`. Recheck HEAD and review before committing.

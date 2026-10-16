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
- **Source commit:** `974b9c03b4b20347da7d425cc66437979a3374c3`
- **Hosted CI:** `38827775344`, SUCCESS.
- Exact GitHub Actions: https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/38827775344
- All three jobs SUCCESS: `prototype-checks`, `laravel-postgres`, `editor-browser-e2e`.
- PHP 8.3 clean Composer package resolution of Laravel 13 + Filament 5 succeeded.
- PostgreSQL 16 migrations succeeded, including versioned website and tenant identity tables.
- Backend `86 passed (392 assertions)` at latest CI, covering previous security/CMS tests and new private branding preview checks. Earlier tests include: tenant/hall access, delegated website edit vs publish checks, cross-tenant composite FK rejection, invalid role rejection, isolation of draft/published revision, cross-page published revision rejection, immutable revisions/audit records.
- Database **CMS migration rollback and replay** succeeded.
- Node prototype logic and UI structure checks and JS syntax passed.
- **Locked dependencies, CMS transactional foundation with audited restore and scoped authorization policy, Filament MFA and shared PostgreSQL cache/session infrastructure are verified** at the above SHA. Composer lock downloaded from the verified PHP 8.3 artifact, vetted (141 packages, content hash f199e7dfdf44e33a642f6ac51604217e), committed at 8c1e01e2; hosted CI now uses `composer install`. One-time lock-promotion workflow was removed. This documentation change must itself pass CI.

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

**Do NOT call this production-ready:** No actual booking persistence/atomic allocation, invoicing or payment ledger, production-proven owner MFA flows, Telegram, customer intake API, CMS HTTP/controller integration, media upload pipeline, real interactive Filament resources or complete browser E2E. Backend page validator and draft/publish service exist but do NOT provide a production-ready website. No live customer data or production deployment. Filament sign-in shell exists but its auth/authorization is NOT ready for real business use. Public repo: no production secrets or customer information.

## Critical dependencies/known technical debts
1. **Composer dependency lock completed and verified.** `backend/composer.lock` committed from verified artifact `12005937405`; CI now uses `composer install` and no longer requires lock generation or a write-permission bootstrap. Renovate/update dependencies only via a separately tested change.
2. Model defaults `is_active` and optional permission booleans were set on fresh Eloquent models; do not remove without tests. CI formerly failed until fixed.
3. PostgreSQL `venuedesk_immutable_history` function is `CREATE OR REPLACE` to support test-database refresh; dropping and replaying CMS schema is tested. App DB owner/superuser can still bypass ordinary trigger protection; enforce least-privilege database roles, secure external audit export and retention before production.
4. Chromium desktop and 360px phone editor prototype E2E pass at `fd1bfaf` CI `38814758861`; comprehensive a11y and authenticated Filament browser E2E remain pending.
5. Current website images and capacities are illustrative; replace with owner-authorized real business details before public launch. No fake reviews/metrics.
6. The current `backend/` is separate from static root prototypes. Do not replace/overwrite root mockups; connect them only after secure server services and verified UI paths exist.
7. Phase 1 is **IN PROGRESS**, not complete: identities/migrations/basic access/transactional draft-publish/audit inserts, Filament MFA plus shared cache/sessions verified; MFA browser/operational flows, full model-level policies, CMS HTTP integration, audited rollback workflow, media approval and audit export are pending.

## REQUIRED NEXT BOUNDED TASK — immediately implement after cold-start
- Step 1: verify LIVE head and CI for **this handoff commit**, reconcile any intervening commits.
- Step 2: COMPLETE/VERIFIED at SHA `51c839a9d6201f206c46fd47441b7f286dc8a564`, CI `38807212590`: locked Composer install and one-time bootstrap removal.
- Step 3: Typed CMS validator and authenticated company-scoped transactional draft-save and publish service COMPLETE/VERIFIED at `cf2bc68be8a1d40dc6434b8bb98183e4dad325ba`, CI `38808404906` (after test fix). Tested stale revision rejection, delegation denial, published snapshot isolation, idempotent publish, audit/rollback failure handling. Filament 5 mandatory TOTP MFA, encrypted recovery codes, password reset, email verification and active-company panel admission **VERIFIED** at `98fb311c219028afe3c0055317f8a179a6cec3ae`, CI `38809096372`. Shared PostgreSQL-backed cache locks and session storage, configurable expiry, encrypted session setting, 3-migration rollback/replay **VERIFIED** at `91eeafdba6b5253acf787e0b9562840363477594`, CI `38809986246`. Company-scoped Laravel SitePagePolicy for read/update/publish/delete denial and verified-email checks in service **VERIFIED** at `a4adf3518dae789080a0c71b5a74af0e50fffc01`, CI `38810316559`. Audited `restorePublishedRevision()` with prior-published-only guard, optimistic concurrency, rollback, immutable history and draft preservation **VERIFIED** at `b9caf82d1039f6ce85734fe7f01a68f0b4c55110`, CI `38810740763`. **NEXT:** connect private media staging, owner approval and typed image insertion to MFA-protected Filament workspace; add tenant and permission Livewire tests. Then branding/theme, safe variants and authenticated panel browser E2E. Company-scoped Website/SitePage/SitePageRevision models are VERIFIED at `0d9ea64b8ab02c05940d44541fb873c44e945851`, CI `38808747998`; explicit scopes are NOT automatic authorization. Publishing service itself is not yet wired to public or Filament routes.
- Step 4: Filament MFA/email verification foundation VERIFIED at `98fb311c219028afe3c0055317f8a179a6cec3ae`; shared replay-resistant cache/session infrastructure VERIFIED at `91eeafdba6b5253acf787e0b9562840363477594`. Continue real verification email delivery and privileged recovery procedures, then full least-privilege CMS/API policies and tests. Initial SitePagePolicy plus verified-email publishing guard verified at `a4adf3518dae789080a0c71b5a74af0e50fffc01`.
- Step 5: add Playwright browser tests for website editor on desktop/mobile and preserve existing mockups.
- Step 6: continue Phase 2 configurable halls/session times/capacity/add-ons and Phase 3 no-double-booking concurrency. Then finance ledger, approval center/Telegram, website CMS publishing/media, operations and pilot per docs/ROADMAP.md.
- One bounded batch at a time: **verify live SHA → inspect next requirement → implement & tests → commit → hosted CI → fix red → verify exact SHA → update FEATURE_REGISTER.csv and AI_HANDOFF.md → immediately inspect next unblocked task**. Continue as long as the active execution environment permits; no claim of continuous background operation.
- Never force-push, create new branches without agreement, blindly replace existing files, assume old CI verifies new HEAD, mark prototypes as complete production modules, touch `main` or touch property-platform.
- When another session edits a file, fetch current blob SHA, compare differences and reconcile; do not overwrite concurrent work. Record unresolved blockers exactly.

## Defects repaired during Phase 1
- Run `38804939995`: backend PHPUnit could not run because Mockery development dependency absent. Added `mockery/mockery`.
- Run `38805071892`: fresh user/hall Eloquent models initially omitted in-memory `is_active` defaults, resulting in false access denials; corrected model defaults.
- Earlier CMS run `38805587443`: PostgreSQL test refresh kept PL/pgSQL function; corrected migration to `CREATE OR REPLACE FUNCTION`.
- Latest verified run `38810740763` passes ALL required checks (38 backend tests / 128 assertions) for SHA `b9caf82d1039f6ce85734fe7f01a68f0b4c55110`. Future CI failures are not resolved by this historical green run.

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

## 2026-10-15 company-scoped CMS models verified
- Added Website, SitePage, SitePageRevision Eloquent models, explicit `forCompany` query scopes, relationships and additional update/delete guards around immutable history. Direct queries MUST be tenant-scoped; a model scope alone is not an authorization boundary.
- `backend/tests/Feature/SiteModelsScopeTest.php` verifies cross-company exclusion and inability to mutate Eloquent revision history.
- Hosted CI `38808747998` SUCCESS at product SHA `0d9ea64b8ab02c05940d44541fb873c44e945851`; both jobs green. Next Phase 1 security batch: built-in Filament 5 app-TOTP MFA (secret/recovery encryption), email verification, active-company panel admission tests. Rollout still needs secure delivery, operational account recovery and browser acceptance; no production approval.

## 2026-10-15 Filament 5 MFA and email verification foundation verified
- `User.php` implements Filament TOTP + recovery contracts/traits, Laravel MustVerifyEmail, encrypted TOTP and recovery-code casts with hidden serialization. Filament AdminPanelProvider enables mandatory app MFA, recovery, password reset, profile and email verification. Added reversible MFA column migration and tests.
- `canAccessPanel()` additionally rejects a user with only an inactive company. Exact source SHA `98fb311c219028afe3c0055317f8a179a6cec3ae` and hosted CI `38809096372` SUCCESS, 27 PostgreSQL-backed tests / 82 assertions, both jobs green.
- **NOT production-ready:** `.env.example` still has CACHE_STORE=array and SESSION_DRIVER=file and MAIL_MAILER=log, not shared atomic/cache sessions or real email delivery. Complete reliable shared session/cache infrastructure, MFA replay enforcement/expiries, account recovery policy and browser acceptance. Do not claim TOTP has been end-to-end browser tested.

## 2026-10-15 PostgreSQL shared auth/session foundation verified
- Added reversible migration `2026_10_15_000300_create_shared_auth_infrastructure.php` with shared `cache`, `cache_locks`, `sessions`. Local `.env.example`: `CACHE_STORE=database`, `SESSION_DRIVER=database`, `SESSION_LIFETIME=60`, encrypted session values, cache prefix; production must set secure cookie + real TLS/email/secret/backup. PHPUnit uses array stores globally for isolation, with explicit PostgreSQL integration testing for database cache locks and sessions.
- CI rollback/replay now covers last three CMS/MFA/shared migration steps. Initial run `38809632213` failed cache key prefix assertion; run `38809809617` hit Laravel cache lock duplicate key inside PHPUnit transaction. Fixed key prefix expectation and used `DatabaseMigrations` instead of `RefreshDatabase` for the actual lock-contention test. Exact SHA `91eeafdba6b5253acf787e0b9562840363477594` CI `38809986246`, 29 passed / 96 assertions; both jobs SUCCESS.
- NEXT pending uncommitted Git blobs: `backend/app/Policies/SitePagePolicy.php` blob `e2b4f6375b83ce14070bd3e8b5396196d4e1c411`; `backend/tests/Feature/SitePagePolicyTest.php` blob `c02ca4ac8b69c905898325738d3c4247b7806aa9`. Review against current HEAD; not yet committed or verified. Never treat model scopes alone as authorization.

## 2026-10-15 Laravel CMS authorization policy verified
- `app/Policies/SitePagePolicy.php` company-scoped Laravel Gate policy: only verified account + active company/site and delegated edit/publish rights can view unpublished content, update or publish; physical delete denied to protect history. Actor verification enforced again inside SitePagePublishingService. PostgreSQL-backed Gate and stale service tests passed.
- Exact source `a4adf3518dae789080a0c71b5a74af0e50fffc01`, run `38810316559` both jobs SUCCESS, 35 tests 114 assertions. This is still not a fully protected HTTP API. Filament MFA must be enforced at *each* future editing endpoint, not merely plain Laravel session auth.
- NEXT prepared but UNCOMMITTED Git blobs: audited `restorePublishedRevision` method source `e754af5060d9ce389f0af1724601f0852bb7496e` and restore tests `a1cfb96e5341725ab791c53ea5b08599002e1087`. Check live HEAD before committing. Restore must only target a previously published revision and preserve draft/audit history. These blobs are not verified yet.

## 2026-10-15 audited published snapshot restore verified
- `SitePagePublishingService::restorePublishedRevision` transaction checks authenticated/verified publisher, tenant page/site, compare-and-swap published pointer, and target revision's existing publishing event. It validates historical typed content/media, updates published pointer only, inserts immutable `site_publishing_events` action `restore` and append-only audit; returns idempotently when already restored. Draft revision and history stay unchanged.
- Tests cover restoring older published content without deleting a newer draft, stale pointer, rejecting never-published draft, and transaction rollback when audit write is blocked.
- Product SHA `b9caf82d1039f6ce85734fe7f01a68f0b4c55110`, CI `38810740763`, both jobs SUCCESS, 38 tests / 128 assertions. **NEXT:** Filament media UI, approval and image blocks, followed by secure optimized public image variants, branding and authenticated Filament browser E2E. No production website acceptance yet.

## 2026-10-15 Filament website workspace navigation verified
- Added `backend/app/Services/SiteWorkspaceService.php`, `backend/app/Filament/Pages/WebsiteWorkspace.php`, Blade view and PostgreSQL tests. The Website tab lists only active verified users' own companies and active-site pages, does not trust tenant/page IDs from Livewire, rejects revoked membership, and displays selected draft/published state. Registered only within Filament's mandatory-MFA Admin panel; inherited panel authentication remains the only route into this workspace. Read-only UI so far, not a production editor.
- First batch CI `38812291312` failed a new TEST provider instantiation; fixed test constructor and explicit page registration. Exact product SHA `f5316f57638d4a84b84ddcbc21f2d6399cdf8d08`, CI `38812448013`: both jobs SUCCESS, backend 41 tests / 141 assertions.
- **NEXT:** Add save draft/publish controls to this Filament page with fresh Gate, tenant and revision checks per action, strict typed server validation, protected Livewire UI and appropriate regression tests. After that build accessible responsive visual drag/reorder and rollback UI. No live site connection, production MFA browser acceptance, or public go-live claim.

## 2026-10-15 Filament website draft editing and publishing verified
- Added typed text/hero/CTA sections, section moves/removal, local in-page field editing, load-latest, audited save draft, permission-separated published snapshot action. Every Livewire mutation uses fresh company/page scoping and SitePage Gate; server save/publish service additionally enforces tenant, verified actor, content/media validation and optimistic concurrency. Publish refuses local unsaved changes. A saved draft stays separate from public published content.
- Backend Livewire tests cover owner save/publish, delegated editor without publish UI, and rejection of unsaved edits. Product SHA `9ec24696e9ff16e322336a1878d37b3e2df3d460`, CI `38812979688`: BOTH jobs SUCCESS, PHP 44 tests / 162 assertions. Do NOT mistake typed field editing for complete drag-and-drop visual page builder or browser E2E; no real site published.
- **NEXT:** expose prior published version history and restore action only to authorized publishers, require server-side prior-publication proof and stale-pointer compare as existing service does, and test across owner/editor/tenant conflicts. Then improve responsive visual editing and Playwright acceptance.

## 2026-10-15 Filament published-history restore verified
- Added company/page-authorized published-history list and protected Livewire restore action. Only users with fresh page Gate publishing permission can access history; restore goes through the pre-existing transactionally audited service with prior-publication evidence and locked expected published pointer, without discarding draft content. A locked server-loaded published pointer updates after publish/restore.
- Livewire test creates two published revisions and a newer unpublished draft, restores the first, confirms the latest draft and immutable publication/audit events remain. Product SHA `ba216cc188c034dbc1c961358fd00c3a259c2750`; CI `38813371132` both jobs SUCCESS, 45 backend tests / 174 assertions.
- **NEXT:** accessible visual 1/2/3-column section controls, desktop width presets and independent mobile order, responsive draft preview using only escaped safe typed values; add PostgreSQL/Livewire tests. Following batch should add Playwright browser/mobile flows and media approval/owner branding; do not claim browser acceptance or deployment.

## 2026-10-15 Filament responsive typed builder verified
- Added owner/delegated editor-controlled 1/2/3-column section templates, 12-unit desktop width presets, independent phone order moves, nested block text controls, and escaped/placeholder-only desktop/tablet/phone draft preview. The workspace saves layouts via the previously tested strict validator, tenant-scoped service, immutable revision/audit. This is a typed layout editor, NOT yet native drag/drop or full production visual page builder.
- Two new Livewire PostgreSQL tests cover 3-column mobile reordering and persisted desktop widths and 2-column section moves. Product SHA `cc7df05a0ca18f74bea0ad8e9a1b3bf341451d6a`, run `38813878859`, BOTH jobs SUCCESS, 47 tests / 183 assertions. No Playwright/browser E2E conducted.
- **NEXT:** introduce native drag/drop with keyboard fallback for sections and desktop column ordering; validate server-side indices/role and preserve mobile stacking order. Then browser E2E for desktop and 360px phone; owner-controlled media/branding.

## 2026-10-15 Filament drag ordering controls verified at backend level
- Added native HTML draggable handles and Alpine drop listeners to move entire sections or reorder desktop columns without disturbing independent phone stacking. Keyboard/touch fallback buttons remain. Each Livewire move method rechecks company-scoped Gate on current actor and validates indices; actual save still passes tenant-scoped typed CMS validation and append-only audit.
- PostgreSQL Livewire regression tests verify section reorder, columns move, preserved mobile order, immutable draft/audit. SOURCE `66dab591eb5706b882e2fdfd4ffb3f2975eeceba`, CI `38814382810` both jobs SUCCESS, 48 tests / 189 assertions. **Not browser tested:** whether actual HTML5 drag events/Alpine listeners behave in Chrome and on mobile. Not production visual builder acceptance.
- **NEXT:** desktop/phone browser tests, then secure upload and approval for media, owner theme/logo, and broader tenant/hall policies. Do not mislabel browser behavior proven by PHP-only tests.

## 2026-10-15 Chromium prototype browser checks verified
- Added `playwright.config.mjs`, tests/browser/website-editor.spec.mjs and a separate `editor-browser-e2e` CI job using pinned `@playwright/test@1.56.1` plus headless Chromium. Tests run actual browser desktop section drag reordering, typed column insertion, draft demo publish, and 360px phone preview/branding and keyboard arrow section moves.
- Exact SOURCE `fd1bfaf86f5c04c19d8fb0b6481503d08d50fb55`, CI `38814758861`: ALL THREE jobs SUCCESS, Chromium **2 passed**, backend 48 tests / 189 assertions, Node prototype PASS. Browser tests exercise the STATIC prototype, not the protected server-backed Filament page, and must not be overstated.
- **NEXT:** tenant-scoped image upload staging, MIME and binary validation, dimensions/bytes, private storage, approval workflow, immutable audit and cleanup on failure; tests with real PostgreSQL. Then connect media library and owner branding to Filament and add authenticated browser acceptance. Booking, finance, approvals/Telegram and real public site remain upcoming.

## 2026-10-15 secure staged website image service verified
- Added `backend/app/Services/SiteMediaService.php`: checks logged-in/verified actor and active company/site with delegated editor/publisher rights; validates real JPEG/PNG/WebP bytes, MIME/dimensions/20MP/4MB, alt text, SHA-256, and private local storage with generated opaque filename. Atomic staged media row and immutable audit, cleanup staged file on transactional failures; never create a public-serving URL for staging.
- Separate `approveImage()` requires publisher rights and image existence, uses PostgreSQL row locking, immutable audit, and safe idempotent retries. Tests reject foreign/unverified/inactive editors and disguised bytes. SOURCE `adcafff973c9cbb603923e76acb520486ef83e59`, CI `38815140340`: all 3 jobs green, 52 backend tests 204 assertions, 2 Playwright prototype browser checks.
- **NEXT:** Filament upload/approve/insert UI scoped to tenant with Livewire integration and tests, then image processing/secure publication variants and branding controls. Staged files must remain private; no production-facing image rendering or public website deployment yet.

## 2026-10-15 verified Filament image-library integration and optimized private variants
- Intervening product source `896bf79b42ff44f2c0a707cdce018674093259be` delivered real Filament company-scoped upload, publisher approval, typed image insertion and Livewire test; hosted CI `38815515674` SUCCESS, all three jobs. Never repeat this work.
- Product `f8cba858f3c02442816c830a121f335088364a2d` (including small test syntax correction) adds integrity validation of persisted original bytes at approval, GD decode/re-encode of private width/height-capped WebP variants with stripped source metadata, sha256/size/dimensions in private asset metadata, safe idempotent owner approval, transactional audit and new-file cleanup on error. Added tamper and image resize tests.
- **Hosted CI `38817967003` SUCCESS for the exact product SHA:** `prototype-checks`, `laravel-postgres`, `editor-browser-e2e` all SUCCESS. PHP syntax, PostgreSQL migrations/test suite and rollback/replay passed; Chromium tests remain STATIC editor tests, not authenticated Filament acceptance.
- No public media URLs or rendering pipeline yet. Public publication still needs secure variant-only materialization, published-snapshot isolation, authenticated panel browser E2E, operational image security hardening (including cleanup on transactional retry) and accessibility review.
- **EXACT NEXT BOUNDED TASK:** owner-controlled branding draft/publish foundation for site themes/logo/favicon, with tenant-authorized scope, strict preset validation, optimistic concurrency, separate published vs draft state, immutable audit/revision history and PostgreSQL tests. Integrate owner controls into MFA-protected Filament after service verification. Keep media files private.
- Only branch `chatgpt/phase0-foundation`; never touch `main` or `property-platform`. Follow verified-SHA test/commit/CI/handoff continuation cycle.

## 2026-10-15 tenant-scoped branding foundation VERIFIED
- Product `e620d6c593f28f8edeb4a1c4e8c6c513778f6b3d`, hosted CI `38818316115` SUCCESS (all three jobs).
- Added reversible PostgreSQL branding schema: per-website independent draft revision number and published-revision pointer, append-only `site_branding_revisions` and `site_branding_publishing_events`, website/composite company foreign keys, immutable trigger protection and same-site published FK.
- Added `SiteBrandingValidator` with explicit site name/tagline fields, restricted theme and font presets, nullable UUID logo/favicon references. Arbitrary CSS/JS, unsafe content, unknown fields and arbitrary asset aliases rejected.
- PostgreSQL tests prove cross-company branding revision rejection, foreign-site publish pointer rejection, persisted draft/published separation, immutable revisions/publish events; source syntax, all backend tests, schema rollback/replay and prototype Chromium CI pass.
- **NEXT** implement `SiteBrandingService` with authenticated company-scoped transactional draft-save/publish, optimistic version check, separate edit/publisher access, tenant-owned approved sanitized media requirement, append-only audit and published-only safe read; then Livewire owner controls for branding and image selection. No public website rendering or live branding release yet.
- `main` and `property-platform` untouched.

## 2026-10-15 versioned branding application service VERIFIED
- Product `df8509d94647c2491c3d09c1e586b8403559f7b3`, GitHub Actions `38818610733`: `prototype-checks`, `laravel-postgres`, and `editor-browser-e2e` all SUCCESS (exact SHA).
- New `SiteBrandingService` uses authenticated verified actor, active company membership and website, row locks, revision CAS, tenant-scoped media, private intact approved WebP variant verification, atomic immutable draft/publish revisions and publication events, and append-only audit.
- Tests cover separate public vs unsaved draft branding, idempotent publishing, delegated editor without publish permission, stale concurrent drafts, cross-company actor denial, unsafe theme rollback, pending logo rejected for publication, approved logo accepted, and changed optimized variant rejected.
- Public reads are published-pointer-only; no browser UI or public media routes exist. Owner-facing Filament branding controls, audited restore, public variant-only publication, operational MFA browser E2E and live site acceptance are still outstanding.
- **NEXT BOUNDED TASK:** wire SiteBrandingService into existing MFA-protected Filament WebsiteWorkspace as a company-scoped owner/edit-delegated branding panel, with logo/favicon selection from authorized private library, reasons, load/save/publish permissions and Livewire PostgreSQL tests. Keep draft/public separation and prevent unsaved-form publishing.

## 2026-10-15 authenticated Filament website branding controls VERIFIED
- Product source `d2af406687d1d95c91ad3b62636865a01425eeca`, hosted CI `38818982835`: all three jobs SUCCESS at exact SHA, including real PostgreSQL Livewire tests, schema rollback/replay and static prototype Chromium tests.
- The MFA-panel WebsiteWorkspace now exposes accessible, tenant-scoped branding fields: public venue name/tagline, allowed theme and font presets, dropdowns listing only currently authorized private media for logo/favicon, save/reload branding draft, and owner/delegated-publisher-only publish.
- Locked loaded company/website IDs and current page Gate checks protect every action; service independently rechecks authenticated verified actor, membership, tenant and approved intact media. Unsaved Livewire form edits cannot be published. Switching website context clears previous company's form. Draft edits do not mutate the published pointer. Livewire tests cover owner approval, editor draft-only role, unsaved publish denial.
- Browser E2E here still covers static prototype, not authenticated Filament. Branding is not wired into public serving; no owner go-live claim. Site media remains private until separately validated publication.
- **NEXT BOUNDED TASK:** add audited rollback/restore for previously published BRANDING snapshots (not unpublished drafts), with expected published-pointer concurrency guard, untouched latest draft, immutable publishing events, and owner-only Livewire history/restore controls and PostgreSQL tests. Then responsive theme preview and safe public media projection (separate release gate).

## 2026-10-15 audited branding snapshot restore backend VERIFIED
- Product `e0a60159e119522613e0e898db4e9c92aeb2c1d3`, hosted CI `38819195503` SUCCESS (all three jobs at exact SHA).
- Added `SiteBrandingService::restorePublishedBranding`: verified authorized publisher, live company-scoped website row lock, expected published-pointer CAS, required prior publication event, typed branding/media revalidation, immutable new restore event and append-only audit. It leaves current draft revision and older history unchanged, and idempotent restores do not duplicate events.
- PostgreSQL tests cover first/second published versions followed by unpublished third draft, restoration, stale pointer denial and never-published draft denial. Source/migrations/rollback/prototype checks all passed.
- **NEXT:** add publisher-only branding version history and restore button to MFA Filament WebsiteWorkspace with reason and stale-pointer protection; Livewire PostgreSQL tests. Then run authenticated Filament browser acceptance, safe media publication, and owner theme preview. Public website and customer booking remain incomplete.

## 2026-10-15 owner branding history restore UI VERIFIED
- SOURCE `078046de7028f1060cfd0651b8da83d26a571a73`, hosted CI `38819428701` SUCCESS: all three jobs exact SHA, PHP syntax, PostgreSQL tests, schema rollback/replay, prototype Playwright browser checks.
- Added company/page-scoped publisher-only approved branding version list and restore buttons in Filament WebsiteWorkspace. Each restore rechecks live tenant/page Gate, loaded company/website IDs, reason and expected published pointer before audited immutable service call. Restoring published branding does NOT mutate current draft.
- PostgreSQL Livewire acceptance covers publishing versions one and two, saving separate third draft, restoring version one while preserving draft three, and single immutable audit/restore event. Delegated editor has no publisher controls.
- No authenticated Playwright against Filament yet; browser suite remains static prototype. No production website public rendering, branding asset release, domain setup or booking persistence.
- **NEXT BOUNDED TASK:** accessible responsive preview of whitelist-driven owner branding presets and draft, with escaped content and NO raw staged image file URLs; regression tests. Then service-side secure published snapshot renderer/variant projection and authenticated Filament browser acceptance. Broader Phase 2 venue configuration then Phase 3 booking follow roadmap.

## 2026-10-15 verified responsive branding PREVIEW (not a public renderer)
- SOURCE `d526d1be8e378527d87bd25bac26438b46fd9784`, hosted CI `38826086694` SUCCESS for exact SHA. All three jobs green; backend 74 passed (327 assertions); PostgreSQL migration rollback/replay and static prototype Chromium checks passed.
- Added `backend/resources/views/filament/pages/partials/branding-preview.blade.php` rendered within MFA-protected WebsiteWorkspace. Live name/tagline/palette/font and logo/favicon selections preview using server-owned four palettes and two font presets, escaped text and private-only image placeholders. Desktop/tablet/360px screen controls reuse existing checked mode.
- Added owner and delegated-editor Livewire tests for preview escape, invalid-preset fallback, screen sizes, no unsaved draft/publish/audit writes, no raw private asset links, and denied publisher controls for editors.
- No authenticated Filament browser E2E: Chromium job still validates static root prototype only. No public site or image URLs are published. Real photos, owner review and release gates remain outstanding.
- **NEXT BOUNDED TASK:** implement a tenant-isolated **service-side published snapshot projection** that joins only company/site/page approved branding and page revisions, rechecks typed content and optimized approved image integrity, and returns no private storage paths/original bytes or draft revisions. Add PostgreSQL tests for draft isolation, cross-company lookup, changed asset denial and unapproved asset denial. Keep route disabled until safe asset materialization and full browser acceptance exist. Then explicitly build secure published WebP-only delivery and authenticated Filament browser test infrastructure.

## 2026-10-15 safe approved website snapshot PROJECTION VERIFIED
- SOURCE `f57d45c213766ed3d5664e74e75782105cb75895`, hosted CI `38826473584` SUCCESS, all three jobs; PostgreSQL backend 77 tests / 347 assertions.
- New `SitePublishedSnapshotService::project(company, website, slug)` demands active company/site, a published branding pointer AND a published page pointer; reads only immutable approved revisions, with no draft fallback. Rechecks strict branding and typed page validators.
- Collects referenced media including branding logo/favicon and nested image blocks. Fails closed for missing/revoked media or tampered WebP content, checks company ownership, private WebP path format, checksum, actual MIME, dimensions/size and safe alt text. Returns only typed content and approved metadata, NO private object keys, original file content or public URLs.
- New PostgreSQL tests verify draft/published isolation, company/site/slug isolation, inactive site filtering, one approved WebP serving descriptor, no private path leakage, modified optimized variant rejection and approval revocation denial.
- **NOT public serving:** The projection is an internal service with no public route or actual asset materialization. Filament authenticated browser E2E and secure publication endpoint are still NOT done.
- **NEXT BOUNDED TASK:** implement a server-only variant delivery service that resolves a referenced image from a verified published page projection, rechecks the current private optimized WebP immediately before reading, and returns sanitized bytes + correct mime/cache digest without exposing originals. Add PostgreSQL tests for cross-tenant requests, unpublished media exclusion, tamper and image swaps. No external route until full security review. Then build guarded public delivery/rendering and real authenticated Filament browser E2E.

## 2026-10-15 server-only optimized published WebP read VERIFIED
- SOURCE `72bb9dc8d31db3634fe1c87bc25bb37865f417ea`, hosted CI `38826775925` SUCCESS at exact SHA, 81 backend tests / 366 assertions plus prototype and Chromium jobs SUCCESS.
- New `SitePublishedWebpService::read(company, website, slug, imageId)` uses verified approved published snapshot projection and an approved company-scoped shared-locked media row, rechecks private variant path, checksum, actual WebP MIME/dimensions and bytes before returning safe in-memory WebP bytes, content type, ETag and length. It never returns original upload contents or private key and never writes to public storage.
- PostgreSQL tests cover optimized bytes, digest/cache metadata, tenant/site/slug exclusion, unrelated approved assets, pending assets and newer unpublished draft media, tampered bytes, and foreign-company private key swaps.
- **No public HTTP endpoint** exists yet; this is a server component and must not be called website delivery or release. No browser acceptance against authenticated Filament, SEO/domain, media cache/operational review or customer enquiry backend.
- **NEXT BOUNDED TASK:** add an internal strict typed HTML renderer from the published-only snapshot and whitelisted theme templates (escaping names, text, CTA URLs, accessibility fallbacks, no raw media URLs or arbitrary HTML). Add real PostgreSQL/Blade tests demonstrating draft isolation and safe rendering. DO NOT expose route until publication and asset delivery gates pass. Then work on authenticated Filament browser acceptance/security and Phase 2 hall business rules.

## 2026-10-16 strict published-only HTML server renderer VERIFIED
- SOURCE `9de44062cd212125f6958c556a6e4ecb3dd91515`, hosted CI `38827218511` SUCCESS exact SHA. Three jobs green, backend 83 tests / 385 assertions, PostgreSQL migrations and rollback/replay passed.
- New internal `SitePublishedHtmlService::render` uses `SitePublishedSnapshotService` approved immutable branding and page snapshots, with server-owned four theme palettes, two font presets, escaped page/branding text and strict CTA links. Responsive desktop 12-column/mobile independent order supported. Image/gallery/branding logo are private-safe placeholders only; no raw file URL, unreviewed image or executable CMS content. Dynamic catalog/enquiry blocks deliberately show nonfunctional release-gate placeholders.
- Real PostgreSQL/Blade tests assert no rendering before both approvals, published-vs-unsaved isolation, tenant mismatch, escaped ampersands and URL queries, theme tokens, column widths and mobile order and no leaked private media paths.
- **NO PUBLIC HTTP ROUTE** connected to this server renderer. Static root prototype is not a deployed website. No functional customer enquiry, booking, finance or Telegram. No authenticated Filament Playwright browser E2E (static root-only browser checks still). Launch is not approved.
- **NEXT BOUNDED TASK:** start Phase 2 persistent tenant/hall-scoped three-program schedule and pricing/grace/buffer rules, database constraints, authorized audited writes, optimistic versioning and PostgreSQL tests. Keep security work planned: authenticated Filament browser acceptance and secure public website/media route release gate, media accessibility/performance and operational identity recovery. Continue owner settings for seating, add-ons and slot pricing; then robust booking concurrency without double bookings.

## 2026-10-16 immutable tenant-scoped hall three-program schema VERIFIED
- SOURCE `974b9c03b4b20347da7d425cc66437979a3374c3`, hosted CI `38827775344` SUCCESS at exact SHA, 86 backend tests / 392 assertions, all three jobs and migration rollback/replay green.
- Added `halls.program_rules_revision` (0 means unconfigured) and append-only `hall_program_rule_revisions` with company+hall composite foreign key, three required morning/noon/evening JSONB objects, ISO-style 3-letter uppercase currency code, unique hall/revision number, changer, reason, immutable PostgreSQL update/delete trigger.
- PostgreSQL tests prove defaults, immutable history, cross-company FK rejection, and a fourth program rejected by database constraints.
- First run `38827588599` FAILED at migration: Laravel `DB::statement` interpreted PostgreSQL JSONB `?` as PDO placeholder. Fixed to `DB::unprepared` in `974b9c03`, exact SHA run passes.
- **NEXT BOUNDED TASK prepared as UNCOMMITTED BLOBS (NOT verified):** TenantAccess.php `8e1d4e019093b930a912045bdb081beab064ae47`; HallProgramRulesService.php `035357cd423436bd0a20ab20509caa712cbeb60b`; HallProgramRulesServiceTest.php original `4785ade1e9df11d7aa3b4be90eb97cedb971df1d`, JSONB-order assertion fix `bed29fec09a325a15bf6610c05a6dc45a2bebb34`. REVIEW against live HEAD before committing. Implements owner/admin-only audited transactional three-program configuration with CAS, validates setup/clean buffers, time and rate limits, staff-scoped read and audit-failure rollback.
- Phase 2 is NOT complete. No venue calendar, date-specific overrides, active holds, booking concurrency, hall pricing settings UI, actual overtime billing, functional public website or finance. Continue authenticated Filament browser acceptance and secure public asset delivery as separate bounded release-gated work.

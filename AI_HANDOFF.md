# Zargul Silver Marquee — concise development handoff (2026-10-24)

Repository `yasirskhan/Zargul-Silver-Marquee`, **only branch `chatgpt/phase0-foundation`**. Single authorized normal-Chat writer. Never Work mode, `main`, a new branch, force-push, `property-platform`, or production activation. Always recheck live HEAD and exact branch CI before writes; publish with expected SHA and `force=false`.

## Current verified baseline

- **Final integrated baseline: `59fb9650eddb57bf0a82bf5c49a706342c03567c`, [run 40138475496](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40138475496) — six jobs SUCCESS.**
- Phase 0: `7ab6ff8`, CI 40020880262.
- Phase 0.5: `63c6b7a`, CI 40050039945.
- Phase 1: `4024399`, CI 40064675256.
- Phase 2: `39a0315`, CI 40072138788.
- Phase 3: `bf288cb`, CI 40075891670.
- Phase 4: `1d4afb3`, CI 40090557799.
- Phase 5: `ef6217b`, CI 40113341018.
- Phase 6: `59beb42`, CI 40119020374.
- Phase 7/7.1–7.3: `5735312`, CI 40120614543.
- Phase 8 technical prep: `cb04416`, CI 40127753617; real pilot **BLOCKED EXTERNAL**.
- Phase 9 first milestone: `59fb965`, CI 40138475496; `docs/PHASE9_CENTRAL_PORTAL.md`.
  - `central/public/staff.php`: default-off, verified-HTTPS-host staff front controller with vendor-only email/password/TOTP login, secure session cookie, CSRF and delegation to the existing central staff portal router.
  - External `CENTRAL_STAFF_KMS_BOOTSTRAP` supplies TOTP decryption/support-reply encryption; no KMS/signing secret in repo.
  - `central/tests/staff_public_portal.php` proves default denial, trusted-host login UI, wrong-host denial, no secret output and fail-closed missing KMS.\n  - `central/tests/staff_portal_journey.php` proves real central PostgreSQL staff login + TOTP, secure cookie/CSRF, home, customer directory, logout and revoked-session denial via the operator HTTP composition.
  - Dashboard/customer directory/license queue/billing/support/audit endpoints are the existing role-scoped portal services; not yet exercised by a deployed authenticated browser.

## External/operational gates (not fabricated)

- Real staging pilot, backup restore, Owner/Manager UAT, operator/security signoff.
- Central DNS/HTTPS, KMS/HSM, database credentials, staff seed accounts, bank/provider settlement.
- Real Telegram/payment credentials, off-site backup/restore, real photography/DNS.
- Phase 12 operational acceptance remains open; Phase 13 not promoted.

## Next executable work

1. Authenticated browser journey for the gated central portal in an isolated test environment (login, dashboard, customer/installation directory, authorized/denied roles, logout).
2. Complete the resumable installation wizard on `InstallationSetup`: owner/MFA, company/venue, halls/addresses/capacities, currency/timezone, programs/pricing, guest brackets, add-ons, branding/maps/footer, approved payment settings, staff permissions, review/complete; preserve installed data and make retries safe.
3. Continue central license/billing/support and client licensing workflows; keep `CENTRAL_API_ENABLED`, `CENTRAL_STAFF_PUBLIC_PORTAL_ENABLED` and all release gates OFF until external approval.

## Rules and gates

- All production release flags remain OFF until operator/security/restore acceptance.
- Preserve immutable audit, tenant isolation, MFA, role checks, financial controls and customer privacy.
- Six required jobs: `prototype-checks`, `central-postgres`, `licensing-postgres`, `laravel-postgres`, `editor-browser-e2e`, `authenticated-panel-e2e`. VERIFIED only when all six on the exact SHA are completed SUCCESS.

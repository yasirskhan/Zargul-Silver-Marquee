# VenueDesk / HAFZATECH Development Efficiency — Owner Approved

## Goal and measurable baseline
Speed up verified fully functioning features **without skipping tests, security, bug fixing, tenancy checks or owner-approved scope**. Recent six-job GitHub Actions CI runs commonly took 5–7 minutes, with repeated full runs for tiny code and documentation changes; improvement must be measured, not promised.
Existing six independent jobs run in parallel: `prototype-checks`, `central-postgres`, `licensing-postgres`, `laravel-postgres`, `editor-browser-e2e`, and `authenticated-panel-e2e`.

## Safe optimization now applied
- Cache Composer download archives in three dependency-installing jobs, **strictly keyed by backend/composer.lock**. `composer install`, `composer validate`, PHP lint, PostgreSQL and actual tests still run on every new SHA. No `vendor/` cache.
- Cache npm download archives and Chromium browser binaries only for Playwright version **1.56.1** in the two E2E jobs. `npm install`, `npx playwright install --with-deps chromium` and all browser tests still execute. Never cache `node_modules/`, mutable databases or secret state.
- Continuity checks enforce this document, the full 2026-10-18 historical handoff archive, issue/bug register and quality policy. No automatic CI cancellation, no jobs removed, no test assertions changed.
- Root `AI_HANDOFF.md` is a concise current operational checkpoint; historical content remains verbatim in `AI_HANDOFF_ARCHIVE_2026-10-18.md` and older 2026-10-17 archive.

## Process after the optimization
Use a bounded vertical customer-facing feature as the milestone: UI + backend + authorizations + durable audit + error/retry/rollback + realistic negative/concurrency/tenant/finance tests. Run fast syntax/lint/targeted tests on the development environment before publishing; then require complete six-job hosted CI on exact committed SHA. Any failing job: inspect logs, reproduce, fix the root cause, add regression and rerun full hosted CI. Keep affected release flag OFF. Record real bugs in `docs/ISSUE_AND_BUG_REGISTER.md`, not merely status messages.
Preserve feature IDs and acceptance from `docs/FEATURE_REGISTER.csv`. A helper or schema counts as verified foundation, not end-to-end production-ready feature.

## Don't introduce new quality risks for speed
No removed/skipped/weakened security, PostgreSQL or authenticated E2E tests. No force pushes or concurrent writes without expected SHA. No unverified production activation, mock bank settlements, fabricated vendor contracts, KMS private keys, or release flag enablement. Do not cancel exact CI runs required for verification. **Any speed gain must be evidenced by later CI duration/cache hits and completed features per week.**

## Next milestone
Finish real protected HAFZATECH license paid issuance/renewal/verify and client UX, accounting for independent staff MFA, payment settlement, signer custody and no fake completion claims; then currency/time zone wizard, public booking/payment, inventory returns and remaining approved modules with full release gates.

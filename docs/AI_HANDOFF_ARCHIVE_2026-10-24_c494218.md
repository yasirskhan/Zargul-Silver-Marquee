# Zargul Silver Marquee — concise development handoff (2026-10-24)

Repository `yasirskhan/Zargul-Silver-Marquee`, **only branch `chatgpt/phase0-foundation`**. Single authorized normal-Chat writer. Never Work mode, `main`, a new branch, force-push, `property-platform`, or production activation. Always recheck live HEAD and exact branch CI before writes; publish with expected SHA and `force=false`.

## Current verified baseline

- **Final integrated baseline: `4024399e8ca6cea68b680fb72d2e08756225d822`, [run 40064675256](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40064675256) — six jobs SUCCESS.**
- Phase 0: accepted at `7ab6ff8`, CI 40020880262; `docs/PHASE0_ACCEPTANCE.md`.
- Phase 0.5: accepted at `63c6b7a`, CI 40050039945; `docs/PHASE0_5_ACCEPTANCE.md`; final media/layout integration at `df1e5b8`.
- Phase 1: technically accepted at `4024399`, CI 40064675256; `docs/PHASE1_ACCEPTANCE.md`.
  - AUTH-001: MFA/recovery/email verification + authenticated browser suite; `venuedesk:auth-readiness` command/tests.
  - TEN-001: cross-company and assigned-hall isolation matrix plus service/policy regressions.
  - AUD-001: encrypted owner export with 31-day window, private ciphertext download, `audit.export_downloaded`, and `venuedesk:verify-audit-export` SHA-256 recovery verification.
  - CMS-008: company edit/publish/media authorization and audit.
  - UX-001: role-scoped owner/manager/operations overview and quick-search regressions.
  - PLAT-005/006: Laravel 13.36.0, Filament 5.10.2, PHP 8.3, reproducible composer.lock CI.

## External/operational gates (not fabricated)

- Real SMTP/provider credentials and delivery verification.
- Approved off-site audit archive storage, retention, key custody and restore drill.
- Real Owner media uploads, staging, operator/security/backup acceptance and production release approval.
- Phase 12 operational acceptance remains separately open; Phase 13 is not promoted.

## Next executable phase

Phase 2 — Owner configuration: sessions, grace/overtime/turnover, capacity/seating, catering/add-ons and versioned pricing. Reconcile `HALL-001`, `SLOT-001`, `SLOT-002`, `ADD-001` against actual code/tests; implement only the genuinely missing workflow/interface/browser acceptance, then verify all six jobs and record Phase 2 acceptance before Phase 3.

## Rules and gates

- All production release flags remain OFF until operator/security/restore acceptance.
- Preserve draft/publish separation, immutable revisions/audit, company/hall isolation and role checks.
- Six required jobs: `prototype-checks`, `central-postgres`, `licensing-postgres`, `laravel-postgres`, `editor-browser-e2e`, `authenticated-panel-e2e`. A result is VERIFIED only when all six on the exact SHA are completed SUCCESS.

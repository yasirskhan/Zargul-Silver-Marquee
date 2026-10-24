# Zargul Silver Marquee — concise development handoff (2026-10-24)

Repository `yasirskhan/Zargul-Silver-Marquee`, **only branch `chatgpt/phase0-foundation`**. Single authorized normal-Chat writer. Never Work mode, `main`, a new branch, force-push, `property-platform`, or production activation. Always recheck live HEAD and exact branch CI before writes; publish with expected SHA and `force=false`.

## Current verified baseline

- **Final integrated baseline: `df1e5b8fb4147d5e8993549cdd0a9352875074f7`, [run 40058788632](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40058788632) — six jobs SUCCESS.** This includes the Phase 0/0.5 code and acceptance documents plus the Phase 1 isolation matrix.
- Phase 0 foundation: `7ab6ff8b573a45f99eec19a81825a1d79ad762c7`, [run 40020880262](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40020880262) — six jobs SUCCESS. Acceptance: `docs/PHASE0_ACCEPTANCE.md`.
- Phase 0.5 CMS editor: `63c6b7a8d2a6180c51e74f5ded2cbeb3a6a192ea`, [run 40050039945](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40050039945) — six jobs SUCCESS. Acceptance: `docs/PHASE0_5_ACCEPTANCE.md`.
- Phase 0.5 work completed:
  - authenticated private media thumbnails via `/site-media/{uuid}` with company edit/publish authorization, active/verified user checks and generated WebP;
  - real logo/photo rendering in branding and draft previews; image blocks preserve bounded `focalX`, `focalY`, `cropAspect` in draft revisions;
  - safe replacement as a **new media ID** (published revisions keep old bytes) and retirement refused while a published revision references the asset;
  - authenticated Owner browser journey covering upload/approve/thumbnail/crop plus two-column width, desktop reorder and independent phone order, save and reload;
  - backend regressions for private route access, foreign-company denial, replacement/retirement and crop/focal persistence.
- Phase 1 first batch: `backend/tests/Feature/Phase1TenantIsolationTest.php` proves the cross-company and assigned-hall isolation matrix for website edit/publish, hall configure/view and manager/operations scope at the final baseline.
- Phase 12 remains **OPEN** on external operational acceptance. Do not promote Phase 13 or enable production inventory/finance flags. Its existing handoff/report files remain authoritative for Phase 12.

## Next executable requirement

Continue sequentially with the remaining **Phase 1** requirements in `docs/FEATURE_REGISTER.csv` (AUTH-001, TEN-001 full service coverage, AUD-001, CMS-008, UX-001, PLAT-005/006). Reconcile only against executable evidence; do not repeat Phase 0/0.5 or Phase 12 work already verified.

## Rules and gates

- All production release flags remain OFF until operator/security/restore acceptance.
- Use synthetic test media only; real Owner uploads/staging acceptance remain external.
- Preserve draft/publish separation, immutable revisions/audit, company isolation and role checks.
- Six required jobs: `prototype-checks`, `central-postgres`, `licensing-postgres`, `laravel-postgres`, `editor-browser-e2e`, `authenticated-panel-e2e`. A result is VERIFIED only when all six on the exact SHA are completed SUCCESS.

# Zargul Silver Marquee — concise development handoff (2026-10-24)

Repository `yasirskhan/Zargul-Silver-Marquee`, **only branch `chatgpt/phase0-foundation`**. Single authorized normal-Chat writer. Never Work mode, `main`, a new branch, force-push, `property-platform`, or production activation. Always recheck live HEAD and exact branch CI before writes; publish with expected SHA and `force=false`.

## Current verified baseline

- **Final integrated baseline: `cb04416a264ee73dd003af64e2da1e655bf7ea0e`, [run 40127753617](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40127753617) — six jobs SUCCESS.**
- Phase 0: `7ab6ff8`, CI 40020880262; `docs/PHASE0_ACCEPTANCE.md`.
- Phase 0.5: `63c6b7a`, CI 40050039945; `docs/PHASE0_5_ACCEPTANCE.md`.
- Phase 1: `4024399`, CI 40064675256; `docs/PHASE1_ACCEPTANCE.md`.
- Phase 2: `39a0315`, CI 40072138788; `docs/PHASE2_ACCEPTANCE.md`.
- Phase 3: `bf288cb`, CI 40075891670; `docs/PHASE3_ACCEPTANCE.md`.
- Phase 4: `1d4afb3`, CI 40090557799; `docs/PHASE4_ACCEPTANCE.md`.
- Phase 5: `ef6217b`, CI 40113341018; `docs/PHASE5_ACCEPTANCE.md`.
- Phase 6: `59beb42`, CI 40119020374; `docs/PHASE6_ACCEPTANCE.md`.
- Phase 7/7.1/7.2/7.3: `5735312`, CI 40120614543; `docs/PHASE7_ACCEPTANCE.md`.
- Phase 8: technical prep `cb04416`, CI 40127753617; `docs/PHASE8_PILOT_READINESS.md`.
  - `venuedesk:pilot-readiness [--json]` fails closed on production, any release flag ON, non-PostgreSQL/missing tables, unwritable storage or no active verified owner; reports role/MFA/mailer warnings.
  - `PilotReadinessCommandTest` covers pass and flag-on failure.
  - Real staging, backup restore, Owner/Manager UAT, staff attendance, payment and operator signoff remain **BLOCKED EXTERNAL**.

## External/operational gates (not fabricated)

- Controlled Phase 8 pilot with real staging, participants, paired backup/restore and independent operator/security signoff.
- Real Telegram/payment/bank credentials, off-site backup/restore, real photography/DNS, staging/security/operator acceptance.
- Phase 12 operational acceptance remains open; Phase 13 not promoted.

## Next executable phase

Phase 9/9.1/9.2 — HAFZATECH Central Control Center and client licensing portal. Reuse `central/src` foundations; do not recreate. Prioritize usable central staff/customer/licensing/support interfaces and client installation/licensing workflows independent of external DNS/KMS/bank credentials; keep `CENTRAL_API_ENABLED` and all release gates OFF; verify six jobs, record acceptance, then continue Phase 10.

## Rules and gates

- All production release flags remain OFF until operator/security/restore acceptance.
- Preserve immutable revisions/audit, company/hall isolation, balanced ledger, role checks, enquiry consent and customer privacy.
- Six required jobs: `prototype-checks`, `central-postgres`, `licensing-postgres`, `laravel-postgres`, `editor-browser-e2e`, `authenticated-panel-e2e`. VERIFIED only when all six on the exact SHA are completed SUCCESS.

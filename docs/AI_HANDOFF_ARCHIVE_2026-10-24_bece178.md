# Zargul Silver Marquee — concise development handoff (2026-10-24)

Repository `yasirskhan/Zargul-Silver-Marquee`, **only branch `chatgpt/phase0-foundation`**. Single authorized normal-Chat writer. Never Work mode, `main`, a new branch, force-push, `property-platform`, or production activation. Always recheck live HEAD and exact branch CI before writes; publish with expected SHA and `force=false`.

## Current verified baseline

- **Final integrated baseline: `39a0315023efc01fe1fe5c9542939363ed0adfa6`, [run 40072138788](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40072138788) — six jobs SUCCESS.**
- Phase 0: `7ab6ff8`, CI 40020880262; `docs/PHASE0_ACCEPTANCE.md`.
- Phase 0.5: `63c6b7a`, CI 40050039945; `docs/PHASE0_5_ACCEPTANCE.md`; final integration `df1e5b8`.
- Phase 1: `4024399`, CI 40064675256; `docs/PHASE1_ACCEPTANCE.md`.
- Phase 2: `39a0315`, CI 40072138788; `docs/PHASE2_ACCEPTANCE.md`.
  - Owner authenticated browser saves morning rental, seating capacity/unit prices and a Stage lighting add-on, reloads and verifies persistence.
  - Operations authenticated mobile browser proves assigned-hall read-only programs/seating/catalog and nonbinding overtime preview.
  - Manager write denial/read-only, stale CAS, revoked/unverified and foreign-company denials are PostgreSQL/Livewire-tested.
  - Versioned hall rules/seating/add-on catalogs preserve approved terms; booking/quote monetary snapshots are Phase 3/4.

## External/operational gates (not fabricated)

- Real SMTP/provider credentials and delivery verification.
- Approved off-site audit archive storage, retention, key custody and restore drill.
- Real Owner media, staging, operator/security/backup acceptance and production release approval.
- Phase 12 operational acceptance remains open; Phase 13 not promoted.

## Next executable phase

Phase 3 — Bookings and Calendar. Reconcile the existing booking draft/reservation, slot-claim, proposal and calendar components; implement only genuinely missing workflow/interface/browser acceptance, verify six jobs, record `docs/PHASE3_ACCEPTANCE.md`, then continue Phase 4 without another prompt.

## Rules and gates

- All production release flags remain OFF until operator/security/restore acceptance.
- Preserve draft/publish separation, immutable revisions/audit, company/hall isolation, slot/claim safeguards and role checks.
- Six required jobs: `prototype-checks`, `central-postgres`, `licensing-postgres`, `laravel-postgres`, `editor-browser-e2e`, `authenticated-panel-e2e`. VERIFIED only when all six on the exact SHA are completed SUCCESS.

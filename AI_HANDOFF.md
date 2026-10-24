# Zargul Silver Marquee — concise development handoff (2026-10-24)

Repository `yasirskhan/Zargul-Silver-Marquee`, **only branch `chatgpt/phase0-foundation`**. Single authorized normal-Chat writer. Never Work mode, `main`, a new branch, force-push, `property-platform`, or production activation. Always recheck live HEAD and exact branch CI before writes; publish with expected SHA and `force=false`.

## Current verified baseline

- **Final integrated baseline: `bf288cbaef6297dc4d90c2fab5a928ef7b2c8d91`, [run 40075891670](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40075891670) — six jobs SUCCESS.**
- Phase 0: `7ab6ff8`, CI 40020880262; `docs/PHASE0_ACCEPTANCE.md`.
- Phase 0.5: `63c6b7a`, CI 40050039945; `docs/PHASE0_5_ACCEPTANCE.md`.
- Phase 1: `4024399`, CI 40064675256; `docs/PHASE1_ACCEPTANCE.md`.
- Phase 2: `39a0315`, CI 40072138788; `docs/PHASE2_ACCEPTANCE.md`.
- Phase 3: `bf288cb`, CI 40075891670; `docs/PHASE3_ACCEPTANCE.md`.
  - Internal calendar, unique hall/date/program claims, hold/confirm/reschedule/cancel and cross-hall transfer are PostgreSQL/Livewire tested.
  - Authenticated owner browser creates an encrypted customer and unconfirmed booking request with no claim, invoice, deposit or payment.
  - Operations mobile browser remains assigned-hall read-only.
  - `VENUE_BOOKING_CONFIRMATION_ENABLED` remains default OFF; full browser hold/confirm is not claimed.

## External/operational gates (not fabricated)

- Operator/staging approval to enable live booking confirmation.
- Real customer data/public intake, invoices, deposits, payments and notifications (later phases).
- Real SMTP/provider delivery, off-site audit backup/restore, real Owner media and production release approval.
- Phase 12 operational acceptance remains open; Phase 13 not promoted.

## Next executable phase

Phase 4 — Finance: quotes, invoices, receipts/PDF, deposits and controlled posting. Reconcile existing finance services/tests; implement only genuinely missing workflow/UI/authorization/browser acceptance; keep payment and production gates OFF; verify six jobs, record `docs/PHASE4_ACCEPTANCE.md`, then continue Phase 5 without another prompt.

## Rules and gates

- All production release flags remain OFF until operator/security/restore acceptance.
- Preserve immutable revisions/audit, company/hall isolation, slot claims, role checks and customer privacy.
- Six required jobs: `prototype-checks`, `central-postgres`, `licensing-postgres`, `laravel-postgres`, `editor-browser-e2e`, `authenticated-panel-e2e`. VERIFIED only when all six on the exact SHA are completed SUCCESS.

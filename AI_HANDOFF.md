# Zargul Silver Marquee — concise development handoff (2026-10-24)

Repository `yasirskhan/Zargul-Silver-Marquee`, **only branch `chatgpt/phase0-foundation`**. Single authorized normal-Chat writer. Never Work mode, `main`, a new branch, force-push, `property-platform`, or production activation. Always recheck live HEAD and exact branch CI before writes; publish with expected SHA and `force=false`.

## Current verified baseline

- **Final integrated baseline: `59beb429586ba76369e50ca34e41eee18f622dbc`, [run 40119020374](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40119020374) — six jobs SUCCESS.**
- Phase 0: `7ab6ff8`, CI 40020880262; `docs/PHASE0_ACCEPTANCE.md`.
- Phase 0.5: `63c6b7a`, CI 40050039945; `docs/PHASE0_5_ACCEPTANCE.md`.
- Phase 1: `4024399`, CI 40064675256; `docs/PHASE1_ACCEPTANCE.md`.
- Phase 2: `39a0315`, CI 40072138788; `docs/PHASE2_ACCEPTANCE.md`.
- Phase 3: `bf288cb`, CI 40075891670; `docs/PHASE3_ACCEPTANCE.md`.
- Phase 4: `1d4afb3`, CI 40090557799; `docs/PHASE4_ACCEPTANCE.md`.
- Phase 5: `ef6217b`, CI 40113341018; `docs/PHASE5_ACCEPTANCE.md`.
- Phase 6: `59beb42`, CI 40119020374; `docs/PHASE6_ACCEPTANCE.md`.
  - Public rendered site remains host-bound and operator-gated.
  - New consented public enquiry form persists company/website-scoped enquiries with idempotent request UUID, IP hash, immutable audit, honeypot, CSRF and 5/minute throttle; no booking/hold/invoice/payment.
  - New owner/admin Enquiries page lists private contact, hall/date/program/guests/message and records audited status/reason triage; manager denied.
  - Active hall names/capacities are published for the enquiry selector; no private hall/customer/financial data.
  - Public-site launch, real photography, DNS/indexing approval and public browser UAT remain external.

## External/operational gates (not fabricated)

- `VENUE_PUBLIC_SITE_ENABLED` remains OFF; real DNS/host, real venue photography, owner/public UAT and indexing approval.
- Phase 10 advanced themes, precise guest pricing brackets, maps/contact presentation and verified-payment 5% holds.
- Telegram/payment provider/bank credentials, off-site backup/restore, staging/security/operator acceptance.
- Phase 12 operational acceptance remains open; Phase 13 not promoted.

## Next executable phase

Phase 7 — Event operations and staff workflows. Reconcile existing event operations, tasks, staff assignment, attendance, closeout and dashboard UI; implement only genuinely missing backend/UI/browser acceptance; keep production flags OFF; verify six jobs, record `docs/PHASE7_ACCEPTANCE.md`, then continue Phase 8 without another prompt.

## Rules and gates

- All production release flags remain OFF until operator/security/restore acceptance.
- Preserve immutable revisions/audit, company/hall isolation, balanced ledger, role checks, customer privacy and enquiry consent.
- Six required jobs: `prototype-checks`, `central-postgres`, `licensing-postgres`, `laravel-postgres`, `editor-browser-e2e`, `authenticated-panel-e2e`. VERIFIED only when all six on the exact SHA are completed SUCCESS.

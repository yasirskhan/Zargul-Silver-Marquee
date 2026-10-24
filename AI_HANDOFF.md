# Zargul Silver Marquee — concise development handoff (2026-10-24)

Repository `yasirskhan/Zargul-Silver-Marquee`, **only branch `chatgpt/phase0-foundation`**. Single authorized normal-Chat writer. Never Work mode, `main`, a new branch, force-push, `property-platform`, or production activation. Always recheck live HEAD and exact branch CI before writes; publish with expected SHA and `force=false`.

## Current verified baseline

- **Final integrated baseline: `ef6217b8e8097609131385ec83a976eacb16fd56`, [run 40113341018](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40113341018) — six jobs SUCCESS.**
- Phase 0: `7ab6ff8`, CI 40020880262; `docs/PHASE0_ACCEPTANCE.md`.
- Phase 0.5: `63c6b7a`, CI 40050039945; `docs/PHASE0_5_ACCEPTANCE.md`.
- Phase 1: `4024399`, CI 40064675256; `docs/PHASE1_ACCEPTANCE.md`.
- Phase 2: `39a0315`, CI 40072138788; `docs/PHASE2_ACCEPTANCE.md`.
- Phase 3: `bf288cb`, CI 40075891670; `docs/PHASE3_ACCEPTANCE.md`.
- Phase 4: `1d4afb3`, CI 40090557799; `docs/PHASE4_ACCEPTANCE.md`.
- Phase 5: `ef6217b`, CI 40113341018; `docs/PHASE5_ACCEPTANCE.md`.
  - Owner browser reviews and approves a pending non-self expense request; approved count updates; no financial application.
  - Approval Center categories, linked proposal rules, exact-reference/status filters, immutable decisions, no self-approval and tenant/role isolation are PostgreSQL/Livewire-tested.
  - Telegram pairing/webhook/dedup/revocation and owner login alert are backend/UI tested; live credentials are absent and the browser asserts the explicit unconfigured state.
  - Emergency delegation to active admins with category/amount ceiling, expiry and revocation is tested.

## External/operational gates (not fabricated)

- Real Telegram bot/chat credentials and operator pilot.
- Real discount/refund/waiver/free-service application to quotes/invoices/payments.
- Payment-provider/bank settlement, real customer data, SMTP provider, off-site backup/restore, staging/security/operator acceptance.
- Phase 12 operational acceptance remains open; Phase 13 not promoted.

## Next executable phase

Phase 6 — Public website and customer-facing experience. Reconcile the existing CMS/website/public routes and templates; deliver the complete responsive public site UI and CMS-backed content/navigation; implement only genuinely missing workflow/UI/browser acceptance; keep booking/payment gates OFF; verify six jobs, record `docs/PHASE6_ACCEPTANCE.md`, then continue Phase 7 without another prompt.

## Rules and gates

- All production release flags remain OFF until operator/security/restore acceptance.
- Preserve immutable revisions/audit, company/hall isolation, balanced ledger, role checks and customer privacy.
- Six required jobs: `prototype-checks`, `central-postgres`, `licensing-postgres`, `laravel-postgres`, `editor-browser-e2e`, `authenticated-panel-e2e`. VERIFIED only when all six on the exact SHA are completed SUCCESS.

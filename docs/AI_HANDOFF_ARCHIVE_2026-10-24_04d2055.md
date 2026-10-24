# Zargul Silver Marquee — concise development handoff (2026-10-24)

Repository `yasirskhan/Zargul-Silver-Marquee`, **only branch `chatgpt/phase0-foundation`**. Single authorized normal-Chat writer. Never Work mode, `main`, a new branch, force-push, `property-platform`, or production activation. Always recheck live HEAD and exact branch CI before writes; publish with expected SHA and `force=false`.

## Current verified baseline

- **Final integrated baseline: `1d4afb3c783322d32c716c27b72cca3031fde145`, [run 40090557799](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40090557799) — six jobs SUCCESS.**
- Phase 0: `7ab6ff8`, CI 40020880262; `docs/PHASE0_ACCEPTANCE.md`.
- Phase 0.5: `63c6b7a`, CI 40050039945; `docs/PHASE0_5_ACCEPTANCE.md`.
- Phase 1: `4024399`, CI 40064675256; `docs/PHASE1_ACCEPTANCE.md`.
- Phase 2: `39a0315`, CI 40072138788; `docs/PHASE2_ACCEPTANCE.md`.
- Phase 3: `bf288cb`, CI 40075891670; `docs/PHASE3_ACCEPTANCE.md`.
- Phase 4: `1d4afb3`, CI 40090557799; `docs/PHASE4_ACCEPTANCE.md`.
  - Immutable confirmed-reservation quote snapshots, gated numbered quote/invoice issuance, internal branded PDFs, deposits/partial payments, balanced journals, refunds/reversals and cash closeout/reconciliation.
  - New authenticated owner browser journey posts PKR 100000 opening cash, freezes counted 99975 and shows variance -25.
  - Finance/payment/quote/invoice/refund gates remain default OFF; CI enables only controlled posting for synthetic cash closeout.

## External/operational gates (not fabricated)

- Real payment-provider/bank credentials, settlement, customer charges and statements.
- Operator finance/currency/tax approval and production release.
- Real SMTP provider, off-site audit backup/restore, real Owner media, staging/security/restore acceptance.
- Phase 12 operational acceptance remains open; Phase 13 not promoted.

## Next executable phase

Phase 5 — Approvals and Telegram integration. Reconcile ApprovalCenter, ApprovalWorkflowService, Telegram webhook and existing tests; implement only genuinely missing workflow/UI/authorization/browser acceptance; keep production flags OFF; verify six jobs, record `docs/PHASE5_ACCEPTANCE.md`, then continue Phase 6 without another prompt.

## Rules and gates

- All production release flags remain OFF until operator/security/restore acceptance.
- Preserve immutable revisions/audit, company/hall isolation, balanced ledger, role checks and customer privacy.
- Six required jobs: `prototype-checks`, `central-postgres`, `licensing-postgres`, `laravel-postgres`, `editor-browser-e2e`, `authenticated-panel-e2e`. VERIFIED only when all six on the exact SHA are completed SUCCESS.

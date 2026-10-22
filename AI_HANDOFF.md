# Zargul Silver Marquee | Concise current development handoff (2026-10-22 UTC)

Repository: yasirskhan/Zargul-Silver-Marquee; ONLY branch chatgpt/phase0-foundation. Normal Chat only; no main, new branch, force-push, property-platform or production activation. Maintain one active writer. Before ANY write recheck live HEAD and six-job status; use expected_sha with force=false.

## Read first
Root AGENTS.md, this entire handoff, docs/SESSION_CONTROL.md, docs/ROADMAP.md, docs/INVENTORY_IMPLEMENTATION_PLAN.md, docs/PHASE12_FINANCIAL_CLAIM_SCOPE.md, docs/decisions/ADR-0008-external-in-partner-loss-obligation.md, docs/FEATURE_REGISTER.csv, docs/ISSUE_AND_BUG_REGISTER.md, actual source/tests. Previous handoff archived verbatim as docs/AI_HANDOFF_ARCHIVE_2026-10-22_34937ce.md.

## Verified exact checkpoints
- VERIFIED `40ab4ce65138a07621eedba58e3a6302ed9457a5` / [run 39737410547](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39737410547) ALL SIX SUCCESS. Gated balanced ledger for a verified external-in partner payment: only when `finance_controls.posting_enabled` is true, verification posts entry type `inventory_partner_payment` with debit `inventory_partner_loss` and credit `cash_on_hand`/`bank_clearing`; a different owner/admin can post an immutable `reversal` that swaps the lines. No cash mutation, settlement or loan closure. Historical red `8350424` remains historical (entry_type varchar(24) too narrow), fixed at `40ab4ce`.
- VERIFIED `64aa7d6` / [run 39734053941](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39734053941) ALL SIX SUCCESS: Owner payment-evidence record/independent-verification UI.
- VERIFIED `d6c9157` / [run 39732210760](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39732210760) ALL SIX SUCCESS: payment-evidence backend (approved obligation only, currency/amount caps, private proof, separate review).
- VERIFIED `45d9eb7` / [run 39729151047](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39729151047) ALL SIX SUCCESS: Owner obligation proposal/review UI.
- VERIFIED `c872af4` / [run 39727336870](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39727336870) ALL SIX SUCCESS: ADR-0008 and obligation backend.
- VERIFIED `39f28c9` / [run 39725278812](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39725278812) ALL SIX SUCCESS: partner-confirmed receipt clearance.
- VERIFIED `641100b` / [run 39722040547](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39722040547) and `9b4db00` / [run 39718936025](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39718936025) ALL SIX SUCCESS: partner short/damaged evidence and per-loan reconciliation.
- Historical red `935b7cc` remains historical (wrong decision-table lookup), fixed at `d6c9157`.

## Open Phase 12 requirements and next task
Phase 12 OPEN: external-in non-clean closure readiness (physical zero, verified financial position, numbered immutable PDF); Owner/Admin reversal UI for the ledger; INTERNAL abnormal financial obligation, post-return physical amendment/reversal and numbered final certificate; multi-loan concurrency, storage failure/restore/security, full owner/manager/operator acceptance and release certification.
- Next executable: add the external-in non-clean closure readiness and numbered final certificate only when physical custody and the verified financial position are both zero, plus the Owner reversal UI. Never close from evidence or a proposal; never infer a price/tax; never retire unreturned stock; keep every production inventory/finance switch OFF.

## Gates and blockers
All production inventory/finance flags remain OFF. EXTERNAL BLOCKED: real partner signatures/admissions, bank/cash settlement verification, provider credentials, staged operator acceptance, backup/restore/security signoff and explicit production release approval. Phase 13 not started because Phase 12 acceptance is incomplete. Do not fabricate evidence, receipts, settlements or operator approvals.

# Zargul Silver Marquee | Concise current development handoff (2026-10-22 UTC)

Repository: yasirskhan/Zargul-Silver-Marquee; ONLY branch chatgpt/phase0-foundation. Normal Chat only; no main, new branch, force-push, property-platform or production activation. Maintain one active writer. Before ANY write recheck live HEAD and six-job status; use expected_sha with force=false.

## Read first
Root AGENTS.md, this entire handoff, docs/SESSION_CONTROL.md, docs/ROADMAP.md, docs/INVENTORY_IMPLEMENTATION_PLAN.md, docs/PHASE12_FINANCIAL_CLAIM_SCOPE.md, docs/decisions/ADR-0008-external-in-partner-loss-obligation.md, docs/FEATURE_REGISTER.csv, docs/ISSUE_AND_BUG_REGISTER.md, actual source/tests. Previous handoff archived verbatim as docs/AI_HANDOFF_ARCHIVE_2026-10-22_45d9eb7.md.

## Verified exact checkpoints
- VERIFIED `d6c9157510a966d49792c11e52f607b4d4045bbb` / [run 39732210760](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39732210760) ALL SIX SUCCESS. Default-OFF EXTERNAL_IN partner payment evidence: only an independently approved obligation; amount capped by the approved amount and currency-bound; cash/bank method, payment reference, paid date and private PDF/photo SHA256+size; a different current owner/admin independently verifies or rejects. Immutable evidence/review; no journal, cash mutation, settlement, waiver, stock movement or closure. Owner UI, balanced ledger posting and reversal remain OPEN. Historical red `935b7cc` remains historical: settlement queried the discrepancy-decision table instead of the approved obligation decision; fixed at `d6c9157`.
- VERIFIED `45d9eb7` / [run 39729151047](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39729151047) ALL SIX SUCCESS: Owner Hall Inventory proposal/independent review UI for the provisional obligation.
- VERIFIED `c872af4` / [run 39727336870](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39727336870) ALL SIX SUCCESS: ADR-0008 plus obligation backend foundation.
- VERIFIED `39f28c9` / [run 39725278812](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39725278812) ALL SIX SUCCESS: partner-confirmed receipt clearance.
- VERIFIED `641100b` / [run 39722040547](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39722040547) ALL SIX SUCCESS: per-loan partner short/damaged reconciliation.
- VERIFIED `9b4db00` / [run 39718936025](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39718936025) ALL SIX SUCCESS: immutable partner-signed short/damaged evidence.

## Open Phase 12 requirements and next task
Phase 12 OPEN: Owner UI for the new payment evidence/review; balanced finance journal posting for the verified partner payment and an immutable reversal behind the existing finance release gate; external-in non-clean closure; INTERNAL abnormal financial obligation, post-return physical amendment/reversal and numbered final certificate; multi-loan concurrency, storage failure/restore/security, full owner/manager/operator acceptance and release certification.
- Next executable: add the Owner payment-evidence UI, then the separately gated balanced ledger posting/reversal using the approved chart of accounts. Never mark the obligation settled without an independently verified payment and a balanced immutable journal; never infer a price/tax; never retire unreturned stock or close a loan from evidence alone.

## Gates and blockers
All production inventory/finance flags remain OFF. EXTERNAL BLOCKED: real partner signatures/admissions, bank/cash settlement verification, provider credentials, staged operator acceptance, backup/restore/security signoff and explicit production release approval. Phase 13 not started because Phase 12 acceptance is incomplete. Do not fabricate evidence, receipts, settlements or operator approvals.

# Zargul Silver Marquee | Concise current development handoff (2026-10-22 UTC)

Repository: yasirskhan/Zargul-Silver-Marquee; ONLY branch chatgpt/phase0-foundation. Normal Chat only; no main, new branch, force-push, property-platform or production activation. Maintain one active writer. Before ANY write recheck live HEAD and six-job status; use expected_sha with force=false.

## Read first
Root AGENTS.md, this entire handoff, docs/SESSION_CONTROL.md, docs/ROADMAP.md, docs/INVENTORY_IMPLEMENTATION_PLAN.md, docs/PHASE12_FINANCIAL_CLAIM_SCOPE.md, docs/FEATURE_REGISTER.csv, docs/ISSUE_AND_BUG_REGISTER.md, actual source/tests. Previous handoff archived verbatim as docs/AI_HANDOFF_ARCHIVE_2026-10-22_641100b.md.

## Verified exact checkpoints
- VERIFIED `39f28c99e86af9271054e5bf6fc2269decfd00cc` / [run 39725278812](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39725278812) ALL SIX SUCCESS. Default-OFF EXTERNAL_IN partner receipt clearance: after an independent substantiated discrepancy, a different current borrowing-hall owner/admin clears exactly the partner-received quantities from `borrowed_transit` through immutable `confirm_borrowed_return` movements; missing units remain in transit. SQL guard binds the exact discrepancy, decision, item, received quantity and movement. Replay/conflicting identifier denied; no owned stock, liability, journal, payment, write-off or closure. Read model subtracts cleared units and keeps `allPhysicalReturnsConfirmed=false` for damage/missing.
- VERIFIED `641100be24955a3d17cd92086261e23d51d3ec4c` / [run 39722040547](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39722040547) ALL SIX SUCCESS: read-only per-loan partner short/damaged reconciliation (pending/substantiated counts; rejected excluded).
- VERIFIED `9b4db002e357738e506740173ff35f167c928faa` / [run 39718936025](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39718936025) ALL SIX SUCCESS: immutable partner-signed short/damaged evidence, exact caps, private SHA256+size proof, separate owner/admin decision and Owner UI.
- Prior verified: `1915e11` run39718067682 six; `e6b7f47` run39716255948 six; `383d7650` run39717167809 six; `2758e665` run39711783683 six. Historical red runs remain historical (the first `c21f91b` clearance attempt failed only on a wrong expected outstanding total and migration rollback order; corrected at `39f28c9`).

## Open Phase 12 requirements and next task
Phase 12 OPEN: independently authorized EXTERNAL_IN financial obligation/settlement and non-clean closure; INTERNAL abnormal independent financial obligation, post-return physical amendment/reversal and numbered final abnormal certificate; multi-loan concurrency, storage failure/restore/security, full owner/manager/operator acceptance and release certification.
- Next executable: write the explicit finance ADR for a possible external-in payable direction, then implement the separately authorized obligation and verified cash/bank settlement path, or the INTERNAL abnormal financial path. Never treat testimony, a confirmed finding or a partner shortfall as cash or an approved liability; never retire unreturned stock. Add the corresponding numbered non-clean closure and exact CI.

## Gates and blockers
All production inventory/finance flags remain OFF. EXTERNAL BLOCKED: real partner signatures/admissions, bank/cash settlement verification, provider credentials, staged operator acceptance, backup/restore/security signoff and explicit production release approval. Phase 13 not started because Phase 12 acceptance is incomplete. Do not fabricate evidence, receipts, settlements or operator approvals.

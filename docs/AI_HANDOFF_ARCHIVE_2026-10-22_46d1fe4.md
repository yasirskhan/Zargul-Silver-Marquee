# Zargul Silver Marquee | Concise current development handoff (2026-10-22 UTC)

Repository: yasirskhan/Zargul-Silver-Marquee; ONLY branch chatgpt/phase0-foundation. Normal Chat only; no main, new branch, force-push, property-platform or production activation. Maintain one active writer. Before ANY write recheck live HEAD and six-job status; use expected_sha with force=false.

## Read first
Root AGENTS.md, this entire handoff, docs/SESSION_CONTROL.md, docs/ROADMAP.md, docs/INVENTORY_IMPLEMENTATION_PLAN.md, docs/PHASE12_FINANCIAL_CLAIM_SCOPE.md, docs/PHASE12_ACCEPTANCE_STATUS.md, docs/decisions/ADR-0008-external-in-partner-loss-obligation.md, docs/decisions/ADR-0009-internal-abnormal-settlement-accounting.md, docs/FEATURE_REGISTER.csv, docs/ISSUE_AND_BUG_REGISTER.md, actual source/tests. Previous handoff archived verbatim as docs/AI_HANDOFF_ARCHIVE_2026-10-22_f31eff7.md.

## Verified exact checkpoints
- VERIFIED `84ff55f59a59b437b3c23eac66a44d9a7262b047` / [run 39818374871](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39818374871) ALL SIX SUCCESS. ADR-0009 **Option A** internal abnormal closure: separately authorized source owner/admin write-off of exactly the confirmed `owned_on_loan` units, full verified settlement coverage, company-serial `IAC-<n>` numbered immutable private PDF with SHA256/size, audit and replay/conflict denial. **No company-level journal**, no cash mutation, no unconfirmed stock retirement. Owner UI for authorization/closure remains OPEN.
- VERIFIED `f31eff7` / [run 39806715238](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39806715238) ALL SIX SUCCESS: ADR-0009 proposed and verified; owner subsequently chose Option A.
- VERIFIED `303975e` / [run 39803376087](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39803376087) ALL SIX SUCCESS: read-only internal abnormal closure readiness.
- VERIFIED `ef326a4` / [run 39797843118](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39797843118) ALL SIX SUCCESS: Owner UI for private internal settlement evidence and independent verification.
- VERIFIED `695d5bd` / [run 39791495105](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39791495105) ALL SIX SUCCESS: private internal settlement evidence backend.
- VERIFIED `f76bde7` / [run 39787096473](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39787096473) ALL SIX SUCCESS: Owner/manager UI for the provisional internal loss obligation.
- VERIFIED `26fa63e` / [run 39782634798](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39782634798) ALL SIX SUCCESS: internal loss obligation backend.
- VERIFIED `f4f2546` / [run 39779366263](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39779366263) ALL SIX SUCCESS: final external-in non-clean closure and `ENCL-<n>` numbered immutable PDF.
- VERIFIED `e009c41` / [run 39774276958](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39774276958) ALL SIX SUCCESS: external-in non-clean closure readiness.
- VERIFIED `21c1342` / [run 39739681884](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39739681884) ALL SIX SUCCESS: Owner reversal UI for a verified partner payment.
- VERIFIED `40ab4ce` / [run 39737410547](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39737410547) ALL SIX SUCCESS: gated `inventory_partner_payment` ledger and immutable reversal.
- VERIFIED `64aa7d6` / [run 39734053941](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39734053941) ALL SIX SUCCESS: Owner payment-evidence record/independent-verification UI.
- VERIFIED `d6c9157` / [run 39732210760](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39732210760) ALL SIX SUCCESS: payment-evidence backend.
- VERIFIED `45d9eb7` / [run 39729151047](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39729151047) ALL SIX SUCCESS: Owner obligation proposal/review UI.
- VERIFIED `c872af4` / [run 39727336870](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39727336870) ALL SIX SUCCESS: ADR-0008 and obligation backend.
- VERIFIED `39f28c9` / [run 39725278812](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39725278812) ALL SIX SUCCESS: partner-confirmed receipt clearance.
- VERIFIED `641100b` / [run 39722040547](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39722040547) and `9b4db00` / [run 39718936025](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39718936025) ALL SIX SUCCESS: partner short/damaged evidence and per-loan reconciliation.
- Historical reds `4102245` (two 63-char FK names collided) and `0ea2488` (test actor not authenticated as the service actor) remain historical; fixed at `84ff55f`.
- Historical red `cc041cd` remains historical (wrong physical-quantity expectation), corrected at `e009c41`.

## Open Phase 12 requirements and next task
Phase 12 OPEN: Owner UI for internal write-off authorization and numbered certificate display/download; full feature/UI/permissions/audit reconciliation; multi-loan concurrency, storage failure/restore/security; full owner/manager/operator acceptance and release certification. See docs/PHASE12_ACCEPTANCE_STATUS.md.
- Next executable: add the Owner UI for internal write-off authorization, final closure and numbered certificate, then the Phase 12 acceptance walkthrough. Never close from evidence or a proposal; never infer a price/tax; never retire unconfirmed stock; keep every production inventory/finance switch OFF.

## Gates and blockers
All production inventory/finance flags remain OFF. EXTERNAL BLOCKED: real partner signatures/admissions, bank/cash settlement verification, provider credentials, staged operator acceptance, backup/restore/security signoff and explicit production release approval. Phase 13 not started because Phase 12 acceptance is incomplete. Do not fabricate evidence, receipts, settlements or operator approvals.

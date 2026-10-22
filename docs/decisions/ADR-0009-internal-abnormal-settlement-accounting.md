# ADR-0009 — INTERNAL abnormal settlement accounting and final closure

- Status: **PROPOSED — OWNER/ACCOUNTING DECISION REQUIRED**
- Date: 2026-10-22
- Branch: `chatgpt/phase0-foundation`
- Related: ADR-0008 (external-in partner loss obligation), Phase 12 acceptance status

## Context

The INTERNAL abnormal flow now has verified, default-OFF layers:

1. joint signed borrower/lender physical finding and independent confirmation;
2. provisional internal loss obligation with independent approval;
3. private settlement evidence (cash/bank reference, paid date, proof) and independent verification;
4. read-only closure readiness that reports remaining custody, approved obligations, verified settlements and the two unresolved blockers.

No journal has been posted for the internal settlement, and no physical write-off or final certificate exists. The missing decision is the accounting meaning of an inter-hall settlement inside one company. Both halls belong to the same company; a cash/bank transfer between them does not change company-level cash. The current finance ledger has no hall-specific receivable/payable or inter-hall transfer accounts.

## Options

### Option A — No company-level journal (recommended, conservative)

Treat the internal settlement as an inter-hall management allocation recorded by the obligation and settlement evidence, not as a company-level cash journal. Final abnormal closure requires:

- a confirmed finding with zero unexplained physical custody after an authorized write-off;
- an independently approved internal loss obligation;
- independently verified settlement evidence covering the approved amount;
- a separate source owner/admin authorization for the physical write-off;
- a company-numbered immutable final certificate documenting the obligation, settlement, write-off and remaining ownership position.

No new chart accounts, no company-level journal, no cash mutation by the closure. The write-off retires only the confirmed unreturned `owned_on_loan` units. This option needs an explicit owner decision because the approved Phase 12 text mentions a financial settlement, and this ADR defines it as evidence-backed internal allocation rather than a company ledger posting.

### Option B — Balanced inter-hall receivable/payable journal

Treat the borrowing hall as owing the source hall and post a balanced journal under the finance gate: debit an inter-hall receivable/expense account and credit an inter-hall payable account (or the reverse on settlement), with an immutable reversal. This requires owner/accounting approval, new chart accounts, a defined hall dimension on journal lines, and a reversal policy. It cannot be implemented safely by inventing account codes.

### Option C — Defer internal abnormal closure

Keep the verified evidence, obligation, settlement and readiness layers, but do not write off custody or issue a final certificate until the owner chooses A or B. The readiness gate remains BLOCKED. This is the current state.

## Proposed decision

Option A, subject to explicit owner/accounting approval. It avoids inventing chart accounts, keeps company cash unchanged, and documents the inter-hall allocation with immutable evidence and a numbered certificate. Physical write-off remains a separate authorized action after verified settlement.

## Consequences and controls

- All internal flags stay OFF until approval and acceptance.
- No closure from evidence, a proposal or a settlement alone.
- No retirement of units that are not confirmed and independently authorized.
- No production activation; operator/security/restore acceptance remains external.
- If the owner chooses B, a separate ADR amendment and chart/hall-dimension design are required before any journal code.

## Decision

**PENDING.** The owner must choose A, B or C. This ADR does not itself authorize a journal, write-off, certificate or production release.

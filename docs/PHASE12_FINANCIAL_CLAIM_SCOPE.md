# Phase 12 — independently reviewed external-owned loss claims

Status: implementation candidate. Production release flag OFF, exact-SHA hosted CI required.

A current original lending-hall owner/admin may propose a money amount in ISO three-letter currency and integer minor units only for an independently substantiated inventory incident on an actually approved outside-owned dispatch. A different authorized owner/admin must make the immutable collection assessment; the original incident reporter and claim proposer cannot approve their own proposed charge.

Claims have company, original loan/hall, original incident, independent evidence-decision and immutable amount bindings enforced by PostgreSQL composite foreign keys. The original loan row is locked for quantity limits and concurrent proposals. Pending, approved, or disputed claim quantities reserve units, preventing repeated overlapping loss claims for the same physically documented damaged or missing/disputed goods. Rejected claims release the held quantity, not the immutable history. Decision outcomes are approved_for_collection, rejected, or disputed.

**No invoice, recognized receivable, settlement, external partner admission, journal, stock disposal, deposit refund, or non-clean closure is recorded here.** Collection approval is an internal proposed position only. All records are append-only with audit entries and conflicting-replay denials. Following work must require signed partner agreement or contested-case resolution, independently verified settlement and inventory write-off authorization before formal nonclean closure.

Flag: VENUE_INVENTORY_LOAN_FINANCIAL_CLAIMS_ENABLED (defaults to false). Dependencies: inventory incident and independent review and external_out physical dispatch and return controls. Do not release until operator acceptance, financial reconciliation tests, all six hosted jobs exact SHA and security approval.

# ADR-0008: External Borrowed Partner Loss Obligation (provisional payable foundation)

Status: DESIGN DECISION FOR A DEFAULT-OFF FOUNDATION; LIVE FINANCE AND PAYMENT NOT APPROVED.
Date: 2026-10-22.

## Context

EXTERNAL_IN loans bring a partner's stock into a hall as borrowed custody. The partner-signed
discrepancy flow can now record received, damaged and missing quantities, and an independent
owner/admin substantiates or rejects that evidence. A substantiated shortfall may create a
commercial liability of the hall to the partner, but the approved product scope does not yet
define a payment rail, tax treatment, price list or signed settlement contract.

The existing external-out financial controls model money owed **to** the hall by a partner.
They must not be reused for EXTERNAL_IN because that would reverse the accounting direction
and could fabricate a receivable or cash receipt.

## Decision

1. Only a substantiated `hall_inventory_external_in_discrepancies` finding for the exact
   company, borrowing hall, original loan and item counts may ground a provisional partner
   loss obligation. A pending, rejected or requires-more-evidence discrepancy cannot.
2. The quantity basis is the partner-reported **missing plus damaged** units. Units the partner
   confirmed as received are excluded; the hall's physical custody of missing units remains a
   separate inventory question.
3. A current borrowing-hall owner/admin may propose one obligation per discrepancy in an ISO
   4217 three-letter currency and positive integer minor units. The amount is manually entered;
   no automatic item valuation, tax or price list is inferred.
4. A **different** current borrowing-hall owner/admin must independently decide
   `approved`, `rejected` or `disputed`. The discrepancy recorder and return dispatcher cannot
   self-approve. One immutable decision per obligation; conflicting replay fails.
5. An approved obligation is a **provisional internal commercial position only**. It is not an
   invoice, recognized receivable, payable journal entry, payment, partner admission, waiver,
   stock retirement or loan closure. It must never be presented as settled money.
6. Settlement is excluded from this decision. A later, separately gated batch may record a
   verified cash/bank **payment out** with independent verification, equal debit/credit journal
   lines and an immutable reversal. It must not reuse external-out recovery or customer
   receipt paths.
7. Non-clean closure remains separately gated and may occur only when physical custody is zero,
   the financial position is independently verified, and a numbered immutable PDF exists.

## Safety controls

- Feature gate `VENUE_INVENTORY_EXTERNAL_IN_LOSS_OBLIGATION_ENABLED` defaults **false**.
- PostgreSQL composite foreign keys bind company, hall, loan, discrepancy, decision, currency
  and amount; immutable triggers protect evidence and decisions.
- The original loan row is locked for quantity and replay checks; audit writes are atomic.
- No stock movement, owned position, cash, bank, journal or closure is written by this feature.
- Production inventory and finance switches remain OFF until operator, security, backup and
  restore acceptance is evidenced.

## Consequences

The project can capture a reviewed commercial position without pretending a price is approved
or money has moved. Finance UI, settlement verification, reversals, tax/legal review and
non-clean closure remain open and must be verified separately.

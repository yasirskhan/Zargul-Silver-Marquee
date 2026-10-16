# ADR-0007: Internal Finance Preview and Ledger Release Gate

Status: IMPLEMENTATION FOUNDATION ONLY; LIVE FINANCE ENABLEMENT NOT APPROVED.
Date: 2026-10-16.

## Current invariant
The in-progress system still has no approved confirmed-booking service, client-facing invoices,
receipt issue/credit-note contract, custody terms, taxes, refunds or collected payment rail.
No code in this batch may imply a real customer debt or successful cash receipt.

FIN-001 provides an **immutable internal nonbinding rental-price preview** for a proposal already
under review. It freezes the exact approved hall rental rate revision, event program, proposal
revision, integer minor-unit currency and reason. It excludes unapproved add-on calculations,
taxes, discounts, client-facing PDFs and invoice issuance. A preview never holds a slot.

FIN-002 provides a PostgreSQL-reconciled double-entry book, with two immutable debit/credit
lines per journal, unique journal UUID, enforced balanced totals at COMMIT, single-reversal
reference uniqueness, atomic audit and exact-payload retry. During this boundary, the
only allowed posting types are verified owner cash *opening* and linked *reversal*. There
is no customer receipt, payment gateway, actual refund, partial payment, disbursement,
or manual adjustment endpoint. This avoids fabricating money before custody signoff.
Company lock serializes conflicting postings and daily closeouts.

FIN-003 provides UTC daily immutable closeouts that compare cash-on-hand balance in minor
units with owner-counted physical cash. Any discrepancy remains explicit and audited and
does not quietly change ledger balances. Once a company/currency UTC day is closed,
new same-day journals are refused. Venue-local shift times and physical cash handover
protocol require separate business acceptance before an operational rollout.

## Safety controls
`VENUE_FINANCE_POSTING_ENABLED` defaults false; it must stay off in any unapproved environment.
Only an active email-verified owner who is the current authenticated actor may post an
opening, reverse one, or close cash. Active owner/admin can read internal nonbinding
previews. Manager/reception/customer roles and foreign tenants cannot inspect or operate
owner finance. PostgreSQL composite FKs and immutable triggers preserve tenant/history
boundaries. An audit write failure rolls back ledger and closeout atomically.

## Release gates
Before real finance: approve currency minor-unit precision, taxes, quote and contract terms,
add-on charge units, itemized invoices, PDF template & safe customer issuance, consent,
deposit and refund custody/payment method reference handling, posting account chart,
expense approvals, true payment reversals/chargebacks, shift timezone, discrepancy
escalation, secure backups/recovery, pilot reconciliation and owner acceptance.

**Do not mark FIN-001/FIN-002/FIN-003 100% complete merely because their safe internal
primitives have passed hosted tests.**

# Phase 4 acceptance — finance

Status: **TECHNICALLY VERIFIED for internal immutable quotes, invoices, PDFs, ledger/payment/refund services and cash closeout. Payment-provider, bank-settlement and operator/production acceptance remain separate and OFF.**
Verified source: `1d4afb3c783322d32c716c27b72cca3031fde145`
Hosted CI: [run 40090557799](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40090557799) — **all six SUCCESS**.

## Requirement evidence

| Requirement | Evidence |
| --- | --- |
| FIN-001 — versioned quotes and invoice/receipt PDFs | `VenueQuoteService` freezes confirmed-reservation rental/seating/add-on lines from immutable revisions; `VenueQuoteIssueService` issues company-sequential numbered quotes under an operator gate; `VenueInvoiceService` issues immutable numbered invoices under issuance + approved tax-basis gates; `HistoricalInvoicesPage` and reservation UI produce authenticated internal PDF/1.4 invoice, receipt and refund copies. PostgreSQL service/Livewire tests cover stale revisions, idempotent retry, role denial, company scope and total/balance accuracy. |
| FIN-002 — idempotent balanced ledger and reversals | `VenueInvoicePaymentService` records internal cash/bank deposits/partial payments with unique references, balanced deferred journals and overpayment guards; `VenueInvoiceRefundService`/`FinanceControlService` support independently approved refund reversals and opening-cash reversals without rewriting history. PostgreSQL tests assert balanced debit/credit, unique request keys, rollback and audit immutability. |
| FIN-003 — cash closeout and reconciliation | Owner-controlled UTC cash opening, immutable counted closeout with expected/counted/variance persistence and same-day lock; read-only daily customer-deposit/refund reconciliation matches original journals and closeouts. New authenticated browser journey opens PKR 100000 cash, freezes a counted 99975 closeout and displays variance -25. |
| Authorization and isolation | Finance is owner/admin scoped; manager/foreign-company direct access denied; persisted active/verified account is rechecked; no customer contact in finance screens; currency and company scope enforced in services. |
| Release gates | `VENUE_FINANCE_POSTING_ENABLED`, quote/invoice/payment/refund gates remain default OFF. The authenticated CI job enables only controlled finance posting for the synthetic cash-closeout browser proof. No real charge, bank settlement or provider confirmation is performed. |

## Browser acceptance

- Real owner password + TOTP on desktop: Finance Review opens, controlled cash opening posts a balanced entry, counted 99975 closeout persists and shows expected/counted/variance in minor units.
- Existing owner mobile/desktop finance preview journey remains green; manager access remains denied by policy/tests.

## Remaining separate gates

- Real payment-provider/bank credentials, settlement, customer charges and reconciliation statements.
- Operator finance/currency/tax approval and production release.
- Real customer data, invoices to customers, public payments and notifications.
- Phase 12 operational acceptance and all production flags remain separate.

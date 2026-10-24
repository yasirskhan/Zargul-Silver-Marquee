# Phase 3 acceptance — bookings, calendar and universal search

Status: **TECHNICALLY VERIFIED for the internal booking lifecycle and browser intake. Live confirmation remains release-gated OFF; full browser hold/confirm and operator/staging acceptance are separate.**
Verified source: `bf288cbaef6297dc4d90c2fab5a928ef7b2c8d91`
Hosted CI: [run 40075891670](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40075891670) — **all six SUCCESS**.

## Requirement evidence

| Requirement | Evidence |
| --- | --- |
| BOOK-001 — availability calendar | Role/hall-scoped Filament calendar with day/week/month projections, holds, confirmed occupancy, cleanup-required state, draft counts and validated company/hall/date/program links; `HallCalendarLivewireTest`, `HallOccupancyCalendarServiceTest` and PostgreSQL authorization/expiry regressions. Operations mobile browser proves assigned-hall read-only access. |
| BOOK-002 — database-safe holds and no double booking | Unique PostgreSQL hall/date/program claim, `HallSlotHoldService` expiry/release, `VenueReservationService` confirm/reschedule/cancel and cross-hall transfer under stable lock order; two-connection lock contention test, conflict/rollback/retry and conversion tests. Confirmation is gated by `VENUE_BOOKING_CONFIRMATION_ENABLED`, default OFF. |
| BOOK-003 — customers, lifecycle and history | Encrypted company-scoped customer profiles, staff intake with existing opaque customer ID, owner review/draft state machine, confirmed reservation records, audited reschedule/cancel and owner/admin customer history; no invoices, deposits or payments. New authenticated browser journey creates an encrypted customer + draft booking request and proves no hall claim. |
| SRCH-001 — role-scoped universal search | Tenant-scoped literal search across authorized proposals, planning, approvals, finance previews and owner/admin audit metadata; manager/operations planning-only scope, no contact leakage and exact private deep links; `InternalQuickSearchTest` and `InternalSearchCompanyLinkTest`. |
| Authorization/privacy | Company/hall policies recheck persisted active/verified users; foreign customers/halls and manager financial/contact scope denied; customer contact remains encrypted and owner/admin-only. |

## Browser and lifecycle boundary

- Authenticated owner desktop: real password + TOTP creates a synthetic customer and unconfirmed booking request; no slot claim, invoice, deposit or payment.
- Authenticated operations mobile: assigned-hall search/planning and read-only hall configuration.
- Full browser hold → confirm → reschedule → cancel is **not claimed** because the production confirmation gate is OFF and CI does not enable it. The same lifecycle is proven by PostgreSQL/Livewire tests with the gate explicitly enabled in the test process.

## Remaining separate gates

- Operator/staging approval to enable live confirmation; keep `VENUE_BOOKING_CONFIRMATION_ENABLED=false` until then.
- Real customer data, public bookings, invoices, deposits, payments and notifications.
- Phase 12 operational acceptance and all production release flags remain separate.

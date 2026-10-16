# ADR-0003: Initial Hall Slot Claim Exclusivity

Status: PROPOSED architecture and verified schema only; no live booking service approved.
Date: 2026-10-16.

## Context
A hall has three owner-configured local programs. Confirmed reservations and unexpired temporary holds must not compete for the same hall, date and program, even under simultaneous requests. A calendar color or application-level availability check alone is insufficient.

## Provisional schema invariant
Use a single active claim row with a physical PostgreSQL unique constraint over (hall_id, event_date, program). The row represents either a temporary hold with explicit expiration or a confirmed slot without expiration. Historical transitions are append-only `hall_slot_claim_events`, preserved after an active claim is released. Composite company/hall foreign keys prevent mismatched tenant ownership. There must never be two simultaneous active rows for the same hall/day/program.

Expired holds are not automatically considered free by the unique index: a future transactional service must lock the hall/claim, verify expiry against a trusted clock, append event and audit, release the old claim, and then acquire a new one. The service must implement idempotency and lock-safe retries. Inactive reservation history and financial obligations must remain in permanent booking/ledger records, not in active-claim rows.

## Explicitly deferred release decisions
Venue timezone, cutoff/clock drift, temporary hold duration and extensions, manager approval, customer consent/privacy, program-buffer schedule changes after a booking, cancellation policy, occupancy and seating restrictions, and quote snapshots remain to be approved and tested. A PostgreSQL schema and isolated tests cannot prove a full booking lifecycle or public availability. No public booking confirmation route, customer data, Telegram, invoice or payment writes may use this schema until the complete authorized service and concurrency tests are green.

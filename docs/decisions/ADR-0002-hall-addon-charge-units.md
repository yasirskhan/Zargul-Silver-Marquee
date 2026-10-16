# ADR-0002: Provisional Hall Add-On Catalog Charge Units

Status: PROPOSED. No live quotation, billing or production price consent.
Date: 2026-10-16.

## Context
VenueDesk must let owners configure catering, DJ/music, lighting, bridal entry, stage, photography and additional services without allowing a website editor or staff member to invent or override charges. Business-specific menus and rates are not yet supplied, so production pricing is explicitly unavailable.

## Provisional data representation
An owner/admin can version a per-hall catalog of up to 100 optional items. Every item has a stable safe code, plain-text label, category, explicit charge unit, integer minor-unit rate and enabled state. Supported units are `flat` (per event), `per_guest`, and `per_unit`. These describe catalog entries only. Owner decisions must determine taxable basis, quantities, minimum guests, included packages, deposit implications, discounts, complimentary approvals and contract terms before the actual quote engine uses them. Do not calculate amounts from this ADR alone.

Immutable, company/hall-scoped snapshots, optimistic revision checks and atomic before/after/reason audit protect configuration changes. Managers assigned to the hall can read but not edit. Tenant isolation is enforced in the service and PostgreSQL composite FK.

## Release gate
Do not use these values for an invoice or collect money until currency minor-unit exponent, quantity rules, item eligibility, customer-facing wording, taxes and approval policy have signed-off acceptance tests and versioned quote snapshots. Catalog entries cannot directly authorize bookings, change published prices, waive approval rules or overwrite an existing contract.

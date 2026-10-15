# Zargul Silver Marquee / VenueDesk: Approved Product Specification

Status: DRAFT BASELINE (requirements derived from user-approved mockups; development acceptance still required). Date: 2026-10-15.

## Purpose and principles
One cohesive business platform: a premium responsive customer-facing website branded **Zargul Silver Marquee**, plus a simple, comprehensive and secure **VenueDesk** management application. Use distinct customer vs employee UI. Every record must be traceable and authorized. Real customers and money must never use prototype functions as trusted backend.

## Public venue website
Luxury, modern, mobile responsive design; real approved hall photos, detailed halls and packages, services, gallery, testimonials subject to verification, contact/location, clear enquiry/availability actions and SEO. Customers may enquire by date, hall, guest count and morning/noon/evening program. No customer personal details exposed in availability. Enquiries become internal tracked leads. Manager confirmation before any real booking. No fake pricing/reviews/photos on production pages.

## Website CMS and owner-controlled page builder (approved 2026-10-15)
Authorized venue owner (also referred to by user as landlord) or explicitly delegated site administrator can edit the entire public-facing website in VenueDesk: logo, favicon, theme, fonts/palette presets, photos/crops, text, navigation, halls/packages/services/galleries, SEO, enquiry fields, and footers. Drag/reorder sections, create 1/2/3-column layouts, reorder nested blocks and position desktop columns independently from mobile stacking. Use typed secure blocks and layout templates, not arbitrary executable HTML or direct production template editing.
Maintain page drafts and published snapshots, responsive preview, review/publish permissions, optional approval, scheduled publishing, version history, optimistic concurrency so simultaneous editors cannot overwrite changes, audit trail and rollback. Real backend enforces tenant isolation and authorized media access. Dynamic hall/price/availability/form blocks must call secure business services and cannot override pricing or booking rules. See docs/WEBSITE_CMS_DESIGN.md for complete specification.

## Venue/company hierarchy, identity, permissions
Platform > venue operating company (tenant) > one or more halls, with assigned employees. Owner/Admin, manager, receptionist, cashier and operations staff get task-focused dashboards. MFA for Owner/Admin, forced password change and least-privilege backend policies for every API, background job, search and export. Explicit cross-company and cross-hall isolation tests. No menu visibility as sole authorization.

## Booking and hall operations
Three independently configurable programs each day (morning, noon, evening), start/end times, setup and cleaning buffers, capacity and seating arrangements. Instant monthly/day/mobile calendar with available / tentative hold / booked indicators. Prevent conflicting confirmed reservations and valid holds at the database level, including concurrent requests and retries; holds have expirations. Include basic chairs/tables, sell extra chairs/tables at owner-set rates without exceeding capacity. Configurable per-hall and per-session rental fees. Configurable catering per guest and event add-ons including music/DJ, decorative lighting, bride-and-groom entry, stage and photography. Itemized pricing snapshots for approved quotations.

## Grace/overtime
Owner controls free grace in minutes, overtime started billing interval/rate, cleaning buffer, hard stop when next program exists. Record actual start/end and any actual overrun even if an extension is prohibited. Conflict policy must not silently authorize later end times. Reconcile overtime into auditable post-event invoice adjustment. Preserve pre-existing contract terms unless explicitly adjusted.

## Money and invoices
Quotations, deposits, detailed branded printable invoices/receipts/PDF, configurable discounts/taxes, balances, payment methods, financial ledger, refund/reversal workflow, daily cash drawer, handover/reconciliation, accounting reports. Ledger postings transactional and idempotent with unique references; no silent alteration/deletion. Corrections link original, reversal and replacement records. Existing issued invoices require adjustment/credit documents rather than hidden rewriting.

## Approval center and delegation
Unified approval types: additional discount, exceptional refund, complimentary services, overtime waiver, price override and high-value expenses. Owner sees in-app login popup and persistent inbox with client, manager, event, hall/session/date, itemized original price, existing discounts, request amount, proposed price, reason, and payment balance. Telegram bot sends a private notification with secure in-app link; approval itself requires server-authorized login and MFA where applicable. Pending requests never change active invoice. On approval, apply exactly once and record decision/transaction, notify requester; reject retains authorized price. No self-approval; prevent concurrent duplicate decisions and uncontrolled stacking.
Owner-configured emergency delegation may grant one named administrator selected categories, amount ceilings and start/end time; auto-expire, revoke immediately, never sub-delegate, never self-approve; owner alerted to delegated decisions and searchable audit.

## Search, navigation and usability
Responsive employee interface with bottom navigation on phones, universal role/hall-authorized search across customers/bookings/payments/invoices/approvals/audit, quick actions, recently viewed, linked records. Daily workflows accessible with few clicks. Reception sees no sensitive owner financials; owner can inspect advanced reports as required.

## Auditing and recovery
Record who/what/when, before/after values, reason, policy/delegation context, IP/session metadata where lawful, linked business and financial records. Server-side append-only audit with separate secure export/backup; no secrets or sensitive tokens in logs. Automated encrypted backup and restore test before pilot. Security monitoring and authorization failure alerts.

## Rollout and release gates
Phase 0 discovery/specification/CI; Phase 1 backend/auth/tenant/audit; Phase 2 hall rules; Phase 3 booking/calendar; Phase 4 invoicing/ledger; Phase 5 approvals/Telegram; Phase 6 public venue site production integration; Phase 7 operations and readiness; Phase 8 supervised cash-reconciling pilot; later vendors/payroll/assets/inventory/reporting as approved.
Every release requires traceable acceptance criteria, tests for concurrency/security/finance, green exact-SHA CI, operator review, backup verification, and no unresolved critical risk. A demo UI or unit test is never a production go-live approval.

## Non-goals in Phase 0
No public deployment with live bookings, no real client data, no collection of payments, no production Telegram credentials, no claims of secure backend or immutable ledger. Phase 0 Node demos are explicitly non-authoritative.

# Development Roadmap

## Phase 0 — FOUNDATION (in progress)
Establish dedicated repo, approved spec, requirement register, AI handoff, CI, explicit separation from property-platform; import and label proof-of-concept interfaces. CI verification is required before marking complete.

## Phase 0.5 — Website CMS architecture + editor usability (new approved scope)
Formalize owner-configurable website design, typed safe sections and columns, publishing/audit/access policy and page/version/storage model. Test pure layout/revision helpers, then build a practical visual editor integrated into VenueDesk. Owner can move text, images, sections, columns and mobile stacking. Keep business price and booking constraints on backend. See docs/WEBSITE_CMS_DESIGN.md.

## Phase 1 — Secure server foundation
Laravel/Filament/PostgreSQL versions verified; migration foundation, operating company and halls, authentication, roles, MFA, policy tests, tamper-resistant audit.

## Phase 2 — Owner configuration
Sessions morning/noon/evening, times, grace, overtime, buffer, capacity, seating pricing, catering and add-ons; snapshot/versioned pricing.

## Phase 3 — Bookings and universal search (core internal confirmation implementation pending hosted CI)
Color-coded multi-hall calendar, temporary holds, concurrency constraints, customer profiles, enquiries and mobile wizard.
A new PostgreSQL backed INTERNAL owner/admin/assigned-manager/reception confirmation flow consumes a reviewed proposal into a separate confirmed reservation, locks an exclusive hall/date/program claim, and supports same-hall rescheduling and audited cancellation. The calendar projects confirmed occupancy. Release gate VENUE_BOOKING_CONFIRMATION_ENABLED defaults OFF until operator readiness signoff. This is NOT a public intake, full booking management milestone or ready-to-charge booking: new customer onboarding for manager/reception, cross-hall transfers, operator timezone, expiry/holds UX, consent/privacy signoff, production race acceptance, invoice/payment and mobile browser UAT remain open.

## Phase 4 — Finance
Quote, invoice, receipt, deposit, ledger, correction, refund, cash reconciliation, PDFs.

## Phase 5 — Approvals
Owner review center, Telegram notification integration, approvals audit, permissions, emergency delegation and approvals under concurrency.

## Phase 6 — Venue public website
Production-grade Zargul Silver Marquee brand site and integrated enquiry flow, legitimate venue photos/packages.

## Phase 7 — Operations/readiness
Event actual times, setup/cleaning, services, staffing, overtime billing and operational reports.

## Phase 8 — Controlled pilot
No real launch until finance, isolation, restores, booking concurrency and real user tests are accepted.

## Later
Inventory, payroll, purchases, vendors, assets, maintenance, profitability reporting and multilingual support.

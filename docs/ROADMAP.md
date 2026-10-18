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

## Phase 7.1 — Actual event operations (release gated)
Authenticated assigned-hall event start/finish observations, strict optimistic revision checks,
immutable evidence and current owner rules-based overtime/grace/hard-stop preview.
Actual extension remains unauthorized and no invoice or money changes are made automatically.
Operator release flag `VENUE_EVENT_RECORDING_ENABLED` remains OFF until separate venue signoff.
Catering fulfilment, authorized overtime charge adjustments and event closeout reporting follow.

## Phase 7.2 — Actual setup fulfilment and audit
Bridge reviewed preparation tasks from the original confirmed reservation's proposal
into witnessed physical setup only after actual check-in. Staff records a reason and
immutable one-time fulfilment evidence under the current hall/company policy.
A reviewed plan alone never proves service delivery. No automatic invoice,
payment, hall extension, or added customer charge occurs.

## Phase 7.3 — Frozen operational event closeout
Seal current nonbinding grace/overtime/turnover observations together with
reviewed planned-task counts and witnessed performed/unperformed evidence.
Display unresolved services without falsely marking them delivered, do not silently
change customer invoice, reservation or ledger. Actual overtime billing still needs
authorized contractual adjustment and operator release.

## Phase 8 — Controlled pilot
No real launch until finance, isolation, restores, booking concurrency and real user tests are accepted.

## Phase 9 — Commercial licensing and initial setup (approved, not implemented)
Separate VenueDesk Central Control Center; signed license activation/renewal; hosted + self-hosted tenancy; scoped hall/user entitlements; public maintenance at expiry with backend grace; renew/export/reconcile; resumable securely closed owner/hall/pricing/payment setup wizard and existing-installation migration.

## Phase 10 — Public website productization (approved, not implemented)
Owner-selectable structural themes and company/hall profile; exact guest-count pricing brackets; one-hall auto-selection; published footer per-hall maps/contacts/phone and centrally governed per-license optional designer credit; safe 3-screen enquiry and 5% verified-payment hold.

## Phase 11 — Provider payments and complete finance (approved, not implemented)
Tenant-owned JazzCash/Easypaisa/Raast manual QR and approved APIs; optional unified gateway; named bank/wallet ledgers; independent verification; cash two-signature evidence, owner/staff custody and transfer confirmation; individual manager spend ceilings; vendor/payroll/utilities and reporting.

## Phase 12 — Hall inventory and multi-item loans (approved, not implemented)
Hall-owned inventory catalog and stock movements, inline item entry, monthly/quarterly/post-event audits, minimum levels, immutable discrepancy review, independent internal and outside-establishment multiple-item loans, count/return/condition evidence, partial receipts, automatic closure ONLY on independently confirmed full match, numbered PDF and hall reports. Start schema foundation after existing CI repair; preserve booking/finance gates.

## Phase 13 — News, contextual Help, support and Smart Support (foundation in progress; not production released)
Central editorial news and versioned manuals/videos, contextual help buttons; full central agent/customer bidirectional ticket replies, status tracking, unread counters/banners, support after license expiry, safe queued manual/video/approved prior-solution suggestions without auto-closing ticket and no cross-tenant raw-ticket exposure. Foundation added on the protected development branch: separately encrypted client support outbox and inbox, append-only HAFZATECH ticket and MFA-scoped reply schema, Ed25519 signed request and optional reply-page envelope codecs, default-OFF isolated five-minute support delivery/polling, owner/admin company-scoped inbox page and overview unread link. These are **not live**: central production API routes, staff MFA login, independent KMS key custody, HTTPS/DNS, customer notifications and end-to-end operator acceptance remain open. Require exact hosted CI and release signoff.

## Phase 14 — Performance and commercial release certification (approved, not implemented)
Measure owner/manager/mobile booking/report latencies, indexes/queues/caching, tenant isolation, license offline grace, provider callbacks, financial correctness, real inventory reconciliation/loan concurrency, accessibility and self-hosted upgrade/restore. Green exact-SHA CI and staged pilot required.

## Deferred beyond approved commercial scope
Nonessential extensions only; inventory, wages, vendor finance and reporting are now approved product work, not a generic later bucket.

## Phase 9.1 — HAFZATECH central control contracts (2026-10-17 approved; not implemented)
Implement `docs/CENTRAL_CONTROL_CENTER_V1.md` and `docs/FAST_DELIVERY_PLAN.md`: `lichall.hafzatech.com` trusted client bootstrap, customer/install identity, signed license 6m/12m, automatic 15-day status verification plus owner manual Verify, early paid renewal same license ID, 15-day warning, public maintenance on expiry, seven-day backend grace, audited grace/revocation. Central HAFZATECH staff roles with least privilege, per-customer vendor bank/Easypaisa/international/WU/eligible future PayPal receiving accounts, verified invoice payment before license signing, encrypted optional issuance, separate client operating ISO currency/IANA timezone per hall and support sync. Keep existing VenueDesk functionality untouched and license release OFF until CI and operator release gates.

## Phase 9.2 — Central operator staff portal implementation checkpoint (October 18, 2026)
The dated Phase 9/9.1 "not implemented" baseline above is historical. Current code and
`AI_HANDOFF.md` have implemented isolated installation enrollment, paid-term
and signed status/grant foundations, staff MFA/CSRF sessions, internal
role-scoped read-only vendor dashboards, immutable billing/support audit and
self-password change. Last fully green operator source before this batch:
`e34e9b8`, GitHub Actions 39165362826, all six jobs passed.

Next gated development includes an INTERNAL vendor operator application
composition with injected external secret-manager/MFA and native cookie writers;
pending-only staff account provisioning with no roles or usable credentials.
These new candidate features require their own exact-SHA hosted CI. They DO NOT
constitute a deployed staff website. DNS/TLS/proxy, KMS-backed credentials,
bank settlement verification, real HSM signer, operator recovery and
production release certification are still blockers. Never switch on the
production toggles merely because development CI passes.

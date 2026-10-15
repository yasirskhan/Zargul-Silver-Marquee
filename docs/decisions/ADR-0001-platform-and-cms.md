# ADR-0001: Single Laravel Business Platform with Owned Website CMS
Status: PROPOSED — version lock requires Composer/CI compatibility proof
Date: 2026-10-15

## Decision
Keep one Laravel backend/API, PostgreSQL database, a public Zargul Silver Marquee venue website, a responsive VenueDesk operations/admin panel, and an integrated permission-controlled Website Editor. Public pages are versioned content snapshots, not executable saved template code.

Candidate current stack: Laravel 13 (PHP >=8.3) and Filament 5 (verify compatibility, page editor package and Node/browser stack on clean hosted CI before locking versions), Livewire/Tailwind, Redis queue, object storage for uploaded photos, background image optimization, PostgreSQL strict constraints, Playwright E2E. No production package chosen solely on published docs; pin after fresh Composer install and tests.

## Why
- Employees need one role-scoped sign-on and common booking/customer/audit source.
- Owner needs website branding, pages and column layout edits in the same panel.
- Publishing/rolling back must not affect booking/payment integrity.
- A single shared security and permission engine simplifies auditing.

## Boundaries
Public website: reads approved published page revisions and safe public hall catalog snapshots; enquiry submission through authenticated or rate-limited public backend endpoints; NO direct invoice/ledger DB writes.
Owner/Admin panel: website content resources, media, publishing; also venue business administration. Manager may have specific website edit permission but not publish or finance authorities. Reception/cashier should not see CMS by default.
Application services: ReservationService, PricingService, InvoiceService, LedgerService, ApprovalService, NotificationService, AuditService, ContentPublishingService; handle all money writes in transactions with unique idempotency keys.
Data: company (tenant), site, halls and users are independent entities; site may display several company-owned halls; tenant and hall scopes enforced at service and database query boundaries.

## Website editor
Start with owned, typed block registry compatible with Filament's Builder conventions. Build a responsive visual wrapper with 1/2/3 column editing and drag/drop; evaluate a third-party visual builder plugin in a contained spike only after testing PHP/Filament compatibility, maintenance and threat model. Do not let a plugin own the application's source of truth. Separate site settings, navigations, page versions and media.
Required states: draft, preview, published, archived; every publication has immutable revision and audit event. Optimistic concurrency lock on page edits, publish revisions atomically, rollback creates a NEW publish record pointing at earlier content without deleting history.

## Security and reliability
- Strong owner MFA; server-enforced policies per action; checked signed preview URLs.
- No arbitrary script tags, raw HTML, PHP, JS, or unsafe styles from editor fields.
- Presigned uploads, content-type/size checks, image processing, alt and rights metadata.
- Public production page reads ONLY last good published content; draft cannot leak.
- Transactional outbox for Telegram notification; retries safe; no tokens in logs.
- Strict bookings without overlapping confirmed time ranges or live holds; staff cannot bypass.
- Ledger append-only with appropriate business reversals; daily cash reconciliation.
- Audit covers every sensitive mutation including site publishing, price changes and access.

## Rollout
0: git and CI prototype; 0.5: CMS typed layout specification and tests; 1: real backend/auth/company/hall/audit; 2: configurable hall inventory/rates; 3: bookings/calendar; 4: money; 5: approvals/notifications; 6: dynamic website CMS, public renderer, enquiries, publish workflow; 7: operations; 8: supervised pilot. Basic public design can be previewed in parallel, but never claim functional live bookings until backend ready.

## Decision gates
- Laravel/Filament package compatibility and clean install demonstrated with composer.lock in CI.
- Security and maintenance review of any visual CMS package; avoid plugin lock-in.
- PostgreSQL migrations tested against real hosted DB, not SQLite alone.
- Browser tests on mobile/tablet/desktop for editor and booking workflows.
- Approved owner photos/logo, venue contacts, business policies and taxes before production publish.

## Current limits
This ADR does NOT mean Laravel has already been installed. Current repository has static demo pages and pure JS rules. No real server-side authorization, database tables, secure editor publishing or payment ledger exist.

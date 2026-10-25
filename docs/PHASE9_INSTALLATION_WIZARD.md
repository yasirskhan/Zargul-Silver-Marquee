# Phase 9 installation wizard — corrected foundation

Status: **PAGE REGISTRATION + AUTHENTICATED OWNER BROWSER JOURNEY VERIFIED. Programs, pricing, add-ons and authenticated browser journey verified; final completion intentionally unavailable.**
Verified source: `297b67b0c15608a2c82ad51afd8fdd69e24bf0cd`
Hosted CI: [run 40153851545](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40153570974) — all six SUCCESS.

## Fixed

- `VenueSetupWizard` is now explicitly registered in `AdminPanelProvider`; `/admin/venue-setup` resolves for authorized owners.
- The authenticated Playwright owner journey is restored:
  - password + TOTP;
  - company legal/contact/currency/timezone;
  - two halls with address/phone/capacity;
  - save, reload, resume, Back, Review;
  - status remains in_progress;
  - no false completion;
  - manager denial remains covered by Livewire.

## Persistence and correctness

- `companies` now stores legal/contact/address/currency/timezone operationally.
- `halls` now stores address/phone.
- Hall updates preserve existing `is_active`; setup never silently reactivates.
- ISO 4217 catalog has explicit minor units (JPY 0, KWD 3, USD 2) and rejects unsupported codes.
- `completedStages` is versioned in wizard JSON; final completion requires all approved stages.
- Existing partial states remain resumable; no destructive reset.

## Added operational stages\n\n- Step 3 hall programs through `HallProgramRulesService` for every hall: morning/noon/evening start/end, setup/cleaning, grace, overtime interval/rate and rental rate; immutable program revisions and audit.\n- Step 4 seating/guest pricing through `HallSeatingPriceService`: hall capacity, included chairs/tables and extra unit prices in company currency minor units; immutable pricing revisions.\n- Step 5 add-ons through `HallAddonCatalogService`: categorized code/name/rate/charge unit with immutable catalog revision and audit.\n- Wizard `completedStages` now includes programs/pricing/addons; final completion still refuses until branding/payments/staff/licensing stages exist.\n- Authenticated browser journey saves and resumes all six steps, including two halls and reload; PostgreSQL tests cover canonical revision counters and audit.\n\n## Still missing

- Programs/schedules, guest pricing brackets, seating/add-ons, branding, tenant payment settings, staff invitations, licensing and final readiness stages.
- Real concurrent two-connection and failed-audit rollback tests beyond existing revision conflict coverage.
- Deployed central portal browser acceptance and full Phase 9 licensing workflows.

# Phase 9 installation wizard — corrected foundation

Status: **PAGE REGISTRATION + AUTHENTICATED OWNER BROWSER JOURNEY VERIFIED. Partial stages only; final completion intentionally unavailable.**
Verified source: `eca1e21804ffab757fac6a3ffac28e092d75ba3e`
Hosted CI: [run 40148896410](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40148896410) — all six SUCCESS.

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

## Still missing

- Programs/schedules, guest pricing brackets, seating/add-ons, branding, tenant payment settings, staff invitations, licensing and final readiness stages.
- Real concurrent two-connection and failed-audit rollback tests beyond existing revision conflict coverage.
- Deployed central portal browser acceptance and full Phase 9 licensing workflows.

# Phase 2 acceptance — owner configuration

Status: **TECHNICALLY COMPLETE AND VERIFIED. Booking confirmation, quote issuance and production activation remain later/separate.**
Verified source: `39a0315023efc01fe1fe5c9542939363ed0adfa6`
Hosted CI: [run 40072138788](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40072138788) — **all six SUCCESS**.

## Requirement evidence

| Requirement | Evidence |
| --- | --- |
| HALL-001 — capacity, included chairs/tables and priced additions | Company/hall-scoped Filament seating controls with independent revision token, CAS, reason and audit; owner saves capacity 350, included chairs/tables and additional unit prices in the real authenticated browser journey, reloads and sees the saved values. Livewire/PostgreSQL tests cover manager read-only, revoked/unverified denial and stale conflict. |
| SLOT-001 — morning/noon/evening sessions, times, buffers and rental pricing | Owner edits all three sessions, setup/cleaning buffers, grace, overtime interval/rate and rental rate in the authenticated desktop journey; save, reload and persistence verified. Versioned `HallProgramRulesService` preserves an immutable approved rule set and rejects stale saves. |
| SLOT-002 — grace, overtime, turnover and hard stops | `HallOvertimePreviewService` remains read-only/nonbinding, reads saved rules only, calculates started overtime intervals, free grace, turnover conflict and hard stop, and never posts money or authorizes an extension. PostgreSQL/Livewire tests cover assigned-staff scope and invalid clocks; the authenticated operations journey opens the saved-rule preview on mobile and confirms read-only owner rules. |
| ADD-001 — catering, music, lighting, stage, bridal entry and other add-ons | Owner adds a categorized, unit-priced, enabled optional service in the authenticated browser, saves and reloads it. Versioned `HallAddonCatalogService` keeps catalog revisions immutable with CAS, manager read-only and audit. |
| Authorization and failure behavior | Manager write denial and assigned-hall read-only are covered by PostgreSQL/Livewire tests; revoked, unverified and foreign-company users are denied. Stale owner saves are rejected without overwriting or creating audit. |
| Snapshot boundary | Approved hall/session/seating/add-on terms are versioned in their own immutable revisions. Booking/quote monetary snapshots and confirmation are **Phase 3/4** work and are not claimed here; no booking, invoice, payment or customer charge was created by Phase 2 acceptance. |

## Browser acceptance

- Real owner password + TOTP on desktop: save morning rental, save seating/unit prices, add and save “Stage lighting”, reload and verify persistence.
- Real operations password + TOTP on mobile: assigned-hall read-only hall programs, seating and catalog; nonbinding overtime/turnover preview visible; no owner write controls.
- Real authenticated owner/manager/operations sessions remain subject to the application’s login throttling; the suite waits for the server’s real retry window rather than weakening authentication.

## Remaining separate gates

- Phase 3 booking confirmation/reservation and calendar behavior.
- Phase 4 quote/invoice/payment integration and monetary snapshot acceptance.
- Production activation, real customer data, operator/security/restore acceptance and provider credentials.

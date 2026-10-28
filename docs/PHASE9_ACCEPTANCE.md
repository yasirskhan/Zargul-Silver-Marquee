# Phase 9 acceptance register

Repository: `yasirskhan/Zargul-Silver-Marquee`  
Branch: `chatgpt/phase0-foundation` only.

This register records Phase 9 scope against implemented source and executable
evidence. It deliberately excludes Phase 10 public-website design/productization
and Phase 11 provider payment settlement.

## Evidence baseline

- Phase 9 software and browser acceptance: commit `68f075c`.
- Required six-job branch CI: run `406` series on that commit. The final
  docs-inclusive commit must be green again before release closure.
- Production activation, real signing keys, live payments and deployment are OFF.

## Requirements and evidence

| # | Requirement | Implementation | Evidence | Status |
|---|---|---|---|---|
| 1 | First-Owner bootstrap is operator-issued, single-use, expiring and audited | `backend/app/Services/OwnerBootstrapService.php`, `OwnerBootstrapController.php`, `OwnerBootstrapMail.php` | `OwnerBootstrapTest`; authenticated Owner setup | PASS |
| 2 | Resumable installation wizard persists all stages and never falsely completes | `InstallationWizardService.php`, `Filament/Pages/VenueSetupWizard.php`, wizard Blade view | `InstallationWizardServiceTest`; `zz-wizard-completion.spec.mjs` | PASS |
| 3 | Installation readiness requires verified/MFA Owner, company identity, bookable halls, branding, payments, staff mode and a live signed license | `InstallationReadinessService.php`; `halls.is_bookable` migration | `InstallationReadinessServiceTest` | PASS |
| 4 | Staff invitations are hashed, single-use, seven-day, revocable and email-verified | `StaffInvitationService.php`, acceptance controller, invitation mail | `StaffInvitationServiceTest`; `zzz-staff-onboarding.spec.mjs` | PASS |
| 5 | Staff hall assignment is bounded to the tenant and active halls | `Filament/Pages/StaffManagement.php` | `StaffManagementPageTest`; staff browser journey | PASS |
| 6 | Owner and Staff privileged access requires MFA and verified email | Existing Filament MFA/authentication | Owner/Staff authenticated browser journeys | PASS |
| 7 | Public site fails closed to HTTP 503 when the installation has no signed license | `PublishedVenuePageController` release gate | `PublicSiteMaintenanceHttpTest`; `CentralPublicLicenseReleaseGateTest` | PASS |
| 8 | Restricted dashboards and historical financial access follow license state without breaking authorized audit/history | Central release-gate middleware and historical invoice routes | `CentralPublicLicenseReleaseGateTest`; historical route assertions | PASS |
| 9 | 15-day verification warning, seven-day management grace and expired restrictions are visible and enforced | Licensing page + signed status cache | `zzz-licensing.spec.mjs`, `zzz-licensing-grace.spec.mjs`, `zzz-licensing-expired.spec.mjs`; `venuedesk:test-license-window` | PASS |
| 10 | Central paid preparation requires an independent authorised payment, customer scope and idempotency | `central/tests/staff_paid_license_preparation.php` and Central services | Central PostgreSQL CI job | PASS |
| 11 | Grant issuance is Ed25519-signed by the licensed officer path and never accepts tampering/rollback | `SignedGrantDeliveryService`, VenueDesk `CentralSignedGrantVerifier` | `CentralSignedGrantVerifierTest`; Central delivery tests | PASS |
| 12 | Signed receipts are verified, pinned and cacheable with exact nonce/claim validation | `CentralSignedStatusReceiptVerifier`, `CentralSignedLicenseCache` | `CentralSignedStatusReceiptVerifierTest`; licensing suite | PASS |
| 13 | Renewal preserves the prior term, increments revision and starts at the prior expiry | `CentralVerifiedLicenseDeliveryService`, `CentralSignedLicenseCache` | `CentralLicenseDeliveryIntegrationTest`; `CentralSignedGrantVerifierTest` | PASS |
| 14 | CI transport uses the configured trusted Central base URL, not a hardcoded host | `CentralInstallationStatusTransport`, `config/central_control.php` | `CentralStatusTransportIntegrationTest` | PASS |
| 15 | Public maintenance/restricted release gates are default-OFF and test-only activation is isolated | Config defaults; guarded local status server and `/__test/license-window` controller | `PublicSiteMaintenanceHttpTest`; six-job browser CI | PASS |
| 16 | Customer deposit recording is idempotent, overpayment-rejecting and receipt-numbered | `VenueInvoicePaymentService` | `VenueInvoiceServiceTest` idempotency/balance cases | PASS |
| 17 | Financial journals stay balanced and immutable; refunds require approvals | Finance migrations, `VenueInvoiceRefundService`, approval workflow | `VenueInvoiceServiceTest`; finance control tests | PASS |
| 18 | Two independent processes cannot both apply deposits exceeding the invoice balance | `VenueInvoicePaymentService` company-row lock | Isolated `VenueInvoicePaymentConcurrencyTest` on dedicated `venue_finance_test`, two real PHP workers | PASS |
| 19 | Competing-deposit certification proves one receipt, one journal, rollback of the loser, idempotent retry, recovery payment, tenant isolation and immutable audit | Dedicated isolated DB + worker script | `VenueInvoicePaymentConcurrencyTest`; `tests/Support/payment_worker.php`; Laravel CI finance step | PASS |
| 20 | All six required CI jobs pass at the final source SHA | `.github/workflows/ci.yml` | Final branch run to be recorded after the docs commit | REQUIRED AT FINAL SHA |

## Explicitly out of Phase 9

- Phase 10: public website design/productization, mockup-perfect alignment, content production.
- Phase 11: provider-side payment settlement, bank/provider credentials, reconciliation with external providers.
- Production prerequisites: DNS/HTTPS, KMS/HSM, real signing keys, restore drill, Owner/Manager UAT and operator/security sign-off.

## Release gates

`CENTRAL_API_ENABLED`, `CENTRAL_STAFF_PUBLIC_PORTAL_ENABLED`,
`VENUE_CUSTOMER_PAYMENT_ENABLED`, `VENUE_FINANCE_POSTING_ENABLED`,
licensing activation and public deployment remain OFF in production until the
external release prerequisites are evidenced and approved.

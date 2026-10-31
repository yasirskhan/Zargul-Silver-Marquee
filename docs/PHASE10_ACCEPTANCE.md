# Phase 10 — Public website productization

Status: **IN PROGRESS**, not accepted.

Approved requirement IDs: `WEB-010`, `WEB-011`, `WEB-012`, `BOOK-010`.

## Implemented in this batch

| Requirement | Status | Evidence |
|---|---|---|
| WEB-010 — distinct responsive website themes | PASS: approved structural presets (`classic`, `editorial`, `minimal`) with colour/typography presets and responsive desktop/tablet/phone browser acceptance | `zzz-public-website.spec.mjs`, `SiteBrandingValidator`, `WebsiteWorkspace`, `published-page.blade.php` |
| WEB-011 — per-hall contact, location and maps footer | PASS: immutable address/phone/WhatsApp, Maps directions, `tel:` and `wa.me` links, hall cards and responsive browser acceptance | `SiteBrandingService`, `SitePublishedSnapshotService`, `zzz-public-website.spec.mjs`, `SitePublishedHtmlTest` |
| WEB-012 — central per-client designer credit | PARTIAL: optional `designer_credit` claim is signature-bound to customer, installation, domain and revision; invalid/tampered/disabled credit fails closed or is hidden; exact claim keys, control characters, credentials and malformed hosts are rejected; public footer renders only enabled HTTPS credit. Central operator issuance UI remains. | `CentralSignedGrantVerifierTest::test_signed_designer_credit_is_validated_and_cannot_be_forged`; `SitePublishedHtmlTest::test_signed_designer_credit_renders_only_when_enabled_and_safe` |
| BOOK-010 — exact guest tiers, one-hall selection and 5% verified-payment hold | IN PROGRESS: versioned Owner-configured exact guest-count price brackets, server-side quote lookup, PostgreSQL update/delete immutability and an authorized Owner-only pricing page with tenant/role/forged-selection tests implemented; public journey, holds and verified deposit remain. | `HallGuestPricingService`, `hall_guest_pricing_revisions`; `HallGuestPricingServiceTest` |

## Safety boundaries preserved

- Published renderer uses only immutable published revisions.
- Theme structure is a fixed preset; no arbitrary CSS, JavaScript or HTML.
- Hall contacts come only from active halls belonging to the company.
- Maps links are outbound HTTPS directions; no untrusted map embed.
- Public enquiry remains an enquiry, not a booking, hold, invoice or payment.

## Remaining before Phase 10 acceptance

1. Finish WEB-010 responsive structural acceptance across desktop/tablet/phone.
2. Finish WEB-011 responsive browser acceptance.
3. Implement WEB-012 with a signed, centrally governed designer-credit contract.
4. Implement BOOK-010 guest tiers, one-hall automatic selection, versioned pricing and verified 5% claim.
5. Run all six CI jobs at the final Phase 10 commit and record the exact SHA.

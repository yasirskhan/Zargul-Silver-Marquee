# Phase 10 — Public website productization

Status: **IN PROGRESS**, not accepted.

Approved requirement IDs: `WEB-010`, `WEB-011`, `WEB-012`, `BOOK-010`.

## Implemented in this batch

| Requirement | Status | Evidence |
|---|---|---|
| WEB-010 — distinct responsive website themes | PARTIAL: approved structural `layoutPreset` (`classic`, `editorial`, `minimal`) added to branding validation, Owner Workspace, and the published renderer; colour/typography presets remain | `SiteBrandingValidator`, `WebsiteWorkspace`, `published-page.blade.php`; `SitePublishedHtmlTest::test_phase10_layout_preset_and_hall_contacts_render_from_published_snapshot` |
| WEB-011 — per-hall contact, location and maps footer | PARTIAL: hall contacts are frozen into the approved branding revision; editing a live hall does not alter the public site until the Owner saves and publishes branding. Rendered as hall cards and footer contacts with safe directions and `tel:` links. WhatsApp is validated and rendered as a safe `wa.me` link; the Owner setup wizard captures it. | `SiteBrandingService`, `SitePublishedSnapshotService`, published Blade views; `SitePublishedHtmlTest::test_hall_contact_changes_require_a_new_branding_publication` |
| WEB-012 — central per-client designer credit | NOT STARTED | Requires an approved HAFZATECH-signed designer-credit field and safe propagation. Not fabricated locally. |
| BOOK-010 — exact guest tiers, one-hall selection and 5% verified-payment hold | NOT STARTED | Requires approved guest-bracket pricing and a verified deposit claim integrated with booking holds. Existing enquiry is explicitly not a booking and takes no payment. |

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

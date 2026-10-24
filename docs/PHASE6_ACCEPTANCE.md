# Phase 6 acceptance — public website and enquiries

Status: **TECHNICALLY VERIFIED for the published-site renderer, secure enquiry intake and owner triage. Public launch, real venue media and operator acceptance remain separate and OFF.**
Verified source: `59beb429586ba76369e50ca34e41eee18f622dbc`
Hosted CI: [run 40119020374](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40119020374) — **all six SUCCESS**.

## Requirement evidence

| Requirement | Evidence |
| --- | --- |
| WEB-001 — responsive public website | Server-only published snapshot renderer with responsive shell, header/navigation/footer, hero/text/CTA/FAQ/image/gallery/columns blocks, skip link, viewport/meta/OG tags and static design tokens; public route is host-bound and fail-closed. Existing `PublishedVenueRouteTest`, `SitePublishedHtmlTest` and WebP tests cover published-only content, escaping, canonical metadata and responsive layout classes. |
| WEB-002 — customer enquiries | New consented public enquiry form with name/email/phone, hall selection, date, morning/noon/evening program, numeric guest count, message, privacy consent, honeypot, CSRF and 5/minute throttle. `PublicEnquiryController` + `VenueEnquiryService` persist company/website-scoped enquiries with SHA-256 IP hash, idempotent request UUID and immutable audit; no booking, hold, invoice or payment is created. `PublishedVenueRouteTest`, `VenueEnquiryServiceTest` and `EnquiriesLivewireTest` cover valid submission, consent/foreign-hall rejection, duplicate retry, owner triage and manager denial. |
| CMS-005 — navigation, SEO, domains | Published-only navigation/menu order, page titles/meta/canonical/robots, verified-domain gate and fail-closed host/site/company binding remain covered by existing tests. |
| CMS-006 — media | Published-only optimized WebP delivery with integrity/MIME/dimensions checks and alt text; private media paths are not exposed. |
| CMS-007 — hall/package content | Active hall names/capacities are now included in the published snapshot and selectable on the enquiry form; no private hall, customer or financial data is exposed. Package/guest-bracket presentation remains base content; precise Phase 10 pricing brackets are not claimed. |
| CMS-009 — owner publishing experience | Existing authenticated owner Website Workspace browser journey covers draft, preview, publish, unpublish and restore; owner/admin only with company/hall authorization and audit. |

## Honest boundaries

- `VENUE_PUBLIC_SITE_ENABLED` remains **OFF**; no public browser/UAT or production launch is claimed.
- Real venue photography, DNS/host approval, owner acceptance and search indexing authorization remain external.
- Phase 10 advanced themes, precise guest pricing brackets, per-hall maps/contact presentation and verified-payment 5% holds are **not** claimed.
- Enquiries are private internal leads only; they never confirm a booking, collect a deposit or charge a customer.

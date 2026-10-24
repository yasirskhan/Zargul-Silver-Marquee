# Phase 0.5 acceptance — website CMS architecture and editor usability

Status: **CODE COMPLETE / TECHNICALLY VERIFIED. Real Owner media uploads and staging acceptance remain external.**
Verified source: `63c6b7a8d2a6180c51e74f5ded2cbeb3a6a192ea`
Hosted CI: [run 40050039945](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40050039945) — **all six SUCCESS**.
Final integrated code/docs/Phase 1 baseline: `df1e5b8fb4147d5e8993549cdd0a9352875074f7`, [run 40058788632](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40058788632) — **all six SUCCESS**.

## Approved CMS-001 to CMS-004 scope

Phase 0.5 covers typed safe sections and columns, owner branding/media, the visual section/column editor, drafts/publish/version/rollback, and authentication/audit boundaries. CMS-005 to CMS-009 are Phase 6 or Phase 1 public-site work and are not claimed here.

| Requirement | Implementation evidence | Executable acceptance |
| --- | --- | --- |
| CMS-001 typed page/column schema, ordering and snapshots | `app/Services/SiteContentValidator.php`; `SitePagePublishingService`; typed section/column data in `WebsiteWorkspace`; immutable page revisions | `SiteContentValidatorTest`; `WebsiteWorkspaceLayoutTest` ordered IDs/blocks; authenticated editor save/reload |
| CMS-002 owner branding, theme, logo and media | `SiteMediaService`, `SiteMediaVariantService`, `SiteMediaPreviewController`; branding revisions/preview; private company media library | `WebsiteWorkspaceBrandingTest`, `WebsiteWorkspaceMediaTest`, `WebsiteMediaPresentationTest`; authenticated owner uploads, sees a real private thumbnail, approves the image, and adjusts crop/focal values |
| CMS-003 sections and responsive columns | `WebsiteWorkspace` section/column creation, desktop widths, desktop reorder, independent mobile order, block moves, drag handles; server-owned width presets | `WebsiteWorkspaceDragOrderTest`, `WebsiteWorkspaceLayoutTest`; authenticated owner creates two columns, sets 8/4, reorders desktop, changes phone order, saves and reloads; prototype Chromium drag test remains the pointer-drag proof |
| CMS-004 drafts, publish, versions, rollback | `SitePagePublishingService`; immutable revisions and publishing events; page/branding restore controls; explicit publish/offline/restore UI | `WebsiteWorkspaceLivewireTest`, `WebsiteWorkspaceRestoreTest`; authenticated owner saves draft, publishes, takes offline, restores revision 1, and reloads the latest draft |

## Media and preview controls

- The media library shows actual company-authorized private thumbnails through `/site-media/{uuid}`; the endpoint returns a generated WebP and enforces active/verified membership plus company edit/publish access.
- Image blocks and branding logos/favicons render through that endpoint in private previews. No raw object key or direct disk URL is exposed.
- Image blocks store `focalX`, `focalY` and `cropAspect` in the draft revision. The preview uses only bounded numeric focal values and an allow-listed crop aspect; changes publish only with the page revision.
- A replacement is staged as a new media ID. Published revisions keep the old asset and bytes; retirement is refused while a published revision still references the asset.
- Draft-only media can be retired. No media change bypasses the existing company scope, approval or role checks.

## Boundaries

- Synthetic images are used in development. Real venue photographs/logos must be uploaded by the Owner through the system; that real-media acceptance is external.
- No public deployment, real customer data, payment collection or production release flag is enabled by this acceptance.
- CMS-005 to CMS-009 (public website menus/SEO/domains, enquiry forms, full public delivery and real-owner browser acceptance) remain later Phase 6/1 scope and are not marked complete here.
- Phase 12 operational acceptance remains separate and open; this does not promote Phase 13.

## Verification method

The cited run belongs to the exact source SHA and all six required jobs completed `success`. Green CI certifies the covered source only; real Owner media/staging acceptance remains a separate external gate.

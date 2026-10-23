# Website Content Management and Visual Page Builder
Status: APPROVED SCOPE / PARTIALLY IMPLEMENTED IN GUARDED BACKEND — 2026-10-23; OPERATOR LAUNCH PENDING
Related: docs/PROJECT_MASTER_SPEC.md, docs/FEATURE_REGISTER.csv

## Product promise
The Zargul Silver Marquee public website must be editable by an authorized venue owner or designated website administrator WITHOUT editing code. It is part of VenueDesk, not a disconnected CMS. The operator should be able to change:
- Site identity: venue/business display name, logo, favicon, brand colors, typography presets, social/contact details, footer.
- Content: menu and navigation, home page hero/title/buttons/photos, every normal heading and paragraph, halls, pages, amenities, galleries, customer-facing packages, FAQs, testimonials (only approved authentic ones), SEO titles/descriptions and sharing images.
- Layout: insert, duplicate, hide or delete sections; drag entire sections up/down; add 1/2/3-column rows; drag blocks between columns; resize desktop column proportions within approved layouts; reorder mobile stacking independently; choose spacing, background themes, alignment, image cropping and permitted responsive visibility.
- Media: upload, crop, set focal point, replace, tag, sort and retire images. Asset library tracks author, approved usage, alt text, sizes/variants; scan file type, size and signatures, use private staging and publication-safe public assets.
- Dynamic commerce blocks: hall cards, public availability enquiry, packages and services map to approved live catalog and backend access rules. Page editor can position these blocks but cannot override pricing, hall booking capacity, permissions, or reservation validity.
- Multi-page: Home, Halls, Hall Details, Packages, Services, Gallery, About, Contact, Privacy, Terms; reusable header/footer and navigation.
- Preview and release: desktop/tablet/mobile preview, drafts, preview links with expiring signed permissions, revision comments, compare changes, publish approval when required, scheduled publish/unpublish, previous-version rollback and publishing history.

## Day-to-day screen flow (Owner/Admin)
VenueDesk > Website > Overview > Pages > Edit Page > Visual Builder.
Editor:
  Left: page/sections outline, "+ Add Section" searchable section templates, drag handles.
  Center: responsive live preview with desktop / tablet / phone toggle and visual column drag/reorder controls.
  Right: fields for selected block (text/photo/button/columns/color/spacing/alignment/visibility).
  Footer/top: Save Draft, Preview, Publish, Version History, Restore Previous Version.
  Separate website tabs: Branding, Header/Menu, Media Library, Halls & Packages, SEO, Forms/Enquiries, Domain & Integration, Analytics, Audit.
  Role-based dashboard should show website publishing tasks but not overload booking staff.

## Default templates
Hero image/title/button; image-text split; image gallery; hall list; packages; services grid; metrics only with verified figures; testimonials only with real consent; CTA; FAQs; 1/2/3-column custom sections; map/contact; validated enquiry form.
Use well-designed templates. Owners can drag blocks and move columns. Avoid unrestricted raw code or arbitrary executable HTML to prevent XSS, layout breakage, and privilege bypass. Advanced custom-code deployment requires developer-controlled review and cannot be injected from normal CMS fields.

## Data model proposal (PostgreSQL, not implemented)
- site_settings(tenant_id, website_id, theme_tokens, logo_media_id, favicon_media_id, site_contact, ...);
- website_domains(website_id, hostname, verified_at, status, ssl_state);
- site_pages(id, website_id, slug, title, seo_fields, status, published_revision_id, created_by, updated_by);
- site_page_revisions(id, page_id, revision_number, content_json, author_id, created_at, message, state);
- site_media(id, tenant_id, object_key, mime, checksum, variants, alt, focal_point, created_by, status);
- site_navigation(id, website_id, location, ordered_items_json, revision);
- site_publishing_events(id, page_id, source_revision_id, previous_revision_id, actor_id, action, timestamp, audit_event_id).
- site_form_submissions with consent/protection, tenant and anti-spam checks. Leads are routed to internal enquiries, not directly into confirmed bookings.
Atomic compare-and-swap on draft revision and publish pointer. Publishing should invalidate CDN/page caches after transaction commits; the previous published snapshot remains intact on failure.

## Validation, authorization and performance
- Tenant owner may manage OWN website; separate capability for site_editor and site_publisher; editors cannot alter production configurations or other tenants.
- Validate/sanitize content on backend. Render using strict typed known Blade components with escaped text and known, checked media references; prevent arbitrary CSS/JS and unsafe links.
- File uploads limit extensions, MIME, size, pixel dimensions, content scans; generate optimized WebP/AVIF variants with fallback; replace image without breaking older published revision.
- Each draft save, block drag/reorder, media replacement, publish, unpublish and rollback must record actor/time, before/after or canonical diff, reason, source revision.
- User can undo mistakes, unpublished edits never alter production pages; publish is atomic and auditable. Conflict detection if two editors change one page simultaneously.
- Accessibility: semantic landmarks, alt texts, keyboard block reordering, sufficient contrast, focus visibility and appropriate heading order.
- Response design: 360px+ phone; 768px tablet; wide desktops. Columns collapse to mobile order and maintain readability.
- Search engine controls: redirects, canonical URLs, XML sitemap, Open Graph, robots and index control. Review performance / LCP / image size and no layout shift.
- Audit, data-backup and rollback validations are required BEFORE live production launch.

## Build order
CMS-001 schema/block validator and pure reorder/version tests;
CMS-002 PostgreSQL page/media/revision models + migrations + company/hall access policies;
CMS-003 Filament owner Site Settings, Branding, Media Library;
CMS-004 visual section/column editor, responsive preview and templates;
CMS-005 save draft, compare, publish/unpublish, history, rollback, optimistic locks;
CMS-006 public page renderer connected to approved services and halls;
CMS-007 forms/enquiries to backend with rate limits, anti-spam and privacy;
CMS-008 SEO/domain, responsive E2E, media/content security tests;
CMS-009 owner acceptance with real site assets and rehearsal publish rollback.

## Acceptance examples
- Owner replaces site logo; preview updates, public site changes only after publishing.
- Manager with site_editor permission edits draft but cannot publish without site_publisher.
- Two editors working on the same revision cannot silently overwrite each other's changes.
- Drag a section, move an image column to left, change widths, preview phone order; saved revision reproduces structure.
- Removing an image in a draft cannot break published page; old version can be restored.
- Customer enquiry enters the correct tenant staff inbox; no public customer data exposure.
- User cannot insert script/javascript: links or access another business's assets/pages.
- Public website still works when CMS/editor/backend job queue is temporarily unavailable (serve last published snapshot).

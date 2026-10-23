# Zargul Silver Marquee / VenueDesk

VenueDesk combines a customer-facing responsive venue website, an authenticated Laravel/Filament management application, and a separately gated HAFZATECH central licensing/service control plane.

## Source-of-truth environments

- **`backend/`** is the actual Laravel application, PostgreSQL migrations, authorization policies, booked/owned stock and financial services, and authenticated owner/manager website editor. Its feature flags default OFF until independent operational acceptance.
- **`central/`** is a separately restricted central licensing and support implementation. It is not the venue's public site or live payment provider.
- **`index.html`, `admin.html`, `website-editor.html` and `assets/`** are original static user-interface prototypes. They are design references and must never be used to authenticate staff, approve reservations, collect money or certify physical stock. Real production pages must come from guarded backend services.
- **`docs/PROJECT_MASTER_SPEC.md`, `docs/ROADMAP.md`, `docs/FEATURE_REGISTER.csv`** define approved scope; **`AGENTS.md`, `docs/SESSION_CONTROL.md` and `AI_HANDOFF.md`** govern the single-writer development branch.

## Checks and release

Run the Node prototype checks with `npm test`. The branch-filtered GitHub Actions workflow also runs real PostgreSQL/Laravel tests, tenant/security checks, and editor and authenticated-panel browser suites. An exact-source six-job success is required for technical certification; green CI alone does not authorize live releases.

Work only on **`chatgpt/phase0-foundation`**. Never modify `main` or another repository without separate authorization. Production release flags, HTTPS deployment, credentials, real bank/partner verification, operator testing and coordinated PostgreSQL/private-file restore remain separate approval gates.

This repository must not contain production credentials, real payment proof or personal customer information.

# Phase 9 central staff portal — first verified milestone

Status: **GATED FRONT-CONTROLLER MILESTONE VERIFIED. Default OFF; external DNS/HTTPS, KMS, central database credentials and operator signoff remain BLOCKED.**
Verified source: `fbc13a519b7cb6a4fc96f3ea86b3a46e0c3d5c5f`
Hosted CI: [run 40134105446](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40134105446) — all six SUCCESS.

## Implemented source

- `central/public/staff.php`
  - new, separately gated HAFZATECH staff front controller;
  - requires `CENTRAL_STAFF_PUBLIC_PORTAL_ENABLED=true`;
  - trusted HTTPS host must be exactly `lichall.hafzatech.com` via `CentralStaffHttpPolicy::secureHost`;
  - renders a separate vendor-only login page with staff email, password and six-digit TOTP;
  - guards `/staff` and `/staff/login` without exposing secrets;
  - delegates all POST `/staff/*` requests to the existing `CentralStaffPortalOperatorWiring` + `CentralStaffPortalOperatorApp`, preserving staff sessions, CSRF, MFA, roles, customer directory, license queue, billing, support and audit services;
  - requires an external `CENTRAL_STAFF_KMS_BOOTSTRAP` file returning `decrypt_totp` and `encrypt_support_reply` closures; no KMS/signing secret is stored in the repository;
  - secure cookies, no-store HTML, restrictive CSP, no JSON secret serialization.
- `central/tests/staff_public_portal.php`
  - proves default-off denial;
  - proves trusted-host login page and required TOTP field;
  - proves wrong-host denial and no secret material in login HTML;
  - proves POST fails closed without the external KMS bootstrap.
- `.github/workflows/ci.yml`: runs the new central test before the existing operator-wiring tests.

## Working user actions (when operator-configured)

- Open `/staff/login` on the trusted HTTPS host.
- Submit staff email/password/TOTP through the existing credential/session boundary.
- Receive a Secure HttpOnly host-only staff cookie and same-origin CSRF-proof page.
- Use the existing private portal endpoints for home, customer/installation directory, license queue, billing, support, staff/role and audit history according to current central role permissions.
- Sign out through the existing session logout endpoint.

## Still required / external

- Real central PostgreSQL deployment, trusted HTTPS vhost, operator-supplied KMS bootstrap and central staff seed accounts.
- Authenticated browser journey against a deployed isolated portal (not just CLI safety tests).
- Client installation wizard extension of `InstallationSetup`.
- External DNS/KMS/bank/provider credentials and production activation remain OFF.

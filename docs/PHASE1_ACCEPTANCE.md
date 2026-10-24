# Phase 1 acceptance — secure server foundation

Status: **TECHNICALLY COMPLETE AND VERIFIED. External email-provider delivery, off-site audit backup/restore and operator acceptance remain separate.**
Verified source: `4024399e8ca6cea68b680fb72d2e08756225d822`
Hosted CI: [run 40064675256](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40064675256) — **all six SUCCESS**.

## Requirements and evidence

| Requirement | Evidence |
| --- | --- |
| AUTH-001 — owner/admin/staff authentication, MFA and verification | Filament panel enforces app MFA with recovery codes, email verification and profile management; `User` implements `MustVerifyEmail`, `HasAppAuthentication`, `HasAppAuthenticationRecovery`; persisted active/verified checks in `TenantAccess`, panel access and policies; authenticated browser suite exercises real password + TOTP owner/manager/operations login. |
| AUTH-001 — operational email readiness | New `venuedesk:auth-readiness` command reports mailer/from address/APP_KEY, MFA interfaces, active verified owners and MFA enrolment without sending mail or changing data. `AuthReadinessCommandTest` verifies pass/fail-closed behavior. Real provider credentials/delivery remain external. |
| TEN-001 — company/hall isolation | `Phase1TenantIsolationTest` proves foreign-company website edit/publish denial, owner/admin hall configuration scope, manager assignment-only hall access and operations assigned-hall scope; existing service/policy tests cover finance, inventory, audit, overview and hall operations. |
| AUD-001 — append-only searchable audit | Owner/admin scoped search remains immutable and Livewire-tested. New encrypted owner export UI prepares a 31-day maximum ciphertext archive, a private download route returns ciphertext only and writes `audit.export_downloaded`, and `venuedesk:verify-audit-export` decrypts/verifies structure and optional SHA-256 for recovery drills. Off-site secure storage, key custody and operator restore remain external. |
| CMS-008 — website authorization and audit | `SitePagePolicy`/`TenantAccess` enforce per-company edit/publish scope; media stage/approve/replace/retire and preview access are company-authorized and audited; audit export covers website events. |
| UX-001 — role-scoped overview/navigation | `VenueOverviewLivewireTest` and `InternalQuickSearchTest` cover owner/admin aggregate, manager and operations assignment scope, private navigation and no contact/finance leakage. |
| PLAT-005/006 — framework and reproducible dependencies | PHP 8.3, `laravel/framework` v13.36.0, `filament/filament` v5.10.2; `composer.lock` is used by the reproducible CI install; migrations and PostgreSQL tests run in the six-job workflow. |

## Explicit external/operational gates

- Real SMTP/provider credentials and delivery verification.
- Approved off-site audit archive storage, retention, key custody and restore drill.
- Security signoff, backup/restore acceptance and production release approval.
- Phase 12 operational acceptance remains separately open; no production flag is enabled.

## Verification method

The cited run belongs to the exact source SHA and all six required jobs completed `success`. Green CI certifies the covered source only; external provider and operator acceptance are not fabricated.

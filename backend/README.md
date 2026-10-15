# VenueDesk Laravel backend — Phase 1 foundation

This separate directory isolates the real backend scaffold from the existing static public-site and employee UI prototypes. It uses Laravel 13, Filament 5, PHP 8.3+, and PostgreSQL.

Current implemented scope: an application entrypoint, initial Filament admin shell, users, companies, halls, company memberships, hall-scoped staff assignments, server-side tenant access methods, and a PostgreSQL migration/test foundation. No live booking service, public CMS persistence, financial ledger, owner MFA configuration, or Telegram integration exists.

For development: copy .env.example to .env, set a real local secret, run `composer install` using the committed `composer.lock`, then migrate a development PostgreSQL database. Never publish this backend or use real customers before the release gates.

The PHP 8.3 dependency lock is committed and hosted CI verifies `composer install` from it. Update dependencies only in a separately reviewed, tested lockfile change. No credentials belong in Git.

Backend code is intended to be maintained alongside the approved UI prototypes, not substituted for them.

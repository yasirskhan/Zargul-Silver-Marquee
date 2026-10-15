# VenueDesk Laravel backend — Phase 1 foundation

This separate directory isolates the real backend scaffold from the existing static public-site and employee UI prototypes. It uses Laravel 13, Filament 5, PHP 8.3+, and PostgreSQL.

Current implemented scope: an application entrypoint, initial Filament admin shell, users, companies, halls, company memberships, hall-scoped staff assignments, server-side tenant access methods, and a PostgreSQL migration/test foundation. No live booking service, public CMS persistence, financial ledger, owner MFA configuration, or Telegram integration exists.

For development: copy .env.example to .env, set a real local secret, run Composer to generate vendor dependencies and composer.lock, then migrate a development PostgreSQL database. Never publish this backend or use real customers before the release gates.

The first CI batch may resolve Composer dependencies without a committed lock file. **Before production or release, commit a vetted composer.lock and change CI to composer install with a locked dependency graph.** No credentials belong in Git.

Backend code is intended to be maintained alongside the approved UI prototypes, not substituted for them.

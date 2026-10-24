# Phase 8 pilot readiness — technical preparation

Status: **TECHNICAL PREPARATION VERIFIED. REAL STAGING, OWNER/MANAGER UAT, BACKUP RESTORE AND INDEPENDENT OPERATOR ACCEPTANCE REMAIN BLOCKED/OPEN.**
Verified source: `cb04416a264ee73dd003af64e2da1e655bf7ea0e`
Hosted CI: [run 40127753617](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40127753617) — **all six SUCCESS**.

## Implemented software-side readiness

- New `venuedesk:pilot-readiness` command (`--json` supported) fails closed unless:
  - environment is not production;
  - every booking, finance, invoice, payment, quote, refund, event, public-site and central activation flag is OFF;
  - the database driver is PostgreSQL and required foundation tables exist;
  - the storage root is writable;
  - at least one active, verified owner exists;
  - role coverage is reported for owner/admin/manager/operations/reception.
- Reports warnings for missing pilot roles, private storage not yet provisioned, absent MFA enrolment and non-delivering mailer configuration.
- `PilotReadinessCommandTest` proves the pass path and failure when a release flag is enabled.

## Operator checklist (still external)

1. Provision isolated staging PostgreSQL, private document storage and HTTPS host.
2. Create synthetic owner/admin/manager/operations/reception accounts with real TOTP MFA.
3. Run `php artisan venuedesk:pilot-readiness --json` and retain output.
4. Complete paired PostgreSQL/private-document backup and restore into a clean staging instance.
5. Execute booking → calendar → quote/invoice/payment → approval → website enquiry → event execution → closeout scenarios with synthetic data only.
6. Verify tenant/company/hall isolation, revocation, concurrency, idempotency and failure recovery.
7. Record defects, retests, operator participants, timestamps and independent signoff.

## Honest boundary

No real staging environment, backup restore, Owner/Manager UAT, real staff attendance, customer payment, provider/bank settlement or operator signoff is claimed. All production flags remain OFF. Phase 8 remains **BLOCKED EXTERNAL** until the checklist evidence exists.

Phase 9 Central Control Center work remains the next software phase; external DNS/HTTPS, KMS/HSM, bank/provider credentials and production activation remain blocked and separate.

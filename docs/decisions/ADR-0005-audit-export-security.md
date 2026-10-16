# ADR-0005: Internal Audit Archive and Export Security

Status: PROPOSED ONLY, NOT APPROVED FOR OPERATIONAL EXPORT OR PRODUCTION BACKUP.
Date: 2026-10-16.

## Purpose
VenueDesk must preserve searchable, tamper-resistant audit entries and eventually support controlled secure export and independently verifiable backup/restore, without exposing other tenants or customer/financial information to unauthorized employees.

## Current bounded internal code
- Only authenticated, email-verified, active owner/admin may query internal audit history of their own currently active company. Filament Audit History page is read-only and denies revoked access on new requests.
- AuditExportService creates an internally returned Laravel-APP_KEY-encrypted JSON package for an explicit date window up to 31 inclusive days and at most 500 results. It records who prepared it, the count and SHA-256 digest in append-only audit history. A failed audit write aborts package preparation.
- There is no web download, sharing endpoint, archive persistence, archive import/restore, separate export key, key rotation path, operational access management or S3/cloud backup. Treat generated ciphertext as an internal service artifact only, NOT as a safe production backup.
- Current paginated search is NOT a guaranteed immutable point-in-time snapshot under simultaneous audit inserts; export consistency and duplicate request handling must be hardened before release.

## Pending decisions and release requirements
1. Owner-approved retention and deletion schedule, lawful customer privacy handling and allowable exported fields; coordinate with ADR-0004.
2. Dedicated encryption and key custody with documented rotation and recovery; separation of production APP_KEY, database credentials and archive keys.
3. Secure private object storage, access-control logging, signed short-lived owner/MFA download, least-privilege read access and a no-public-URL policy.
4. Stable snapshot export under concurrent inserts, idempotent request identity, pagination integrity, maximum package size and abuse throttling.
5. Independently administered tamper-evident write-once retention and off-site backup; encrypted regularly tested restore, disaster-recovery objectives and operation signoff.
6. Dedicated live-panel and authorized browser security tests plus retention/access logs before enabling downloads.

## Explicit restrictions
No real customer data export, production backup guarantee, public audit API, staff-initiated download, or release authorization follows from the prototype. Do not allow user-entered free-text audit reasons to expose customer identity in exported or messaging records. Do not connect Telegram to audit content without separate approval.

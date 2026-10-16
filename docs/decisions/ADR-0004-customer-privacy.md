# ADR-0004: Initial Tenant-Scoped Customer Contact Records

Status: PROPOSED security/privacy baseline, not accepted for production use.
Date: 2026-10-16.

## Purpose
Phase 3 needs customer identity links for future enquiries, reviewed quotations, bookings and universal search. Customer data is sensitive. Until venue consent and retention decisions are approved, no public intake, bulk import, booking confirmation, exports or business deployment may populate this data.

## Initial internal design
- One customer profile belongs to exactly one company; composite company/customer foreign keys protect history ownership. No cross-tenant customer search.
- Sensitive name, email and phone are stored as application-encrypted ciphertext. Do not store plaintext contact in immutable events, general audit context, query logs or error messages.
- Only currently authenticated, verified and active owner/admin staff may read/create/change profiles in the first service implementation. Manager, reception and cashier access is deferred until booking/hall association and privacy scope are specified, regardless of menu visibility.
- Staff changes use an optimistic revision token, a transaction, append-only redacted history and an independent audit record; a failed audit must roll back the contact write. No physical deletion or silent history rewrite.
- A retry carrying the same record key and exact same creator/contact may be treated as idempotent; another payload must conflict.

## Decisions that must be approved before any real personal information
- Legal basis/consent wording, data minimization, permitted collection fields, customer access/correction requests, data retention/deletion and right-to-erasure operations.
- Key custody/rotation and encrypted backup/restore verification; separation of database and application secrets; audit export retention.
- Authorized company and hall access for managers, reception, cashier and any cross-hall CRM search; least-privilege PII display masking.
- Duplicate detection/search strategy for encrypted fields (e.g. keyed blind indexes) and anti-abuse monitoring.
- Public enquiry anti-spam, rate limits, notification policies and contact verification.
- Operational procedures and owner acceptance.

## Explicit non-authorization
This proposal is a schema/internal service foundation only. It does NOT authorize collecting real customer data, deploying a public form, importing old customer lists, confirming bookings, pricing quotations or using personal details in Telegram messages.

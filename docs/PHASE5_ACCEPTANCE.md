# Phase 5 acceptance — approvals and Telegram integration

Status: **TECHNICALLY VERIFIED for the internal approval center, scoped delegation and Telegram backend/UI wiring. Live Telegram credentials, operator pilot and any real financial application remain separate and OFF.**
Verified source: `ef6217b8e8097609131385ec83a976eacb16fd56`
Hosted CI: [run 40113341018](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40113341018) — **all six SUCCESS**.

## Requirement evidence

| Requirement | Evidence |
| --- | --- |
| APR-001 — discount/refund/waiver/free-service/expense approval | Company-scoped immutable approval requests with validated categories, linked booking proposal rules, pending/approved/rejected inbox, exact-reference lookup, status/category filters, bounded pagination and immutable decision records. PostgreSQL/Livewire tests cover no self-approval, stale revision conflict, role/tenant isolation and idempotent decision behavior. New authenticated owner browser journey selects a pending non-self expense request, records an approval reason and verifies the approved count; no quote, invoice, payment or financial posting is applied. |
| APR-002 — private Telegram owner alerts and login popup | Pairing-secret webhook, bounded signed receipt/dedup, owner-only pairing/revocation, private owner login alert and in-dashboard attention; `TelegramApprovalAlertServiceTest` and owner-only regressions. Live credentials are absent, so the browser asserts the explicit “Telegram is not configured” state rather than fabricating delivery. |
| APR-003 — emergency delegation, limits and expiry | Owner-only grants to active admins with category ceiling, seven-day maximum, start/end window, no self-approval and immediate revocation; delegated decision eligibility checks and immutable grant/audit history are PostgreSQL/Livewire-tested. |
| Authorization/audit | Approval Center reads persisted active/verified accounts, company/hall scope and role; manager views are own-scoped, owner/admin company-scoped; decisions and delegations are immutable and auditable. |

## Browser acceptance

- Real owner password + TOTP: pending cross-actor expense approval reviewed and approved with a decision reason; approved total updates; Telegram remains explicitly unconfigured.
- Existing owner/manager/operations browser journeys cover private navigation, role-scoped search and finance/booking entry points.

## Remaining separate gates

- Real Telegram bot token/chat credentials, pairing pilot and operator monitoring.
- Actual discount/refund/waiver application to quotes/invoices/payments; those remain gated and are not claimed here.
- Security/operator acceptance, production release and all production flags remain OFF.

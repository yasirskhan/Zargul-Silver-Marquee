# ADR-0006: Opt-in Private Telegram Approval Notifications

Status: PROPOSED for controlled pilot; no production Telegram credentials or real customer information approved.
Date: 2026-10-16.

## Scope and trust boundaries
Telegram is a supplementary notification transport, **never** an approval authority or payment gateway.
Only an active, verified tenant **owner** may generate a one-time private Telegram bot pairing link while signed in to VenueDesk.
The bot webhook must present a configured secret header and an HTTPS connection in production. Its single-use, ten-minute link is redeemed only by a non-bot sender whose Telegram sender ID exactly matches a private chat ID; untrusted group/supergroup/channel messages never create subscriptions. Pairing stores the opaque private chat ID under Laravel encryption, and stores a keyed fingerprint for uniqueness. Webhook update IDs are immutable and deduplicated.

No customer names, phones, emails, balances, discount amounts, quote figures, notes, private booking fields or credentials are sent through Telegram. Alert text contains only a generic "approval updated" notice and a fixed HTTPS VenueDesk approval-center link. The link requires ordinary Filament sign-in and mandatory app TOTP/recovery MFA and server policy checks. Bot commands cannot accept, reject, refund or alter prices.

## Operational delivery limits
Transport is disabled by default and requires environment-specific BotFather token, bot username, webhook secret, HTTPS application URL, and an owner completed pairing process. Credentials are not stored in git or audit. The server must configure and authenticate the Telegram webhook out of band.

A transactional immutable company-scoped outbox captures internal requested/decided events. Each recipient/event has a unique mutable delivery receipt and at most three bounded attempts. The sender locks owner links and delivery rows, rechecks active verified owner/company access and opt-in status before a send. Failed requests are not claimed delivered. Successful sends are deduplicated by receipt. **Bot API sendMessage has no idempotency key**: a crash after Telegram accepted the message but before the DB receipt committed may produce a duplicate notification on retry. Never present this as exactly-once remote delivery.

Owner may revoke a link immediately; retries skip unlinked or revoked owners. Owners must protect their one-time pairing links; possession of an unused code can authorize pairing, so no forwarding. The production pilot must validate bot token custody/rotation, alert consumer scheduling and operational retry monitoring, known-good webhook ingress/network/TLS, account disable/revocation, and owner-visible opt-out. Configured Telegram support does not authorize changes to tax/pricing or real payments.

## Remaining gates
The production site, private-chats user consent/retention/privacy operations, verified delivery to real owner test accounts, notifications visible promptly after sign-in, operational monitoring and acceptance must be approved and tested before turning on live alerts.

# Support reply changes contract (internal only)

This is a read-only foundation, not a live HAFZATECH API route. Both
CENTRAL_SUPPORT_PULL_ENABLED and central API deployment are OFF. Every poll is
signed with the installation Ed25519 key, including these newline-separated
fields without trailing newline:

HAFZATECH-SUPPORT-CHANGES-V1
installation_id
customer_id
cursor
limit
nonce

A second Ed25519 HAFZATECH-INSTALL-CHECK-V1 proof binds request time/nonce and
gets replay-checked. The server returns at most 50 staff-authored customer-
scoped encrypted-at-rest replies ordered by monotonic reply-audit event ID,
with next_cursor = last returned event ID. No venue payments or bookings are
sent automatically and expired signed subscriptions do not block support.
Future client must verify a vendor-signed reply envelope before storing any
message or advancing its durable cursor. NEVER expose this raw service from a
public PHP router before secure vault custody and signed response authorization.

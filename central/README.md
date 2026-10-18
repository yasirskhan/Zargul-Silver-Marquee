# HAFZATECH central identity service (foundation, NOT LIVE)

This is a **separately deployable** PHP 8.3 / PostgreSQL central service, intentionally not registered in VenueDesk Laravel routes or wired to the VenueDesk tenant database. Deploy only its central/ directory on an isolated service host and provide a dedicated least-privilege PostgreSQL database/role and HTTPS vhost at the owner-approved trusted host \`https://lichall.hafzatech.com\`. No tenant finance records or signing private keys belong here. Sodium Ed25519 verification is required (standard PHP sodium extension).

The service includes customer/installation enrollment foundations, central staff roles and internal password/TOTP authorization primitives, protected staff MFA audit readers, and a PostgreSQL-backed session foundation. **There is still NO public HAFZATECH staff login, credential vault, verified production payment workflow, production signing release, domain deployment or functioning public staff portal.** Current client support transport is not an operator-approved production endpoint. Leave \`CENTRAL_API_ENABLED=false\` until operator security signoff. Rate limits at reverse proxy/WAF, central staff-approved owner enrollment and independent DNS domain proof must be implemented before production use.

The future authorized central customer/staff workflow must first insert a customer with status active, verify the claimed domain outside this endpoint and provision a **single-use cryptographically random 32-byte enrollment token**, persist only its SHA-256, an enrollment UUID, approved lowercase domain and verification timestamp with short expiry. Deliver the token out of band only after authenticated customer ownership/MFA and DNS proof. No self-service enrollment route exists.

Two versioned JSON POST endpoints with HTTPS required:
1. \`/api/v1/installations/challenge\` accepts \`enrollment_id\` and \`enrollment_token\`, returns 5-minute random \`nonce\`.
2. \`/api/v1/installations/register\` accepts those fields plus \`installation_id\` (UUIDv4), \`customer_id\` (UUIDv4), \`domain\` (canonical lowercase FQDN), \`nonce\`, \`public_key\` (base64url Ed25519 32 bytes) and \`signature\` (base64url Ed25519 detached 64 bytes). Signature input is UTF-8 lines separated by LF, no final LF: \`HAFZATECH-INSTALL-REGISTER-V1\`, installation ID, customer ID, enrollment ID, domain, nonce, public key. Response \`registered_pending_license\` is **not** an activated paid entitlement.

An enrollment and challenge are one-use. Failed signature / foreign customer / unverified domain / stale challenge / replay is denied and never creates a registered installation. Only operator-approved domain enrollments enter this flow. Do not expose a route to create or verify the provisioning token from untrusted web requests. Logged audit events record non-secret details; API errors never echo enrollment token, challenge secret or database exception.

For development: configure \`CENTRAL_DB_DSN\`, \`CENTRAL_DB_USERNAME\`, \`CENTRAL_DB_PASSWORD\` separately from VenueDesk, run \`php central/bin/migrate.php\`, then \`php central/tests/installation_identity.php\`. Hosted CI runs these against a completely separate \`hafzatech_central_test\` database. Do not use the VenueDesk \`venue_test\` database.

Next: customer provisioning/owner confirmation, domain TXT-proof workflow, rate limits + signed request authentication for license check-in, central staff/MFA permissions, verifiable payments and isolated grant signer. No server URL override is authorized from normal customer UI.

## Protected central staff login prerequisites

The internal staff session code is not an HTTP controller. Before any vendor login is enabled, deploy a dedicated secret-manager-backed TOTP/HMAC credential provider, trusted TLS/proxy client IP handling, pre-auth account and network throttling, secure HttpOnly/SameSite cookies, CSRF validation, session renewal/revocation and audit logging, credential lifecycle and tested operator recovery. The staff authenticator must never accept VenueDesk tenant logins or an installation signing credential as a vendor staff credential. All public launch gates remain OFF.

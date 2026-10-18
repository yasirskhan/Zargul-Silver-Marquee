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

### Staff request security policy and session-derived authorization (INTERNAL)

Use `CentralStaffSessionAuthorization` to bind every vendor capability decision to an actual centrally authenticated session rather than an HTTP-supplied staff UUID or MFA Boolean. A separate session-bound CSRF proof and host/origin policy are available as internal helpers. All central staff HTTP routing remains disabled. A future controller must enforce the exact trusted HTTPS vhost, same-origin state-changing requests, secure host-only cookies, bearer privacy, trusted origin IP, protected HMAC/MFA secrets and independent customer scope per action. These helpers alone are not a staff login portal.

### Protected staff HTTP flow adapter (default OFF)

`CentralStaffProtectedHttpBoundary` composes the internal policy, MFA-based session, independent CSRF proof, scope authorization and logout in one disabled-by-default service. It returns cookie and proof material only for a future trusted server renderer and **must never be serialized as public JSON or logged**. There is no new URL in `central/public`. HTTPS/DNS, operator-managed KMS and HMAC secrets, trusted reverse-proxy provenance, cookie writer/CSRF validation, full session audit and staff recovery are still blocking operator go-live.

### Vendor staff immutable session audit (internal)

The separate central database will maintain append-only session issued, rotated, logged-out and authorization-invalidated outcomes. Token, CSRF, password and OTP secrets are never placed in events. Application session mutations and corresponding records commit atomically and PostgreSQL rejects audit UPDATE/DELETE. Vendor staff public login remains OFF pending KMS and operator controls.

### Role-scoped session event history reader (not public)

The internal `CentralStaffSessionAuditReader` queries only safe event fields under a live central staff bearer with active `audit.read` permission. Its bounded ID cursor and subject UUID checks prevent unscoped history access. It does not create an HTTP endpoint or reuse a customer venue login. Operator KMS-backed credential handling, trusted HTTPS/proxy, audit/alerting and staff security recovery must be verified before any live staff portal.

### Internal central staff suspension (OFF for public use)
A centrally MFA-authenticated staff administrator may suspend a different active vendor employee when authorized for `staff.disable`. The independent central database writes a tamper-resistant account-decision record and revokes all that target's sessions in one transaction. Ordinary administrators cannot suspend themselves or a Super Administrator through this primitive. No public vendor staff login, staff suspension HTTP route or central KMS secret is enabled by these changes.

### Live vendor session binding for MFA history
Use `CentralStaffAuthenticatedMfaHistory` for any future protected staff portal MFA audit display. It derives the actor from current central staff session state and rechecks the existing auditor permission and pagination, never trusting a supplied actor ID or claimed MFA Boolean. No public staff API was added.

### Scoped immutable vendor account action history
`CentralStaffAccountActionReader` grants bounded safe suspension-event history solely to an active, centrally MFA-authenticated staff auditor with current `audit.read`; it rejects forged actors and revoked roles. No HTTP endpoint or private staff credentials are included.

### Staff self-password rotation (internal only)
The trusted current vendor MFA session and old password are both required to rotate its OWN secret. The new password is stored as Argon2id; all that staff member's live sessions are revoked atomically with immutable metadata-only audit. This is a private service, not a registered staff or customer API endpoint, and does not bypass operator KMS/HTTPS release gates.

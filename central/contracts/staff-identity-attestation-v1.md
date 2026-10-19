# Internal HAFZATECH Staff Identity Attestation Contract v1

**Status: synthetic-only security prerequisite, NOT an approved or deployed identity provider.**
This contract is restricted to the isolated HAFZATECH central trust boundary.
No customer VenueDesk installation may sign, supply, trust or route vendor staff identity evidence.

## Provider and challenge trust

The operator must approve and independently provision an external supervised
identity-verification provider, its verification rules and protected Ed25519
private-key custody. Operator-side wiring must pin a provider key ID and the
exact 32-byte Ed25519 PUBLIC key from a trusted configuration source, never
from a web form, assertion header, customer record or installation credential.

A current MFA-authenticated GLOBAL \`staff.create\` actor may internally issue
a five-minute challenge ONLY for a truly pending, provisioned central staff
record. The central database stores SHA-256 of the 32-byte random base64url
nonce and current stored lowercase email plus exact staff UUID, issuer and
approved provider key ID in an immutable journal. The public nonce is not a
password, identity proof or credential-enrollment grant. No trusted delivery
or real provider enrollment service has been implemented yet.

## Signed evidence wire representation

The vendor-signed external JSON envelope has exactly three properties:
\`key_id\`, \`payload\`, \`signature\`. The latter two are unpadded canonical
base64url strings containing, respectively, UTF-8 JSON and a detached 64-byte
Ed25519 signature. The signature input is:

\`HAFZATECH-STAFF-IDENTITY-ATTESTATION-V1\` + LF + exact payload JSON bytes.

The payload JSON must use precisely these eight keys and values; the verifier
rejects missing/extra fields and noncanonical JSON encoding:

- \`version\`: literal \`v1\`.
- \`purpose\`: literal \`HAFZATECH-STAFF-IDENTITY-ATTESTATION-V1\`.
- \`staff_id\`: exact lowercase UUIDv4 of the pending HAFZATECH recipient.
- \`email_sha256\`: lowercase hex SHA-256 of the CURRENT central staff email.
- \`nonce\`: exact 32-byte random unpadded base64url challenge nonce.
- \`issued_at\`: Unix UTC integer seconds, no future skew.
- \`expires_at\`: Unix UTC integer seconds, after current time and at most
  300 seconds after \`issued_at\`.
- \`evidence_ref\`: 16–128 character ASCII opaque reference, matching
  \`[A-Za-z0-9_-]\`, to operator-controlled records, not sensitive evidence.

The JSON serializer must preserve its signed byte sequence, use UTF-8 and
avoid lossy string/numeric coercion. The verifier accepts a maximum 300-second
age since issuance. An independent reviewer with a current GLOBAL
\`staff.create\` MFA session must differ from the issuer and recipient,
and both staff administrators' current grants must still be eligible.

The evidence checker accepts only an exact unexpired stored challenge and a
signature under the pinned provider key. Its one-row-per-challenge immutable
journal stores the independent reviewer and a digest of signed evidence.
Replays, foreign recipients, changed emails, unauthorized reviewers and
invalid signatures are denied.

## Explicit non-authorizations

Successful cryptographic signature checking **does not assert completed
human identity verification** and never modifies staff status, passwords,
MFA data, roles or sessions. The system has no real provider enrollment
connection, verified recipient-only challenge delivery, KMS/vault backed
password/MFA setup, independently authorized activation service, operator
recovery acceptance or live HAFZATECH vhost. All three separate feature
flags remain FALSE in deployment templates. Never enable any of them
without formal operator security review and production acceptance.

The central implementation comprises
\`CentralStaffIdentityAttestationVerifier\`,
\`CentralStaffEnrollmentChallengeService\` and
\`CentralStaffEnrollmentEvidenceService\`, with PostgreSQL synthetic
contract/regression tests. Real external assertions are NOT created by
those tests.

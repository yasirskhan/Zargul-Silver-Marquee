-- Isolated worker finalization audit. No signed grants are created by HTTP
-- enrollment routes; the protected worker uses a separate explicit release gate.
CREATE TABLE IF NOT EXISTS central_license_signature_events (
    revision_id uuid PRIMARY KEY REFERENCES central_paid_license_revisions(id) ON DELETE RESTRICT,
    license_id uuid NOT NULL REFERENCES central_licenses(id) ON DELETE RESTRICT,
    authorized_officer_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    signer_key_id varchar(64) NOT NULL,
    sealed_envelope_sha256 char(64) NOT NULL CHECK (sealed_envelope_sha256 ~ '^[0-9a-f]{64}$'),
    finalized_at timestamptz NOT NULL DEFAULT now()
);

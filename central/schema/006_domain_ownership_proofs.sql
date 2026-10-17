-- Independently provisioned DNS TXT proof, distinct from the secret one-use
-- enrollment token. A DNS record alone NEVER authorizes a central customer.
CREATE TABLE IF NOT EXISTS central_installation_domain_proofs (
    enrollment_id uuid PRIMARY KEY REFERENCES installation_enrollments(id) ON DELETE RESTRICT,
    proof_sha256 char(64) NOT NULL,
    expires_at timestamptz NOT NULL,
    verified_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS central_domain_verification_audit (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id uuid NOT NULL REFERENCES vendor_customers(id) ON DELETE RESTRICT,
    enrollment_id uuid NOT NULL UNIQUE REFERENCES installation_enrollments(id) ON DELETE RESTRICT,
    verified_domain text NOT NULL,
    verified_at timestamptz NOT NULL DEFAULT now()
);

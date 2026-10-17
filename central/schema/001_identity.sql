-- HAFZATECH-only PostgreSQL database. Never execute against the VenueDesk tenant database.
CREATE TABLE IF NOT EXISTS vendor_customers (
    id uuid PRIMARY KEY,
    legal_name text NOT NULL CHECK (length(trim(legal_name)) > 0),
    status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS installation_enrollments (
    id uuid PRIMARY KEY,
    customer_id uuid NOT NULL REFERENCES vendor_customers(id) ON DELETE RESTRICT,
    token_sha256 char(64) NOT NULL UNIQUE,
    approved_domain text NOT NULL CHECK (length(approved_domain) BETWEEN 4 AND 253),
    domain_verified_at timestamptz,
    expires_at timestamptz NOT NULL,
    claimed_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS installation_enrollments_customer_idx
    ON installation_enrollments(customer_id);
CREATE TABLE IF NOT EXISTS registered_installations (
    id uuid PRIMARY KEY,
    customer_id uuid NOT NULL REFERENCES vendor_customers(id) ON DELETE RESTRICT,
    enrollment_id uuid NOT NULL UNIQUE REFERENCES installation_enrollments(id) ON DELETE RESTRICT,
    approved_domain text NOT NULL,
    ed25519_public_key text NOT NULL UNIQUE CHECK (length(ed25519_public_key) = 43),
    status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'revoked')),
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT registered_installations_customer_id_unique UNIQUE (customer_id, id)
);
CREATE UNIQUE INDEX IF NOT EXISTS registered_installations_active_domain_unique
    ON registered_installations(lower(approved_domain)) WHERE status = 'active';
CREATE TABLE IF NOT EXISTS installation_challenges (
    enrollment_id uuid PRIMARY KEY REFERENCES installation_enrollments(id) ON DELETE CASCADE,
    nonce_sha256 char(64) NOT NULL UNIQUE,
    expires_at timestamptz NOT NULL,
    consumed_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS central_identity_audit (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id uuid NOT NULL REFERENCES vendor_customers(id) ON DELETE RESTRICT,
    installation_id uuid,
    enrollment_id uuid,
    event text NOT NULL CHECK (event IN ('installation.registered')),
    occurred_at timestamptz NOT NULL DEFAULT now(),
    details jsonb NOT NULL DEFAULT '{}'::jsonb
);

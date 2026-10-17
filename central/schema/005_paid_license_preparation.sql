-- Pending central issuance records only. No signing key, payment collection or live API.
CREATE TABLE IF NOT EXISTS central_licenses (
    id uuid PRIMARY KEY,
    customer_id uuid NOT NULL REFERENCES vendor_customers(id) ON DELETE RESTRICT,
    installation_id uuid NOT NULL UNIQUE,
    approved_domain text NOT NULL,
    current_revision integer NOT NULL DEFAULT 0 CHECK (current_revision >= 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (customer_id, installation_id)
      REFERENCES registered_installations(customer_id, id) ON DELETE RESTRICT,
    UNIQUE (customer_id, id)
);
CREATE TABLE IF NOT EXISTS central_paid_license_revisions (
    id uuid PRIMARY KEY,
    license_id uuid NOT NULL REFERENCES central_licenses(id) ON DELETE RESTRICT,
    invoice_id uuid NOT NULL UNIQUE REFERENCES central_vendor_invoices(id) ON DELETE RESTRICT,
    revision integer NOT NULL CHECK (revision >= 1),
    term_months integer NOT NULL CHECK (term_months IN (6, 12)),
    begins_at timestamptz NOT NULL,
    expires_at timestamptz NOT NULL,
    plan_snapshot jsonb NOT NULL,
    authorized_officer_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    state text NOT NULL DEFAULT 'awaiting_isolated_signer' CHECK (
        state IN ('awaiting_isolated_signer', 'signed', 'cancelled')
    ),
    signed_envelope_ciphertext bytea,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (license_id, revision),
    CHECK (expires_at > begins_at),
    CHECK ((state = 'signed') = (signed_envelope_ciphertext IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS central_license_revisions_latest
    ON central_paid_license_revisions(license_id, revision DESC);
CREATE TABLE IF NOT EXISTS central_license_issuance_audit (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id uuid NOT NULL REFERENCES vendor_customers(id) ON DELETE RESTRICT,
    license_id uuid NOT NULL REFERENCES central_licenses(id) ON DELETE RESTRICT,
    revision_id uuid NOT NULL UNIQUE REFERENCES central_paid_license_revisions(id) ON DELETE RESTRICT,
    invoice_id uuid NOT NULL REFERENCES central_vendor_invoices(id) ON DELETE RESTRICT,
    officer_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    action text NOT NULL CHECK (action = 'paid_license_revision.prepared'),
    occurred_at timestamptz NOT NULL DEFAULT now()
);

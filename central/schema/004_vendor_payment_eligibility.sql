-- Preparation for central billing: no customer charge, webhook, bank API or license signer is enabled.
-- All monetary amounts are exact integer minor units in the invoice's fixed currency.
CREATE TABLE IF NOT EXISTS central_vendor_plan_versions (
    id uuid PRIMARY KEY,
    plan_code text NOT NULL,
    version integer NOT NULL CHECK (version >= 1),
    term_months integer NOT NULL CHECK (term_months IN (6,12)),
    currency varchar(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    price_minor bigint NOT NULL CHECK (price_minor > 0),
    entitlements_snapshot jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(plan_code, version)
);
CREATE TABLE IF NOT EXISTS central_vendor_invoices (
    id uuid PRIMARY KEY,
    customer_id uuid NOT NULL REFERENCES vendor_customers(id) ON DELETE RESTRICT,
    plan_version_id uuid NOT NULL REFERENCES central_vendor_plan_versions(id) ON DELETE RESTRICT,
    invoice_number text NOT NULL UNIQUE,
    currency varchar(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    total_minor bigint NOT NULL CHECK (total_minor > 0),
    receiving_account_id uuid NOT NULL,
    encrypted_instructions_snapshot bytea NOT NULL,
    status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','issued','void')),
    issued_at timestamptz,
    due_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (customer_id, receiving_account_id)
        REFERENCES central_customer_provider_assignments(customer_id, receiving_account_id)
        ON DELETE RESTRICT,
    UNIQUE (customer_id, id),
    CHECK (status <> 'issued' OR issued_at IS NOT NULL)
);
CREATE TABLE IF NOT EXISTS central_vendor_payment_evidence (
    id uuid PRIMARY KEY,
    invoice_id uuid NOT NULL REFERENCES central_vendor_invoices(id) ON DELETE RESTRICT,
    claimed_reference text NOT NULL,
    attachment_private_object_key text,
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','reviewed','rejected')),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS central_vendor_verified_settlements (
    id uuid PRIMARY KEY,
    invoice_id uuid NOT NULL REFERENCES central_vendor_invoices(id) ON DELETE RESTRICT,
    receiving_account_id uuid NOT NULL REFERENCES central_vendor_receiving_accounts(id) ON DELETE RESTRICT,
    currency varchar(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    amount_minor bigint NOT NULL CHECK (amount_minor > 0),
    external_settlement_reference text NOT NULL,
    verified_by uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    verified_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (receiving_account_id, external_settlement_reference)
);
CREATE INDEX IF NOT EXISTS central_vendor_settlements_invoice ON central_vendor_verified_settlements(invoice_id);

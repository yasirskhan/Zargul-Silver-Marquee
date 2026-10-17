-- Vendor subscription receiving accounts. No VenueDesk wedding payment data is stored here.
-- Release remains OFF: no payment callback, deposit, withdrawal or settlement API exists.
CREATE TABLE IF NOT EXISTS central_vendor_receiving_accounts (
    id uuid PRIMARY KEY,
    provider_kind text NOT NULL CHECK (provider_kind IN (
        'domestic_bank', 'easypaisa', 'international_swift', 'western_union', 'paypal'
    )),
    display_label text NOT NULL CHECK (length(trim(display_label)) BETWEEN 3 AND 100),
    country_code varchar(2) NOT NULL CHECK (country_code ~ '^[A-Z]{2}$'),
    receiving_currency varchar(3) NOT NULL CHECK (receiving_currency ~ '^[A-Z]{3}$'),
    encrypted_instructions bytea NOT NULL CHECK (octet_length(encrypted_instructions) >= 16),
    status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'verified', 'inactive')),
    verified_at timestamptz,
    verified_by uuid REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'verified' OR (verified_by IS NOT NULL AND verified_at IS NOT NULL)),
    -- PayPal is a future optional provider, not presently approved for receiving.
    CHECK (provider_kind <> 'paypal' OR status <> 'verified')
);
CREATE TABLE IF NOT EXISTS central_customer_provider_assignments (
    id uuid PRIMARY KEY,
    customer_id uuid NOT NULL REFERENCES vendor_customers(id) ON DELETE RESTRICT,
    receiving_account_id uuid NOT NULL REFERENCES central_vendor_receiving_accounts(id) ON DELETE RESTRICT,
    enabled boolean NOT NULL DEFAULT false,
    effective_from timestamptz NOT NULL DEFAULT now(),
    effective_to timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (customer_id, receiving_account_id),
    CHECK (effective_to IS NULL OR effective_to > effective_from)
);
CREATE INDEX IF NOT EXISTS central_provider_eligibility_by_customer
    ON central_customer_provider_assignments (customer_id, enabled, effective_from);

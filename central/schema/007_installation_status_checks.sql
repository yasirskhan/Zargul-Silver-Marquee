-- Authenticated, signed installation check-ins; no signed grant delivered yet.
-- Treat the journal as security-sensitive and apply vendor retention policy.
CREATE TABLE IF NOT EXISTS central_installation_status_checks (
    installation_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    nonce_sha256 char(64) NOT NULL,
    checked_at timestamptz NOT NULL DEFAULT now(),
    known_revision integer NOT NULL CHECK (known_revision >= 0),
    PRIMARY KEY (installation_id, nonce_sha256),
    FOREIGN KEY (customer_id, installation_id)
        REFERENCES registered_installations(customer_id, id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS central_installation_status_checks_at
    ON central_installation_status_checks(checked_at);

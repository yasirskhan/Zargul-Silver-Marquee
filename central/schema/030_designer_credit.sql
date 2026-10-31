-- Versioned centrally governed designer credit per license.
CREATE TABLE IF NOT EXISTS central_license_designer_credits (
    id uuid PRIMARY KEY,
    license_id uuid NOT NULL REFERENCES central_licenses(id) ON DELETE RESTRICT,
    version integer NOT NULL CHECK (version >= 1),
    request_id uuid NOT NULL UNIQUE,
    credit jsonb NOT NULL,
    configured_by uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (license_id, version)
);
CREATE TABLE IF NOT EXISTS central_designer_credit_audit (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    credit_id uuid NOT NULL REFERENCES central_license_designer_credits(id) ON DELETE RESTRICT,
    license_id uuid NOT NULL REFERENCES central_licenses(id) ON DELETE RESTRICT,
    actor_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    action text NOT NULL CHECK (action IN ('designer_credit.configured')),
    occurred_at timestamptz NOT NULL DEFAULT now()
);
CREATE OR REPLACE FUNCTION central_designer_credit_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'central designer credit history is immutable';
END; $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS central_designer_credit_audit_immutable ON central_designer_credit_audit;
CREATE TRIGGER central_designer_credit_audit_immutable
    BEFORE UPDATE OR DELETE ON central_designer_credit_audit
    FOR EACH ROW EXECUTE FUNCTION central_designer_credit_immutable();
DROP TRIGGER IF EXISTS central_designer_credit_rows_immutable ON central_license_designer_credits;
CREATE TRIGGER central_designer_credit_rows_immutable
    BEFORE UPDATE OR DELETE ON central_license_designer_credits
    FOR EACH ROW EXECUTE FUNCTION central_designer_credit_immutable();
-- Central HAFZATECH MFA results are tamper-resistant and never contain codes.
-- An audit write and replay-counter/lockout change share one database transaction.
CREATE TABLE IF NOT EXISTS central_staff_mfa_attempt_audit (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    staff_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    outcome varchar(24) NOT NULL CHECK (outcome IN ('accepted','rejected','locked_out','locked')),
    accepted_counter bigint,
    occurred_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT mfa_audit_counter_only_if_accepted CHECK (
        (outcome='accepted' AND accepted_counter IS NOT NULL AND accepted_counter>=0)
        OR (outcome<>'accepted' AND accepted_counter IS NULL)
    )
);
CREATE INDEX IF NOT EXISTS central_staff_mfa_audit_staff_at
    ON central_staff_mfa_attempt_audit(staff_id,occurred_at DESC,id DESC);
CREATE OR REPLACE FUNCTION reject_central_staff_mfa_audit_mutation()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'HAFZATECH MFA audit records are immutable'
        USING ERRCODE='23514';
END
$$;
DROP TRIGGER IF EXISTS central_staff_mfa_audit_immutable ON central_staff_mfa_attempt_audit;
CREATE TRIGGER central_staff_mfa_audit_immutable
BEFORE UPDATE OR DELETE ON central_staff_mfa_attempt_audit
FOR EACH ROW EXECUTE FUNCTION reject_central_staff_mfa_audit_mutation();

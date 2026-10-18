-- Internal HAFZATECH credential rotation journal. Secrets are NEVER logged.
CREATE TABLE IF NOT EXISTS central_staff_password_change_events (
    id bigserial PRIMARY KEY,
    staff_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    outcome text NOT NULL DEFAULT 'changed' CHECK (outcome = 'changed'),
    occurred_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS central_staff_password_changes_by_staff
 ON central_staff_password_change_events(staff_id,id DESC);
CREATE OR REPLACE FUNCTION reject_central_staff_password_change_mutation()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'HAFZATECH password change audit is immutable'
      USING ERRCODE='23514';
END;
$$;
DROP TRIGGER IF EXISTS central_staff_password_change_immutable
 ON central_staff_password_change_events;
CREATE TRIGGER central_staff_password_change_immutable
 BEFORE UPDATE OR DELETE ON central_staff_password_change_events
 FOR EACH ROW EXECUTE FUNCTION reject_central_staff_password_change_mutation();

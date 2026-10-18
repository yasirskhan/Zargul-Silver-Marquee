-- Separate second-person decisions on immutable HAFZATECH role requests.
-- The record cannot be altered, reassigned or erased after it is committed.
CREATE TABLE IF NOT EXISTS central_staff_role_decisions (
    id uuid PRIMARY KEY,
    request_id uuid NOT NULL UNIQUE
       REFERENCES central_staff_role_requests(id) ON DELETE RESTRICT,
    approver_staff_id uuid NOT NULL
       REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    outcome text NOT NULL CHECK (outcome IN ('approved','rejected')),
    reason text NOT NULL CHECK (length(trim(reason)) BETWEEN 5 AND 500),
    role_grant_id uuid UNIQUE
       REFERENCES central_staff_role_grants(id) ON DELETE RESTRICT,
    decided_at timestamptz NOT NULL DEFAULT now(),
    CHECK ((outcome='approved')=(role_grant_id IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS central_staff_role_decisions_actor_idx
 ON central_staff_role_decisions(approver_staff_id,decided_at DESC);
CREATE OR REPLACE FUNCTION reject_central_staff_role_decision_mutation()
RETURNS trigger LANGUAGE plpgsql AS $immut$
BEGIN
 RAISE EXCEPTION 'HAFZATECH staff role decisions are immutable'
  USING ERRCODE='23514';
END;
$immut$;
DROP TRIGGER IF EXISTS central_staff_role_decisions_immutable
 ON central_staff_role_decisions;
CREATE TRIGGER central_staff_role_decisions_immutable
 BEFORE UPDATE OR DELETE ON central_staff_role_decisions
 FOR EACH ROW EXECUTE FUNCTION reject_central_staff_role_decision_mutation();

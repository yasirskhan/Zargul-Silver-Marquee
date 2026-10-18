-- Proposed HAFZATECH vendor staff role changes are immutable intents only.
-- They NEVER become effective grants without a later independently audited
-- second-person decision service. No staff credentials, MFA or bearer stored.
CREATE TABLE IF NOT EXISTS central_staff_role_requests (
    id uuid PRIMARY KEY,
    actor_staff_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    target_staff_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    role text NOT NULL CHECK (role IN (
        'super_administrator','staff_administrator','billing_officer',
        'license_officer','license_supervisor','plan_administrator',
        'support_agent','support_supervisor','auditor'
    )),
    customer_scope_id uuid REFERENCES vendor_customers(id) ON DELETE RESTRICT,
    reason text NOT NULL CHECK (length(trim(reason)) BETWEEN 5 AND 500),
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK (actor_staff_id<>target_staff_id)
);
CREATE INDEX IF NOT EXISTS central_staff_role_requests_target_idx
 ON central_staff_role_requests(target_staff_id,created_at DESC,id DESC);
CREATE OR REPLACE FUNCTION reject_central_staff_role_request_mutation()
RETURNS trigger LANGUAGE plpgsql AS $guard$
BEGIN
    RAISE EXCEPTION 'HAFZATECH staff role requests are immutable'
        USING ERRCODE='23514';
END;
$guard$;
DROP TRIGGER IF EXISTS central_staff_role_requests_immutable
 ON central_staff_role_requests;
CREATE TRIGGER central_staff_role_requests_immutable
 BEFORE UPDATE OR DELETE ON central_staff_role_requests
 FOR EACH ROW EXECUTE FUNCTION reject_central_staff_role_request_mutation();

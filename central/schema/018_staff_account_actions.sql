-- Immutable HAFZATECH vendor staff account suspension decisions.
-- This journal contains no passwords, TOTP codes, bearer/CSRF secrets or IPs.
CREATE TABLE IF NOT EXISTS central_staff_account_actions (
    id bigserial PRIMARY KEY,
    actor_staff_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    target_staff_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    action text NOT NULL CHECK (action='suspended'),
    previous_status text NOT NULL CHECK (previous_status IN
        ('pending_mfa','active','suspended','disabled')),
    reason text NOT NULL CHECK (length(trim(reason)) BETWEEN 5 AND 500),
    occurred_at timestamptz NOT NULL DEFAULT now(),
    CHECK (actor_staff_id<>target_staff_id)
);
CREATE INDEX IF NOT EXISTS central_staff_account_actions_target_idx
 ON central_staff_account_actions(target_staff_id,id DESC);
CREATE OR REPLACE FUNCTION reject_central_staff_account_action_mutation()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'HAFZATECH staff account actions are immutable'
        USING ERRCODE='23514';
END;
$$;
DROP TRIGGER IF EXISTS central_staff_account_actions_immutable
 ON central_staff_account_actions;
CREATE TRIGGER central_staff_account_actions_immutable
 BEFORE UPDATE OR DELETE ON central_staff_account_actions
 FOR EACH ROW EXECUTE FUNCTION reject_central_staff_account_action_mutation();

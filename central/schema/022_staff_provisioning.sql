-- Isolated HAFZATECH provision event, never a client VenueDesk user.
-- Staff accounts begin pending_mfa without roles or usable credentials;
-- an approved operator invitation/enrollment workflow is still required.
CREATE TABLE IF NOT EXISTS central_staff_provision_events (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    actor_staff_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    target_staff_id uuid NOT NULL UNIQUE REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    action text NOT NULL CHECK (action='created_pending_mfa'),
    reason text NOT NULL CHECK (length(trim(reason)) BETWEEN 5 AND 500),
    occurred_at timestamptz NOT NULL DEFAULT now(),
    CHECK (actor_staff_id <> target_staff_id)
);
CREATE INDEX IF NOT EXISTS central_staff_provision_events_actor_idx
 ON central_staff_provision_events(actor_staff_id,id DESC);

CREATE OR REPLACE FUNCTION reject_central_staff_provision_mutation()
RETURNS trigger LANGUAGE plpgsql AS $guard$
BEGIN
    RAISE EXCEPTION 'HAFZATECH staff provisioning journal is immutable'
        USING ERRCODE='23514';
END;
$guard$;
DROP TRIGGER IF EXISTS central_staff_provision_immutable
 ON central_staff_provision_events;
CREATE TRIGGER central_staff_provision_immutable
 BEFORE UPDATE OR DELETE ON central_staff_provision_events
 FOR EACH ROW EXECUTE FUNCTION reject_central_staff_provision_mutation();

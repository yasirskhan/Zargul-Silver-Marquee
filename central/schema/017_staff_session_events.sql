-- Immutable vendor staff session event history; no bearer token, CSRF,
-- password, OTP, raw email or network address is recorded.
CREATE TABLE IF NOT EXISTS central_staff_session_events (
    id bigserial PRIMARY KEY,
    session_id uuid NOT NULL REFERENCES central_staff_sessions(id) ON DELETE RESTRICT,
    staff_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    outcome text NOT NULL CHECK (outcome IN
        ('issued','rotated','logged_out','invalidated')),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS central_staff_session_event_subject_idx
 ON central_staff_session_events(staff_id,id DESC);
CREATE OR REPLACE FUNCTION reject_central_staff_session_event_mutation()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'HAFZATECH staff session event history is immutable'
        USING ERRCODE='23514';
END;
$$;
DROP TRIGGER IF EXISTS central_staff_session_events_immutable
 ON central_staff_session_events;
CREATE TRIGGER central_staff_session_events_immutable
 BEFORE UPDATE OR DELETE ON central_staff_session_events
 FOR EACH ROW EXECUTE FUNCTION reject_central_staff_session_event_mutation();

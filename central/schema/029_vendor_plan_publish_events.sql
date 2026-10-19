-- Default-OFF internal vendor plan publisher's immutable evidence.
-- All plan versions are append-only under schema 028. The journal binds
-- actor/request fingerprint to the exact plan version without personal data.
CREATE TABLE IF NOT EXISTS central_vendor_plan_publish_events (
    request_id uuid PRIMARY KEY,
    plan_version_id uuid NOT NULL UNIQUE REFERENCES
        central_vendor_plan_versions(id) ON DELETE RESTRICT,
    issued_by_staff_id uuid NOT NULL REFERENCES
        central_staff_users(id) ON DELETE RESTRICT,
    request_sha256 text NOT NULL CHECK(request_sha256 ~ '^[a-f0-9]{64}$'),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS central_vendor_plan_published_actor_idx
    ON central_vendor_plan_publish_events(issued_by_staff_id,created_at DESC);
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE
      tgname='central_vendor_plan_publish_events_immutable' AND
      tgrelid='central_vendor_plan_publish_events'::regclass) THEN
        CREATE TRIGGER central_vendor_plan_publish_events_immutable
        BEFORE UPDATE OR DELETE ON central_vendor_plan_publish_events
        FOR EACH ROW EXECUTE FUNCTION central_refuse_financial_audit_mutation();
    END IF;
END;
$$;

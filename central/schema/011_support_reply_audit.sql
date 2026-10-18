-- Separate vendor staff reply audit, never venue ledger or client application DB.
-- This additive migration preserves previously sent support messages.
ALTER TABLE vendor_support_messages
    ADD COLUMN IF NOT EXISTS content_sha256 char(64);
CREATE TABLE IF NOT EXISTS vendor_support_reply_audit (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ticket_id uuid NOT NULL,
    customer_id uuid NOT NULL REFERENCES vendor_customers(id) ON DELETE RESTRICT,
    staff_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    message_id uuid NOT NULL UNIQUE REFERENCES vendor_support_messages(id) ON DELETE RESTRICT,
    action text NOT NULL CHECK(action='support.agent_reply'),
    occurred_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY(customer_id,ticket_id)
        REFERENCES vendor_support_tickets(customer_id,id) ON DELETE RESTRICT
);


-- Audit and support correspondence are append-only. "Read" state belongs
-- in a separate read-receipt table, never edits or deletes an agent reply.
CREATE OR REPLACE FUNCTION central_support_immutable_guard()
RETURNS trigger LANGUAGE plpgsql AS $function$
BEGIN
    RAISE EXCEPTION 'central support messages and audit are append-only';
END
$function$;
DO $block$
BEGIN
    IF NOT EXISTS(SELECT 1 FROM pg_trigger WHERE tgname='support_messages_immutable_guard') THEN
        CREATE TRIGGER support_messages_immutable_guard BEFORE UPDATE OR DELETE
            ON vendor_support_messages FOR EACH ROW
            EXECUTE FUNCTION central_support_immutable_guard();
    END IF;
    IF NOT EXISTS(SELECT 1 FROM pg_trigger WHERE tgname='support_reply_audit_immutable_guard') THEN
        CREATE TRIGGER support_reply_audit_immutable_guard BEFORE UPDATE OR DELETE
            ON vendor_support_reply_audit FOR EACH ROW
            EXECUTE FUNCTION central_support_immutable_guard();
    END IF;
END
$block$;

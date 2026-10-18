-- Internal handling of customer-submitted payment claims. "Reviewed" means
-- an operator inspected a claim, NOT that any bank funds were verified.
-- Signing eligibility reads only independent verified settlements.
CREATE TABLE IF NOT EXISTS central_vendor_payment_evidence_reviews (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    evidence_id uuid NOT NULL UNIQUE REFERENCES central_vendor_payment_evidence(id) ON DELETE RESTRICT,
    invoice_id uuid NOT NULL REFERENCES central_vendor_invoices(id) ON DELETE RESTRICT,
    reviewer_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    decision text NOT NULL CHECK (decision IN ('reviewed','rejected')),
    reason text NOT NULL CHECK (char_length(reason) BETWEEN 12 AND 400),
    reviewed_at timestamptz NOT NULL DEFAULT now()
);

-- An operator decision must never be silently edited or deleted.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger
        WHERE tgname='central_payment_evidence_review_immutable'
          AND tgrelid='central_vendor_payment_evidence_reviews'::regclass) THEN
        CREATE TRIGGER central_payment_evidence_review_immutable
            BEFORE UPDATE OR DELETE ON central_vendor_payment_evidence_reviews
            FOR EACH ROW EXECUTE FUNCTION central_refuse_financial_audit_mutation();
    END IF;
END;
$$;

-- The original customer claim is immutable. Its pending status may change
-- exactly once, only alongside the matching durable review event in the
-- SAME transaction. No claim status can ever become a verified settlement.
CREATE OR REPLACE FUNCTION central_guard_payment_evidence_review()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id OR
       NEW.invoice_id IS DISTINCT FROM OLD.invoice_id OR
       NEW.claimed_reference IS DISTINCT FROM OLD.claimed_reference OR
       NEW.attachment_private_object_key IS DISTINCT FROM OLD.attachment_private_object_key OR
       NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'customer payment claim content is immutable' USING ERRCODE='P0001';
    END IF;
    IF NEW.status IS DISTINCT FROM OLD.status THEN
        IF OLD.status <> 'pending' OR NEW.status NOT IN ('reviewed','rejected') OR
           NOT EXISTS (
               SELECT 1 FROM central_vendor_payment_evidence_reviews a
               WHERE a.evidence_id=OLD.id AND a.invoice_id=OLD.invoice_id
                 AND a.decision=NEW.status
           ) THEN
            RAISE EXCEPTION 'payment claim status needs immutable review audit' USING ERRCODE='P0001';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger
        WHERE tgname='central_payment_evidence_status_guard'
          AND tgrelid='central_vendor_payment_evidence'::regclass) THEN
        CREATE TRIGGER central_payment_evidence_status_guard
            BEFORE UPDATE ON central_vendor_payment_evidence
            FOR EACH ROW EXECUTE FUNCTION central_guard_payment_evidence_review();
    END IF;
END;
$$;

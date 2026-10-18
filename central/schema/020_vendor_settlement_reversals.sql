-- Immutable evidence of an independently VERIFIED bank/provider refund or
-- chargeback. This is a ledger record, not a refund execution endpoint.
-- Every reverse amount reduces FUTURE license issuance eligibility; it never
-- deletes a settled transaction or retroactively alters signed grants.
CREATE TABLE IF NOT EXISTS central_vendor_settlement_reversals (
    id uuid PRIMARY KEY,
    settlement_id uuid NOT NULL REFERENCES central_vendor_verified_settlements(id) ON DELETE RESTRICT,
    amount_minor bigint NOT NULL CHECK (amount_minor > 0),
    kind text NOT NULL CHECK (kind IN ('refund','chargeback')),
    external_reference text NOT NULL CHECK (char_length(external_reference) BETWEEN 6 AND 120),
    reason text NOT NULL CHECK (char_length(reason) BETWEEN 12 AND 400),
    recorded_by uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    recorded_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (settlement_id, external_reference)
);
CREATE INDEX IF NOT EXISTS central_vendor_reversals_settlement
    ON central_vendor_settlement_reversals(settlement_id);

-- A direct SQL insertion must NOT bypass the amount limit. Locking the
-- immutable verified settlement serializes competing reversal inserts.
CREATE OR REPLACE FUNCTION central_enforce_vendor_reversal_bounds()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    original_amount bigint;
    invoice_ref uuid;
    reversed_total numeric;
BEGIN
    -- Same lock order as paid preparation/finalization: invoice, settlement.
    -- A bank reversal cannot race a signer finalizing an otherwise-paid invoice.
    SELECT invoice_id INTO invoice_ref
      FROM central_vendor_verified_settlements
     WHERE id=NEW.settlement_id;
    IF invoice_ref IS NULL THEN
        RAISE EXCEPTION 'verified settlement not found' USING ERRCODE='23503';
    END IF;
    PERFORM 1 FROM central_vendor_invoices WHERE id=invoice_ref FOR UPDATE;
    SELECT amount_minor INTO original_amount
      FROM central_vendor_verified_settlements
     WHERE id=NEW.settlement_id FOR UPDATE;
    IF original_amount IS NULL THEN
        RAISE EXCEPTION 'verified settlement not found' USING ERRCODE='23503';
    END IF;
    SELECT COALESCE(SUM(amount_minor),0) INTO reversed_total
      FROM central_vendor_settlement_reversals
     WHERE settlement_id=NEW.settlement_id;
    IF reversed_total + NEW.amount_minor > original_amount THEN
        RAISE EXCEPTION 'reversal exceeds verified settlement'
            USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger
        WHERE tgname='central_vendor_reversal_limit'
          AND tgrelid='central_vendor_settlement_reversals'::regclass) THEN
        CREATE TRIGGER central_vendor_reversal_limit
            BEFORE INSERT ON central_vendor_settlement_reversals
            FOR EACH ROW EXECUTE FUNCTION central_enforce_vendor_reversal_bounds();
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_trigger
        WHERE tgname='central_vendor_reversal_immutable'
          AND tgrelid='central_vendor_settlement_reversals'::regclass) THEN
        CREATE TRIGGER central_vendor_reversal_immutable
            BEFORE UPDATE OR DELETE ON central_vendor_settlement_reversals
            FOR EACH ROW EXECUTE FUNCTION central_refuse_financial_audit_mutation();
    END IF;
END;
$$;

-- Immutable HAFZATECH central commercial evidence: never silently edit
-- verified money or license preparation/finalization audit events.
-- Future refunds/chargebacks MUST be separate reversal entries with audit,
-- not UPDATE/DELETE on the original verified settlement.
CREATE OR REPLACE FUNCTION central_refuse_financial_audit_mutation()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'immutable HAFZATECH commercial audit event'
        USING ERRCODE = 'P0001';
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger
        WHERE tgname = 'central_verified_settlements_immutable'
          AND tgrelid = 'central_vendor_verified_settlements'::regclass) THEN
        CREATE TRIGGER central_verified_settlements_immutable
            BEFORE UPDATE OR DELETE ON central_vendor_verified_settlements
            FOR EACH ROW EXECUTE FUNCTION central_refuse_financial_audit_mutation();
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_trigger
        WHERE tgname = 'central_license_preparation_audit_immutable'
          AND tgrelid = 'central_license_issuance_audit'::regclass) THEN
        CREATE TRIGGER central_license_preparation_audit_immutable
            BEFORE UPDATE OR DELETE ON central_license_issuance_audit
            FOR EACH ROW EXECUTE FUNCTION central_refuse_financial_audit_mutation();
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_trigger
        WHERE tgname = 'central_license_signature_audit_immutable'
          AND tgrelid = 'central_license_signature_events'::regclass) THEN
        CREATE TRIGGER central_license_signature_audit_immutable
            BEFORE UPDATE OR DELETE ON central_license_signature_events
            FOR EACH ROW EXECUTE FUNCTION central_refuse_financial_audit_mutation();
    END IF;
END;
$$;

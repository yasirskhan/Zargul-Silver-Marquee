-- Keep a six- or twelve-month quoted plan version, currency, price,
-- entitlements and features append-only. New prices require a NEW version.
-- Existing invoice and signed-license audit must never silently rebase.
-- Defined by 009_financial_audit_immutability.sql.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname='central_vendor_plan_versions_immutable'
          AND tgrelid='central_vendor_plan_versions'::regclass
    ) THEN
        CREATE TRIGGER central_vendor_plan_versions_immutable
            BEFORE UPDATE OR DELETE ON central_vendor_plan_versions
            FOR EACH ROW EXECUTE FUNCTION central_refuse_financial_audit_mutation();
    END IF;
END;
$$;

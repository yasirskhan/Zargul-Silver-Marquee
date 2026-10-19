-- Immutable request provenance for a vendor-issued subscription invoice.
-- Issuing an invoice does NOT mean funds were received or a license was signed.
CREATE TABLE IF NOT EXISTS central_vendor_invoice_issue_events (
    request_id uuid PRIMARY KEY,
    customer_id uuid NOT NULL REFERENCES vendor_customers(id) ON DELETE RESTRICT,
    invoice_id uuid NOT NULL UNIQUE,
    issued_by_staff_id uuid NOT NULL REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    request_sha256 text NOT NULL CHECK (request_sha256 ~ '^[a-f0-9]{64}$'),
    created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (customer_id, invoice_id)
        REFERENCES central_vendor_invoices(customer_id,id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS central_invoice_issuer_history_idx
    ON central_vendor_invoice_issue_events(issued_by_staff_id,created_at DESC);
DROP TRIGGER IF EXISTS central_invoice_issue_event_immutable
    ON central_vendor_invoice_issue_events;
CREATE TRIGGER central_invoice_issue_event_immutable
    BEFORE UPDATE OR DELETE ON central_vendor_invoice_issue_events
    FOR EACH ROW EXECUTE FUNCTION central_refuse_financial_audit_mutation();

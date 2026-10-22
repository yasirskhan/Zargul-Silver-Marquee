<?php

return [
    // Internal hall catalog and stocktake UI. Real loan/balance posting remains
    // unavailable until independent counting and finance gates are certified.
    'catalog_enabled' => (bool) env('VENUE_INVENTORY_CATALOG_ENABLED', false),
    'stocktakes_enabled' => (bool) env('VENUE_INVENTORY_STOCKTAKES_ENABLED', false),
    // Read-only per-hall owned and borrowed book balance, OFF until pilot acceptance.
    'position_reports_enabled' => (bool) env('VENUE_INVENTORY_POSITION_REPORTS_ENABLED', false),
    // Immutable count-time book snapshot and read-only variance; default OFF.
    'stocktake_variance_enabled' => (bool) env('VENUE_INVENTORY_STOCKTAKE_VARIANCE_ENABLED', false),
    // Review-bound, same-total OWNED condition movements only, OFF until pilot.
    'variance_reclassification_enabled' => (bool) env('VENUE_INVENTORY_VARIANCE_RECLASSIFICATION_ENABLED', false),
    'loan_drafts_enabled' => (bool) env('VENUE_INVENTORY_LOAN_DRAFTS_ENABLED', false),
    // Only independently approved, evidence-backed source dispatch. OFF in production.
    'loan_dispatch_enabled' => (bool) env('VENUE_INVENTORY_LOAN_DISPATCH_ENABLED', false),
    // Receiving hall must independently confirm and match internal dispatch.
    'loan_receipt_enabled' => (bool) env('VENUE_INVENTORY_LOAN_RECEIPT_ENABLED', false),
    // Physical partial return dispatch does not itself acknowledge or close a loan.
    'loan_returns_enabled' => (bool) env('VENUE_INVENTORY_LOAN_RETURNS_ENABLED', false),
    // Owner/admin independent physical return condition acceptance (default OFF).
    'loan_return_acceptance_enabled' => (bool) env('VENUE_INVENTORY_LOAN_RETURN_ACCEPTANCE_ENABLED', false),
    // Independent signed later physical discovery within an already reviewed return batch. OFF.
    'internal_late_return_enabled' => (bool) env('VENUE_INVENTORY_INTERNAL_LATE_RETURN_ENABLED', false),
    // Immutable staff incident reporting only. Financial settlement remains separately gated.
    'internal_discrepancy_enabled' => (bool) env('VENUE_INVENTORY_INTERNAL_DISCREPANCY_ENABLED', false),
    // Original per-loan borrower + lender signed proof, independently reviewed; never physical/financial clearing.
    'internal_physical_finding_enabled' => (bool) env('VENUE_INVENTORY_INTERNAL_PHYSICAL_FINDING_ENABLED', false),
    'loan_incidents_enabled' => (bool) env('VENUE_INVENTORY_LOAN_INCIDENTS_ENABLED', false),
    // Independent evidence assessment only; does not assign financial liability.
    'loan_incident_reviews_enabled' => (bool) env('VENUE_INVENTORY_LOAN_INCIDENT_REVIEWS_ENABLED', false),
    // Substantiated incident claim proposal and separate collection review, no payment or journal.
    'loan_financial_claims_enabled' => (bool) env('VENUE_INVENTORY_LOAN_FINANCIAL_CLAIMS_ENABLED', false),
    // Private owner-recorded partner correspondence and independent integrity review only.
    'loan_partner_responses_enabled' => (bool) env('VENUE_INVENTORY_LOAN_PARTNER_RESPONSES_ENABLED', false),
    // Manual partner identity attestation and separately approved collectible obligation;
    // never a journaled receivable or payment. Default OFF.
    'loan_authorized_obligations_enabled' => (bool) env('VENUE_INVENTORY_LOAN_AUTHORIZED_OBLIGATIONS_ENABLED', false),
    // Evidence-recorded, independent-bank/cash-verified partial recoveries with
    // balanced cash-basis journals and immutable reversals only. OFF until finance.
    'loan_recoveries_enabled' => (bool) env('VENUE_INVENTORY_LOAN_RECOVERIES_ENABLED', false),
    // Independently approved partial contractual debt forgiveness, NOT payment or cash journal; OFF.
    'loan_financial_waivers_enabled' => (bool) env('VENUE_INVENTORY_LOAN_FINANCIAL_WAIVERS_ENABLED', false),
    // Read-only external-owned abnormal custody and verified money reconciliation.
    // Never authorizes write-off, financial waiver or non-clean closure. OFF.
    'loan_nonclean_reconciliation_enabled' => (bool) env('VENUE_INVENTORY_NONCLEAN_RECONCILIATION_ENABLED', false),
    // Independently verified, two-owner non-clean physical write-off posting; never waives money. OFF.
    'loan_nonclean_writeoffs_enabled' => (bool) env('VENUE_INVENTORY_NONCLEAN_WRITEOFFS_ENABLED', false),
    // Non-clean independently reconciled zero-outstanding loan final numbered PDF. Default OFF.
    'loan_nonclean_final_closure_enabled' => (bool) env('VENUE_INVENTORY_NONCLEAN_FINAL_CLOSURE_ENABLED', false),
    // Entirely clean internal loans only; independently reconciled numbered PDF.
    'loan_clean_closure_enabled' => (bool) env('VENUE_INVENTORY_LOAN_CLEAN_CLOSURE_ENABLED', false),
    // Hall-owned goods lent outside: independent signed handover and owner approval. OFF.
    'external_out_dispatch_enabled' => (bool) env('VENUE_INVENTORY_EXTERNAL_OUT_DISPATCH_ENABLED', false),
    // EOL-series independent owner clean outside-owned loan PDF; OFF.
    'external_out_clean_closure_enabled' => (bool) env('VENUE_INVENTORY_EXTERNAL_OUT_CLEAN_CLOSURE_ENABLED', false),
    // Outside partner physical partial returns and independent condition review. OFF.
    'external_out_return_enabled' => (bool) env('VENUE_INVENTORY_EXTERNAL_OUT_RETURN_ENABLED', false),
    // External partner-to-hall intake requires a different physical counter and approver.
    'external_borrowed_intake_enabled' => (bool) env('VENUE_INVENTORY_EXTERNAL_BORROWED_INTAKE_ENABLED', false),
    // Witnessed partial partner returns and independently verified partner receipt; OFF.
    'external_borrowed_return_enabled' => (bool) env('VENUE_INVENTORY_EXTERNAL_BORROWED_RETURN_ENABLED', false),
    // Partner-signed short/damaged return counts with independent documentary
    // review only. Never clears transit, posts money or closes a loan. OFF.
    'external_borrowed_discrepancy_enabled' => (bool) env('VENUE_INVENTORY_EXTERNAL_BORROWED_DISCREPANCY_ENABLED', false),
    // Owner-only, all-partner-receipts-verified physical closeout PDF. OFF.
    'external_borrowed_clean_closure_enabled' => (bool) env('VENUE_INVENTORY_EXTERNAL_BORROWED_CLEAN_CLOSURE_ENABLED', false),
    // Independently reviewed opening count posting; OFF until physical
    // witness acceptance, authorization, concurrency and pilot signoff.
    'purchase_receipts_enabled' => (bool) env('VENUE_INVENTORY_PURCHASE_RECEIPTS_ENABLED', false),
    // Authenticated private evidence bytes; independent from receipt claim and stock posting.
    'purchase_evidence_upload_enabled' => (bool) env('VENUE_INVENTORY_PURCHASE_EVIDENCE_UPLOAD_ENABLED', false),
    // Only after independent review, both stored evidence files and CAS movement. OFF by default.
    'purchase_stock_posting_enabled' => (bool) env('VENUE_INVENTORY_PURCHASE_STOCK_POSTING_ENABLED', false),
    'opening_stock_posting_enabled' =>
        (bool) env('VENUE_INVENTORY_OPENING_POSTING_ENABLED', false),
];

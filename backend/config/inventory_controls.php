<?php

return [
    // Internal hall catalog and stocktake UI. Real loan/balance posting remains
    // unavailable until independent counting and finance gates are certified.
    'catalog_enabled' => (bool) env('VENUE_INVENTORY_CATALOG_ENABLED', false),
    'stocktakes_enabled' => (bool) env('VENUE_INVENTORY_STOCKTAKES_ENABLED', false),
    // Read-only per-hall owned and borrowed book balance, OFF until pilot acceptance.
    'position_reports_enabled' => (bool) env('VENUE_INVENTORY_POSITION_REPORTS_ENABLED', false),
    'loan_drafts_enabled' => (bool) env('VENUE_INVENTORY_LOAN_DRAFTS_ENABLED', false),
    // Only independently approved, evidence-backed source dispatch. OFF in production.
    'loan_dispatch_enabled' => (bool) env('VENUE_INVENTORY_LOAN_DISPATCH_ENABLED', false),
    // Receiving hall must independently confirm and match internal dispatch.
    'loan_receipt_enabled' => (bool) env('VENUE_INVENTORY_LOAN_RECEIPT_ENABLED', false),
    // Physical partial return dispatch does not itself acknowledge or close a loan.
    'loan_returns_enabled' => (bool) env('VENUE_INVENTORY_LOAN_RETURNS_ENABLED', false),
    // Owner/admin independent physical return condition acceptance (default OFF).
    'loan_return_acceptance_enabled' => (bool) env('VENUE_INVENTORY_LOAN_RETURN_ACCEPTANCE_ENABLED', false),
    // Immutable staff incident reporting only. Financial settlement remains separately gated.
    'loan_incidents_enabled' => (bool) env('VENUE_INVENTORY_LOAN_INCIDENTS_ENABLED', false),
    // Independent evidence assessment only; does not assign financial liability.
    'loan_incident_reviews_enabled' => (bool) env('VENUE_INVENTORY_LOAN_INCIDENT_REVIEWS_ENABLED', false),
    // Entirely clean internal loans only; independently reconciled numbered PDF.
    'loan_clean_closure_enabled' => (bool) env('VENUE_INVENTORY_LOAN_CLEAN_CLOSURE_ENABLED', false),
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

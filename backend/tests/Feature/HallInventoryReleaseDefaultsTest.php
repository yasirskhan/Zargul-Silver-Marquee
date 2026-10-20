<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regression: literal backslash-n in PHP config can join subsequent
 * array entries to a // comment. Runtime overrides would mask the defect.
 */
final class HallInventoryReleaseDefaultsTest extends TestCase
{
    public function test_inventory_release_switches_are_parsed_as_real_boolean_entries():void
    {
        $defaults=require base_path('config/inventory_controls.php');
        $expected=[
            'catalog_enabled','stocktakes_enabled','position_reports_enabled','stocktake_variance_enabled','variance_reclassification_enabled','loan_drafts_enabled',
            'loan_dispatch_enabled','loan_receipt_enabled','loan_returns_enabled',
            'loan_return_acceptance_enabled','loan_incidents_enabled',
            'loan_incident_reviews_enabled','loan_clean_closure_enabled','external_borrowed_intake_enabled',
            'purchase_receipts_enabled','purchase_evidence_upload_enabled',
            'purchase_stock_posting_enabled','opening_stock_posting_enabled',
        ];
        foreach($expected as $flag) {
            $this->assertArrayHasKey($flag,$defaults,'Missing release control: '.$flag);
            $this->assertIsBool($defaults[$flag],$flag.' must be strictly boolean');
            $this->assertTrue(config()->has('inventory_controls.'.$flag));
        }
    }
}

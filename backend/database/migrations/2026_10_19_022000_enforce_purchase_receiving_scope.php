<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // Bind every immutable receiving/review row to the original purchase
        // and physical hall. Single-column FK alone cannot guard mismatched
        // company/hall/item values from accidental privileged SQL writes.
        DB::statement('ALTER TABLE hall_inventory_purchase_requests ADD CONSTRAINT purchase_claim_scope_unique UNIQUE (id, company_id, hall_id, item_id)');
        DB::statement('ALTER TABLE hall_inventory_purchase_receiving_evidence ADD CONSTRAINT receiving_claim_scope_fk FOREIGN KEY (purchase_request_id, company_id, hall_id, item_id) REFERENCES hall_inventory_purchase_requests (id, company_id, hall_id, item_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE hall_inventory_purchase_receiving_evidence ADD CONSTRAINT receiving_review_scope_unique UNIQUE (id, company_id, hall_id, purchase_request_id)');
        DB::statement('ALTER TABLE hall_inventory_purchase_receiving_reviews ADD CONSTRAINT review_receipt_scope_fk FOREIGN KEY (receiving_evidence_id, company_id, hall_id, purchase_request_id) REFERENCES hall_inventory_purchase_receiving_evidence (id, company_id, hall_id, purchase_request_id) ON DELETE RESTRICT');
    }

    public function down(): void {
        // Immutable receiving/review rows must never be orphaned by reverting
        // relationship protection after records have been created.
        if (DB::table('hall_inventory_purchase_receiving_evidence')->exists() ||
            DB::table('hall_inventory_purchase_receiving_reviews')->exists()) {
            throw new RuntimeException('Archive receiving evidence before removing integrity constraints.');
        }
        DB::statement('ALTER TABLE hall_inventory_purchase_receiving_reviews DROP CONSTRAINT review_receipt_scope_fk');
        DB::statement('ALTER TABLE hall_inventory_purchase_receiving_evidence DROP CONSTRAINT receiving_review_scope_unique');
        DB::statement('ALTER TABLE hall_inventory_purchase_receiving_evidence DROP CONSTRAINT receiving_claim_scope_fk');
        DB::statement('ALTER TABLE hall_inventory_purchase_requests DROP CONSTRAINT purchase_claim_scope_unique');
    }
};

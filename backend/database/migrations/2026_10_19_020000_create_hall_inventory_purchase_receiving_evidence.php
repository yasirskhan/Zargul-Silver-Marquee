<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_purchase_receiving_evidence',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('purchase_request_id')->unique('purchase_receiving_request_unique');
            $t->uuid('company_id'); $t->uuid('hall_id'); $t->uuid('item_id');
            $t->unsignedInteger('counted_quantity');
            $t->string('physical_evidence_reference',300);
            $t->string('condition_notes',500);
            $t->char('receipt_fingerprint',64);
            $t->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('purchase_request_id')->references('id')
                ->on('hall_inventory_purchase_requests')->restrictOnDelete();
            $t->foreign(['company_id','hall_id','item_id'])
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_items')->restrictOnDelete();
            $t->index(['company_id','hall_id','created_at'],'purchase_receiving_scope_index');
        });
        DB::statement('ALTER TABLE hall_inventory_purchase_receiving_evidence ADD CONSTRAINT purchase_receiving_positive_quantity CHECK (counted_quantity > 0 AND counted_quantity <= 100000000)');
        DB::statement('CREATE TRIGGER immutable_hall_purchase_receiving BEFORE UPDATE OR DELETE ON hall_inventory_purchase_receiving_evidence FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_purchase_receiving_evidence')->exists())
            throw new RuntimeException('Receiving evidence requires archival before rollback.');
        Schema::dropIfExists('hall_inventory_purchase_receiving_evidence');
    }
};

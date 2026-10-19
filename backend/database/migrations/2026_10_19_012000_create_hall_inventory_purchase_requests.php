<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_purchase_requests',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('hall_id'); $t->uuid('item_id');
            $t->unsignedInteger('quantity');
            $t->string('vendor_name',200); $t->string('vendor_reference',160);
            $t->string('evidence_reference',300); $t->string('reason',500);
            $t->char('request_fingerprint',64);
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign(['company_id','hall_id','item_id'])
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_items')->restrictOnDelete();
            $t->index(['company_id','hall_id','created_at']);
        });
        DB::statement('ALTER TABLE hall_inventory_purchase_requests ADD CONSTRAINT purchase_positive_quantity CHECK (quantity>0 AND quantity<=100000000)');
        DB::statement('CREATE TRIGGER immutable_hall_purchase_requests BEFORE UPDATE OR DELETE ON hall_inventory_purchase_requests FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_purchase_requests')->exists())
            throw new RuntimeException('Purchase claims require archival before rollback.');
        Schema::dropIfExists('hall_inventory_purchase_requests');
    }
};
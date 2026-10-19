<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_purchase_stock_acceptances',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('hall_id'); $t->uuid('item_id');
            $t->uuid('purchase_request_id')->unique('purchase_stock_once_unique');
            $t->uuid('receiving_evidence_id')->unique('purchase_receiving_post_once');
            $t->uuid('review_id')->unique('purchase_review_post_once');
            $t->uuid('movement_id')->unique('purchase_post_movement_unique');
            $t->foreignId('accepted_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign(['company_id','hall_id','purchase_request_id'],'purchase_accept_scope_fk')
                ->references(['company_id','hall_id','id'])->on('hall_inventory_purchase_requests')->restrictOnDelete();
            $t->foreign('receiving_evidence_id','purchase_accept_receipt_fk')
                ->references('id')->on('hall_inventory_purchase_receiving_evidence')->restrictOnDelete();
            $t->foreign('review_id','purchase_accept_review_fk')
                ->references('id')->on('hall_inventory_purchase_receiving_reviews')->restrictOnDelete();
            $t->foreign('movement_id','purchase_accept_movement_fk')
                ->references('id')->on('hall_inventory_movement_events')->restrictOnDelete();
        });
        DB::statement('CREATE TRIGGER immutable_hall_purchase_stock_acceptances BEFORE UPDATE OR DELETE ON hall_inventory_purchase_stock_acceptances FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_purchase_stock_acceptances')->exists())
            throw new RuntimeException('Purchase stock acceptances require archival before rollback.');
        Schema::dropIfExists('hall_inventory_purchase_stock_acceptances');
    }
};

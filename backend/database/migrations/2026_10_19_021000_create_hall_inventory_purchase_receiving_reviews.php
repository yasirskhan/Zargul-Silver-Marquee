<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_purchase_receiving_reviews', function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('receiving_evidence_id')->unique('purchase_receiving_review_one');
            $t->uuid('company_id');
            $t->uuid('hall_id');
            $t->uuid('purchase_request_id');
            $t->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $t->string('review_notes',500);
            $t->char('review_fingerprint',64);
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('receiving_evidence_id','purchase_receiving_review_evidence_fk')
                ->references('id')->on('hall_inventory_purchase_receiving_evidence')->restrictOnDelete();
            $t->index(['company_id','hall_id','created_at'],'purchase_receiving_review_scope');
        });
        DB::statement('CREATE TRIGGER immutable_hall_purchase_receiving_reviews BEFORE UPDATE OR DELETE ON hall_inventory_purchase_receiving_reviews FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_purchase_receiving_reviews')->exists())
            throw new RuntimeException('Purchase receiving reviews require archival before rollback.');
        Schema::dropIfExists('hall_inventory_purchase_receiving_reviews');
    }
};

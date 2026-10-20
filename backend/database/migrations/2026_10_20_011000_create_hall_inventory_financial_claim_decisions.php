<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_financial_claim_decisions',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');$t->uuid('claim_id');
            $t->string('decision',32);$t->string('reason',500);
            $t->char('fingerprint',64);
            $t->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','claim_id'],'fin_claim_single_review');
            $t->foreign(['company_id','claim_id'],'fin_claim_decision_fk')
                ->references(['company_id','id'])->on('hall_inventory_financial_claims')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_financial_claim_decisions ADD CONSTRAINT fin_claim_review_kind CHECK (decision IN ('approved_for_collection','rejected','disputed'))");
        DB::statement('CREATE TRIGGER immutable_hall_inventory_financial_claim_decisions BEFORE UPDATE OR DELETE ON hall_inventory_financial_claim_decisions FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_financial_claim_decisions')->exists())
            throw new RuntimeException('Immutable claim decision requires archival.');
        Schema::dropIfExists('hall_inventory_financial_claim_decisions');
    }
};
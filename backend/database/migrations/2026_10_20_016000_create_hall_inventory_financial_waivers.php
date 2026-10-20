<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_financial_waiver_proposals',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('obligation_id');
            $t->uuid('claim_id');$t->uuid('loan_id');$t->uuid('hall_id');
            $t->char('currency',3);$t->unsignedBigInteger('amount_minor');
            $t->string('reason',500);$t->char('fingerprint',64);
            $t->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','id','obligation_id'],'waiver_exact_proposal_scope');
            $t->foreign(['company_id','obligation_id','claim_id','loan_id','hall_id','currency'],
                'waiver_exact_authorized_obligation')
                ->references(['company_id','id','claim_id','loan_id','source_hall_id','currency'])
                ->on('hall_inventory_financial_obligations')->restrictOnDelete();
            $t->index(['company_id','loan_id','hall_id','created_at'],'waiver_scoped_history');
        });
        DB::statement("ALTER TABLE hall_inventory_financial_waiver_proposals
            ADD CONSTRAINT fin_waiver_proposal_valid CHECK (
                amount_minor BETWEEN 1 AND 100000000000 AND
                length(reason) BETWEEN 10 AND 500 AND currency ~ '^[A-Z]{3}$'
            )");
        DB::statement('CREATE TRIGGER immutable_financial_waiver_proposals BEFORE UPDATE OR DELETE
            ON hall_inventory_financial_waiver_proposals FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
        Schema::create('hall_inventory_financial_waiver_decisions',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('proposal_id');
            $t->uuid('obligation_id');$t->string('decision',12);$t->string('reason',500);
            $t->char('fingerprint',64);
            $t->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','proposal_id'],'waiver_one_decision');
            $t->foreign(['company_id','proposal_id','obligation_id'],'waiver_decision_exact_proposal')
                ->references(['company_id','id','obligation_id'])
                ->on('hall_inventory_financial_waiver_proposals')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_financial_waiver_decisions ADD CONSTRAINT fin_waiver_decision_valid
            CHECK(decision IN ('approved','rejected') AND length(reason) BETWEEN 10 AND 500)");
        DB::statement('CREATE TRIGGER immutable_financial_waiver_decisions BEFORE UPDATE OR DELETE
            ON hall_inventory_financial_waiver_decisions FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_financial_waiver_proposals')->exists()||
           DB::table('hall_inventory_financial_waiver_decisions')->exists())
            throw new RuntimeException('Immutable financial waivers must be archived before rollback.');
        Schema::dropIfExists('hall_inventory_financial_waiver_decisions');
        Schema::dropIfExists('hall_inventory_financial_waiver_proposals');
    }
};
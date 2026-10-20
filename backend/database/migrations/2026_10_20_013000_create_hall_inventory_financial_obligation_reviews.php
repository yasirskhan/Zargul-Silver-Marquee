<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_financial_obligations',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');$t->uuid('claim_id');$t->uuid('loan_id');
            $t->uuid('source_hall_id');$t->char('currency',3);
            $t->uuid('evidence_id');
            $t->unsignedBigInteger('amount_minor');
            $t->string('identity_check_method',40);
            $t->string('identity_check_reference',200);
            $t->string('reason',500);
            $t->char('fingerprint',64);
            $t->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','id','claim_id'],'fin_obligation_exact_decision_scope');
            $t->unique(['company_id','claim_id','evidence_id'],'fin_obligation_one_per_document');
            $t->index(['company_id','loan_id','source_hall_id']);
            $t->foreign(['company_id','claim_id','loan_id','source_hall_id','currency'],
                'fin_obligation_exact_claim_fk')
                ->references(['company_id','id','loan_id','source_hall_id','currency'])
                ->on('hall_inventory_financial_claims')->restrictOnDelete();
            $t->foreign(['company_id','evidence_id','claim_id'],'fin_obligation_partner_evidence_fk')
                ->references(['company_id','id','claim_id'])
                ->on('hall_inventory_financial_counterparty_evidence')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_financial_obligations
            ADD CONSTRAINT fin_obligation_amount_and_method CHECK (
                amount_minor BETWEEN 1 AND 100000000000
                AND identity_check_method IN ('verified_callback','in_person_signed','trusted_contract_record')
                AND length(identity_check_reference) BETWEEN 12 AND 200
                AND length(reason) BETWEEN 10 AND 500
            )");
        DB::statement('CREATE TRIGGER immutable_fin_obligations BEFORE UPDATE OR DELETE
            ON hall_inventory_financial_obligations FOR EACH ROW
            EXECUTE FUNCTION venuedesk_immutable_history()');
        Schema::create('hall_inventory_financial_obligation_decisions',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');$t->uuid('obligation_id');$t->uuid('claim_id');
            $t->string('decision',32);$t->string('reason',500);
            $t->char('fingerprint',64);
            $t->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','obligation_id'],'fin_obligation_one_decision');
            $t->foreign(['company_id','obligation_id','claim_id'],'fin_obligation_decision_source_fk')
                ->references(['company_id','id','claim_id'])
                ->on('hall_inventory_financial_obligations')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_financial_obligation_decisions
            ADD CONSTRAINT fin_obligation_decision_kind CHECK (
                decision IN ('authorized','rejected','disputed')
            )");
        // No two different monetary agreements can be simultaneously authorized
        // against the SAME claimed incident, even via direct SQL.
        DB::statement("CREATE UNIQUE INDEX fin_obligation_one_authorized_claim
            ON hall_inventory_financial_obligation_decisions(company_id,claim_id)
            WHERE decision = 'authorized'");
        DB::statement('CREATE TRIGGER immutable_fin_obligation_decisions BEFORE UPDATE OR DELETE
            ON hall_inventory_financial_obligation_decisions FOR EACH ROW
            EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_financial_obligations')->exists() ||
           DB::table('hall_inventory_financial_obligation_decisions')->exists())
            throw new RuntimeException('Authorized obligation history must be archived.');
        Schema::dropIfExists('hall_inventory_financial_obligation_decisions');
        Schema::dropIfExists('hall_inventory_financial_obligations');
    }
};

<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::table('hall_inventory_loan_incident_reports',function(Blueprint $t):void {
            $t->unique(['company_id','id','loan_id','source_hall_id'],'fin_claim_report_scope');
        });
        Schema::table('hall_inventory_loan_incident_decisions',function(Blueprint $t):void {
            $t->unique(['company_id','report_id','id'],'fin_claim_review_scope');
        });
        Schema::create('hall_inventory_financial_claims',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('loan_id'); $t->uuid('source_hall_id');
            $t->uuid('report_id'); $t->uuid('review_id');
            $t->unsignedInteger('quantity'); $t->char('currency',3);
            $t->unsignedBigInteger('amount_minor'); $t->string('reason',500);
            $t->char('fingerprint',64);
            $t->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','report_id'],'fin_claim_one_report');
            $t->unique(['company_id','id'],'fin_claim_company_id');
            $t->index(['company_id','loan_id','source_hall_id']);
            $t->foreign(['company_id','report_id','loan_id','source_hall_id'],'fin_claim_report_fk')
                ->references(['company_id','id','loan_id','source_hall_id'])
                ->on('hall_inventory_loan_incident_reports')->restrictOnDelete();
            $t->foreign(['company_id','report_id','review_id'],'fin_claim_review_fk')
                ->references(['company_id','report_id','id'])
                ->on('hall_inventory_loan_incident_decisions')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_financial_claims ADD CONSTRAINT fin_claim_amount_check CHECK (quantity > 0 AND amount_minor > 0 AND currency ~ '^[A-Z]{3}$')");
        DB::statement('CREATE TRIGGER immutable_hall_inventory_financial_claims BEFORE UPDATE OR DELETE ON hall_inventory_financial_claims FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_financial_claims')->exists())
            throw new RuntimeException('Immutable claim history requires archival.');
        Schema::dropIfExists('hall_inventory_financial_claims');
        Schema::table('hall_inventory_loan_incident_decisions',function(Blueprint $t):void {
            $t->dropUnique('fin_claim_review_scope');
        });
        Schema::table('hall_inventory_loan_incident_reports',function(Blueprint $t):void {
            $t->dropUnique('fin_claim_report_scope');
        });
    }
};
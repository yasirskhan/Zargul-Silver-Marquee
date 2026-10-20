<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        // Link the document to the exact original hall, loan, claim and money currency.
        Schema::table('hall_inventory_financial_claims',function(Blueprint $t):void {
            $t->unique(['company_id','id','loan_id','source_hall_id','currency'],
                'fin_partner_claim_scope');
        });
        Schema::create('hall_inventory_financial_counterparty_evidence',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');$t->uuid('claim_id');$t->uuid('loan_id');
            $t->uuid('source_hall_id');$t->char('currency',3);
            $t->string('position',40);
            $t->unsignedBigInteger('counteroffer_minor')->nullable();
            $t->char('evidence_sha256',64);$t->unsignedInteger('evidence_size');
            $t->string('evidence_mime',40);$t->string('evidence_path',500);
            $t->char('fingerprint',64);
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','id','claim_id'],'fin_partner_evidence_scope');
            $t->unique(['company_id','claim_id','fingerprint'],'fin_partner_evidence_replay');
            $t->index(['company_id','loan_id','source_hall_id']);
            $t->foreign(['company_id','claim_id','loan_id','source_hall_id','currency'],
                'fin_partner_exact_claim_fk')
                ->references(['company_id','id','loan_id','source_hall_id','currency'])
                ->on('hall_inventory_financial_claims')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_financial_counterparty_evidence
            ADD CONSTRAINT fin_partner_position_check CHECK (
                position IN ('document_says_accepts','document_says_disputes','counteroffer','other_document')
                AND evidence_size BETWEEN 1 AND 5242880
                AND evidence_sha256 ~ '^[0-9a-f]{64}$'
                AND (
                    (position = 'counteroffer' AND counteroffer_minor BETWEEN 1 AND 100000000000)
                    OR (position <> 'counteroffer' AND counteroffer_minor IS NULL)
                )
            )");
        DB::statement('CREATE TRIGGER immutable_fin_partner_evidence
            BEFORE UPDATE OR DELETE ON hall_inventory_financial_counterparty_evidence
            FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
        Schema::create('hall_inventory_financial_counterparty_reviews',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');$t->uuid('evidence_id');$t->uuid('claim_id');
            $t->string('assessment',40);$t->string('reason',500);
            $t->char('fingerprint',64);
            $t->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','evidence_id'],'fin_partner_one_review');
            $t->foreign(['company_id','evidence_id','claim_id'],'fin_partner_review_source_fk')
                ->references(['company_id','id','claim_id'])
                ->on('hall_inventory_financial_counterparty_evidence')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_financial_counterparty_reviews
            ADD CONSTRAINT fin_partner_review_check CHECK (
                assessment IN ('document_integrity_confirmed','insufficient_partner_proof')
            )");
        DB::statement('CREATE TRIGGER immutable_fin_partner_reviews
            BEFORE UPDATE OR DELETE ON hall_inventory_financial_counterparty_reviews
            FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_financial_counterparty_evidence')->exists() ||
           DB::table('hall_inventory_financial_counterparty_reviews')->exists())
            throw new RuntimeException('Counterparty evidence and review require archival.');
        Schema::dropIfExists('hall_inventory_financial_counterparty_reviews');
        Schema::dropIfExists('hall_inventory_financial_counterparty_evidence');
        Schema::table('hall_inventory_financial_claims',function(Blueprint $t):void {
            $t->dropUnique('fin_partner_claim_scope');
        });
    }
};

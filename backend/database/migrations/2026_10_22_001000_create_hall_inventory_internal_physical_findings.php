<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Original INTERNAL loan discrepancy findings are evidence, not stock/financial settlement. */
return new class extends Migration {
    public function up(): void {
        Schema::table('hall_inventory_internal_discrepancy_statements', function(Blueprint $t): void {
            $t->unique(['company_id','id','loan_id','source_item_id','kind'], 'internal_finding_borrower_scope');
        });
        Schema::table('hall_inventory_loan_incident_reports', function(Blueprint $t): void {
            $t->unique(['company_id','id','loan_id','source_item_id','source_hall_id','kind'], 'internal_finding_lender_scope');
        });
        Schema::create('hall_inventory_internal_physical_findings', function(Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('loan_id');
            $t->uuid('borrowing_hall_id'); $t->uuid('source_hall_id'); $t->uuid('source_item_id');
            $t->uuid('borrower_statement_id'); $t->uuid('source_incident_id');
            $t->string('kind',16); $t->unsignedInteger('quantity');
            $t->unsignedInteger('outstanding_snapshot');
            $t->string('reason',500); $t->char('fingerprint',64);
            $t->string('source_proof_path',500); $t->char('source_proof_sha256',64);
            $t->unsignedInteger('source_proof_size');
            $t->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','id'], 'internal_finding_identity');
            $t->unique(['company_id','borrower_statement_id'], 'internal_finding_one_borrower_statement');
            $t->unique(['company_id','source_incident_id'], 'internal_finding_one_source_incident');
            $t->foreign(['company_id','loan_id'])->references(['company_id','id'])
                ->on('hall_inventory_loan_drafts')->restrictOnDelete();
            $t->foreign(['company_id','borrower_statement_id','loan_id','source_item_id','kind'],
                'internal_finding_signed_borrower_fk')
                ->references(['company_id','id','loan_id','source_item_id','kind'])
                ->on('hall_inventory_internal_discrepancy_statements')->restrictOnDelete();
            $t->foreign(['company_id','source_incident_id','loan_id','source_item_id','source_hall_id','kind'],
                'internal_finding_source_incident_fk')
                ->references(['company_id','id','loan_id','source_item_id','source_hall_id','kind'])
                ->on('hall_inventory_loan_incident_reports')->restrictOnDelete();
        });
        Schema::create('hall_inventory_internal_physical_finding_decisions', function(Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('company_id'); $t->uuid('finding_id');
            $t->string('decision',32); $t->string('reason',500); $t->char('fingerprint',64);
            $t->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','finding_id'], 'internal_finding_decision_once');
            $t->foreign(['company_id','finding_id'])->references(['company_id','id'])
                ->on('hall_inventory_internal_physical_findings')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_internal_physical_findings ADD CONSTRAINT internal_finding_valid CHECK (kind IN ('missing','dispute') AND quantity BETWEEN 1 AND 100000000 AND outstanding_snapshot >= quantity AND length(reason) BETWEEN 10 AND 500 AND source_proof_size BETWEEN 1 AND 5242880)");
        DB::statement("ALTER TABLE hall_inventory_internal_physical_finding_decisions ADD CONSTRAINT internal_finding_decision_valid CHECK (decision IN ('confirmed_outstanding','rejected','requires_more_evidence') AND length(reason) BETWEEN 10 AND 500)");
        foreach (['hall_inventory_internal_physical_findings','hall_inventory_internal_physical_finding_decisions'] as $table)
            DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
    }
    public function down(): void {
        if (DB::table('hall_inventory_internal_physical_findings')->exists())
            throw new RuntimeException('Immutable internal physical findings cannot be rolled back.');
        Schema::dropIfExists('hall_inventory_internal_physical_finding_decisions');
        Schema::dropIfExists('hall_inventory_internal_physical_findings');
        Schema::table('hall_inventory_loan_incident_reports', function(Blueprint $t): void {
            $t->dropUnique('internal_finding_lender_scope');
        });
        Schema::table('hall_inventory_internal_discrepancy_statements', function(Blueprint $t): void {
            $t->dropUnique('internal_finding_borrower_scope');
        });
    }
};

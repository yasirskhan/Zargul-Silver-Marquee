<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provisional INTERNAL abnormal loss obligation and independent decision.
 * Evidence only: no journal, cash, stock retirement, waiver or closure.
 */
return new class extends Migration {
    public function up():void {
        Schema::table('hall_inventory_internal_physical_finding_decisions',function(Blueprint $t):void {
            $t->unique(['company_id','finding_id','id'],'internal_finding_decision_scope');
        });
        Schema::create('hall_inventory_internal_loss_obligations',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('loan_id');
            $t->uuid('source_hall_id'); $t->uuid('borrowing_hall_id');
            $t->uuid('finding_id'); $t->uuid('decision_id');
            $t->unsignedInteger('quantity_basis');
            $t->char('currency',3);
            $t->unsignedBigInteger('amount_minor');
            $t->string('reason',500); $t->char('fingerprint',64);
            $t->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','finding_id'],'internal_obligation_one_per_finding');
            $t->unique(['company_id','id'],'internal_obligation_identity');
            $t->foreign(['company_id','finding_id'],'internal_obligation_finding_fk')
                ->references(['company_id','id'])->on('hall_inventory_internal_physical_findings')
                ->restrictOnDelete();
            $t->foreign(['company_id','finding_id','decision_id'],'internal_obligation_decision_fk')
                ->references(['company_id','finding_id','id'])
                ->on('hall_inventory_internal_physical_finding_decisions')->restrictOnDelete();
            $t->foreign(['company_id','source_hall_id'])->references(['company_id','id'])
                ->on('halls')->restrictOnDelete();
            $t->foreign(['company_id','borrowing_hall_id'])->references(['company_id','id'])
                ->on('halls')->restrictOnDelete();
        });
        Schema::create('hall_inventory_internal_loss_obligation_decisions',function(Blueprint $t):void {
            $t->uuid('id')->primary(); $t->uuid('company_id'); $t->uuid('obligation_id');
            $t->string('decision',16); $t->string('reason',500); $t->char('fingerprint',64);
            $t->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','obligation_id'],'internal_obligation_one_decision');
            $t->foreign(['company_id','obligation_id'])->references(['company_id','id'])
                ->on('hall_inventory_internal_loss_obligations')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_internal_loss_obligations
            ADD CONSTRAINT internal_obligation_valid CHECK (
                quantity_basis BETWEEN 1 AND 100000000
                AND amount_minor BETWEEN 1 AND 100000000000
                AND currency ~ '^[A-Z]{3}$'
                AND length(reason) BETWEEN 10 AND 500)");
        DB::statement("ALTER TABLE hall_inventory_internal_loss_obligation_decisions
            ADD CONSTRAINT internal_obligation_decision_valid CHECK (
                decision IN ('approved','rejected','disputed')
                AND length(reason) BETWEEN 10 AND 500)");
        foreach(['hall_inventory_internal_loss_obligations',
            'hall_inventory_internal_loss_obligation_decisions'] as $table)
            DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
    }
    public function down():void {
        if(DB::table('hall_inventory_internal_loss_obligations')->exists())
            throw new RuntimeException('Immutable internal loss obligations cannot be rolled back.');
        Schema::dropIfExists('hall_inventory_internal_loss_obligation_decisions');
        Schema::dropIfExists('hall_inventory_internal_loss_obligations');
        Schema::table('hall_inventory_internal_physical_finding_decisions',function(Blueprint $t):void {
            $t->dropUnique('internal_finding_decision_scope');
        });
    }
};

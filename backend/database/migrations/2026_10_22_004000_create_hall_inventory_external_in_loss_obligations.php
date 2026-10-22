<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provisional EXTERNAL_IN partner loss obligation and independent decision.
 * No invoice, receivable, payable journal, payment, stock movement or closure.
 * Default-OFF release gate.
 */
return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_external_in_loss_obligations',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('hall_id'); $t->uuid('loan_id');
            $t->uuid('discrepancy_id'); $t->uuid('decision_id');
            $t->unsignedInteger('quantity_basis');
            $t->char('currency',3);
            $t->unsignedBigInteger('amount_minor');
            $t->string('reason',500);
            $t->char('fingerprint',64);
            $t->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','discrepancy_id'],'ext_in_obligation_one_per_discrepancy');
            $t->unique(['company_id','hall_id','id'],'ext_in_obligation_scope');
            $t->foreign(['company_id','hall_id','discrepancy_id'],'ext_in_obligation_discrepancy_fk')
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_external_in_discrepancies')->restrictOnDelete();
            $t->foreign(['company_id','discrepancy_id','decision_id'],'ext_in_obligation_decision_fk')
                ->references(['company_id','discrepancy_id','id'])
                ->on('hall_inventory_external_in_discrepancy_decisions')->restrictOnDelete();
        });
        Schema::create('hall_inventory_external_in_loss_obligation_decisions',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('hall_id'); $t->uuid('obligation_id');
            $t->string('decision',16);
            $t->string('reason',500);
            $t->char('fingerprint',64);
            $t->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','obligation_id'],'ext_in_obligation_one_decision');
            $t->foreign(['company_id','hall_id','obligation_id'],'ext_in_obligation_decision_scope_fk')
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_external_in_loss_obligations')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_external_in_loss_obligations
            ADD CONSTRAINT ext_in_obligation_valid CHECK (
                quantity_basis BETWEEN 1 AND 100000000
                AND amount_minor BETWEEN 1 AND 100000000000
                AND currency ~ '^[A-Z]{3}$'
                AND length(reason) BETWEEN 10 AND 500)");
        DB::statement("ALTER TABLE hall_inventory_external_in_loss_obligation_decisions
            ADD CONSTRAINT ext_in_obligation_decision_valid CHECK (
                decision IN ('approved','rejected','disputed')
                AND length(reason) BETWEEN 10 AND 500)");
        foreach(['hall_inventory_external_in_loss_obligations',
            'hall_inventory_external_in_loss_obligation_decisions'] as $table)
            DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
    }
    public function down():void {
        if(DB::table('hall_inventory_external_in_loss_obligations')->exists())
            throw new RuntimeException('Immutable external-in loss obligations cannot be rolled back.');
        Schema::dropIfExists('hall_inventory_external_in_loss_obligation_decisions');
        Schema::dropIfExists('hall_inventory_external_in_loss_obligations');
    }
};

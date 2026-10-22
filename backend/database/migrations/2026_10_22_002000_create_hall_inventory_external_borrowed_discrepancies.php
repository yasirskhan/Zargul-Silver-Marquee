<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partner-signed short or damaged return counts plus an independent review.
 * Evidence ONLY: no borrowed transit is cleared, no liability, journal, stock
 * retirement or numbered closure is created. Default-OFF release gate.
 */
return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_external_in_discrepancies',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('hall_id'); $t->uuid('loan_id'); $t->uuid('batch_id');
            $t->string('reason',500);
            $t->char('fingerprint',64);
            $t->char('evidence_sha256',64);
            $t->unsignedInteger('evidence_size');
            $t->string('evidence_storage_path',500);
            $t->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','batch_id'],'ext_in_discrepancy_one_per_batch');
            $t->unique(['company_id','hall_id','id'],'ext_in_discrepancy_scope');
            $t->foreign(['company_id','hall_id','batch_id'],'ext_in_discrepancy_batch_fk')
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_external_in_return_batches')->restrictOnDelete();
        });
        Schema::create('hall_inventory_external_in_discrepancy_lines',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('hall_id'); $t->uuid('discrepancy_id'); $t->uuid('item_id');
            $t->unsignedInteger('shipped_quantity');
            $t->unsignedInteger('received_quantity');
            $t->unsignedInteger('damaged_quantity');
            $t->unique(['company_id','discrepancy_id','item_id'],'ext_in_discrepancy_distinct_item');
            $t->foreign(['company_id','hall_id','discrepancy_id'],'ext_in_discrepancy_line_fk')
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_external_in_discrepancies')->restrictOnDelete();
            $t->foreign(['company_id','hall_id','item_id'],'ext_in_discrepancy_item_fk')
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_items')->restrictOnDelete();
        });
        Schema::create('hall_inventory_external_in_discrepancy_decisions',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('hall_id'); $t->uuid('discrepancy_id');
            $t->string('decision',24);
            $t->string('reason',500);
            $t->char('fingerprint',64);
            $t->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','discrepancy_id'],'ext_in_discrepancy_one_decision');
            $t->foreign(['company_id','hall_id','discrepancy_id'],'ext_in_discrepancy_decision_fk')
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_external_in_discrepancies')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_external_in_discrepancies
            ADD CONSTRAINT ext_in_discrepancy_valid CHECK (
                length(reason) BETWEEN 10 AND 500 AND evidence_size BETWEEN 1 AND 5242880)");
        DB::statement("ALTER TABLE hall_inventory_external_in_discrepancy_lines
            ADD CONSTRAINT ext_in_discrepancy_line_valid CHECK (
                shipped_quantity BETWEEN 1 AND 100000000
                AND received_quantity<=shipped_quantity
                AND damaged_quantity<=received_quantity)");
        DB::statement("ALTER TABLE hall_inventory_external_in_discrepancy_decisions
            ADD CONSTRAINT ext_in_discrepancy_decision_valid CHECK (
                decision IN ('substantiated','rejected','requires_more_evidence')
                AND length(reason) BETWEEN 10 AND 500)");
        foreach(['hall_inventory_external_in_discrepancies',
            'hall_inventory_external_in_discrepancy_lines',
            'hall_inventory_external_in_discrepancy_decisions'] as $table)
            DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
    }
    public function down():void {
        if(DB::table('hall_inventory_external_in_discrepancies')->exists())
            throw new RuntimeException('Immutable external borrowed discrepancies cannot be rolled back.');
        Schema::dropIfExists('hall_inventory_external_in_discrepancy_decisions');
        Schema::dropIfExists('hall_inventory_external_in_discrepancy_lines');
        Schema::dropIfExists('hall_inventory_external_in_discrepancies');
    }
};

<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::table('hall_inventory_internal_loan_return_acceptance_lines',function(Blueprint $t):void {
            $t->unique(['company_id','acceptance_id','source_item_id'],
                'internal_accepted_line_original_scope');
        });
        Schema::create('hall_inventory_internal_late_returns',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');$t->uuid('loan_id');
            $t->uuid('return_batch_id');$t->uuid('acceptance_id');
            $t->uuid('source_hall_id');$t->uuid('source_item_id');$t->uuid('dest_item_id');
            $t->unsignedInteger('good_quantity');$t->unsignedInteger('damaged_quantity');
            $t->uuid('good_movement_id')->nullable();
            $t->uuid('damaged_movement_id')->nullable();$t->uuid('dest_movement_id');
            $t->char('evidence_sha256',64);$t->unsignedInteger('evidence_size');
            $t->string('evidence_storage_path',500);$t->char('fingerprint',64);
            $t->foreignId('accepted_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','id'],'internal_late_company_scope');
            $t->foreign(['company_id','acceptance_id','source_item_id'],
                'internal_late_accepted_line_fk')
                ->references(['company_id','acceptance_id','source_item_id'])
                ->on('hall_inventory_internal_loan_return_acceptance_lines')->restrictOnDelete();
            $t->foreign(['company_id','return_batch_id','source_hall_id'],
                'internal_late_original_batch_fk')
                ->references(['company_id','id','source_hall_id'])
                ->on('hall_inventory_internal_loan_return_batches')->restrictOnDelete();
            $t->foreign(['company_id','source_hall_id','source_item_id','good_movement_id'],
                'internal_late_good_movement_fk')
                ->references(['company_id','hall_id','item_id','id'])
                ->on('hall_inventory_movement_events')->restrictOnDelete();
            $t->foreign(['company_id','source_hall_id','source_item_id','damaged_movement_id'],
                'internal_late_damaged_movement_fk')
                ->references(['company_id','hall_id','item_id','id'])
                ->on('hall_inventory_movement_events')->restrictOnDelete();
            $t->index(['company_id','loan_id','return_batch_id'],'internal_late_loan_history');
        });
        DB::statement('ALTER TABLE hall_inventory_internal_late_returns ADD CONSTRAINT internal_late_physical_qty CHECK (good_quantity + damaged_quantity BETWEEN 1 AND 100000000 AND (good_quantity = 0) = (good_movement_id IS NULL) AND (damaged_quantity = 0) = (damaged_movement_id IS NULL))');
        DB::statement('CREATE TRIGGER immutable_hall_inventory_internal_late_returns BEFORE UPDATE OR DELETE ON hall_inventory_internal_late_returns FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(Schema::hasTable('hall_inventory_internal_late_returns') &&
            DB::table('hall_inventory_internal_late_returns')->exists())
            throw new RuntimeException('Immutable later return custody requires archival.');
        Schema::dropIfExists('hall_inventory_internal_late_returns');
        Schema::table('hall_inventory_internal_loan_return_acceptance_lines',function(Blueprint $t):void {
            $t->dropUnique('internal_accepted_line_original_scope');
        });
    }
};

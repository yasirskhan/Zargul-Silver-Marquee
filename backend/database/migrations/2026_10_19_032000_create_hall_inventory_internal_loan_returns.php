<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_internal_loan_return_batches',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('loan_id');
            $t->uuid('receipt_id');
            $t->uuid('destination_hall_id');
            $t->uuid('source_hall_id');
            $t->char('evidence_sha256',64);
            $t->unsignedInteger('evidence_size');
            $t->string('evidence_storage_path',500);
            $t->foreignId('returned_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','id','destination_hall_id'],'return_batch_dest_scope_key');
            $t->unique(['company_id','id','source_hall_id'],'return_batch_source_scope_key');
            $t->foreign(['company_id','receipt_id','destination_hall_id'],'return_batch_receipt_fk')
                ->references(['company_id','id','receiving_hall_id'])
                ->on('hall_inventory_internal_loan_receipts')->restrictOnDelete();
            $t->foreign(['company_id','source_hall_id'],'return_batch_source_fk')
                ->references(['company_id','id'])->on('halls')->restrictOnDelete();
            $t->index(['company_id','loan_id','created_at'],'return_batch_loan_history');
        });
        Schema::create('hall_inventory_internal_loan_return_lines',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('return_batch_id');
            $t->uuid('destination_hall_id');
            $t->uuid('source_item_id');
            $t->uuid('item_id');
            $t->uuid('movement_id');
            $t->unsignedInteger('quantity');
            $t->foreign(['company_id','return_batch_id','destination_hall_id'],'return_line_batch_fk')
                ->references(['company_id','id','destination_hall_id'])
                ->on('hall_inventory_internal_loan_return_batches')->restrictOnDelete();
            $t->foreign(['company_id','destination_hall_id','item_id','movement_id'],'return_line_move_fk')
                ->references(['company_id','hall_id','item_id','id'])
                ->on('hall_inventory_movement_events')->restrictOnDelete();
            $t->unique(['return_batch_id','source_item_id'],'return_batch_unique_source_line');
            $t->unique('movement_id','return_batch_unique_movement');
        });
        DB::statement('ALTER TABLE hall_inventory_internal_loan_return_lines ADD CONSTRAINT return_batch_positive_qty CHECK (quantity > 0 AND quantity <= 100000000)');
        foreach(['hall_inventory_internal_loan_return_batches','hall_inventory_internal_loan_return_lines'] as $table)
            DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
    }

    public function down():void {
        if(DB::table('hall_inventory_internal_loan_return_batches')->exists())
            throw new RuntimeException('Immutable internal return batches require archival.');
        Schema::dropIfExists('hall_inventory_internal_loan_return_lines');
        Schema::dropIfExists('hall_inventory_internal_loan_return_batches');
    }
};

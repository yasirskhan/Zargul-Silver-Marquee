<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::table('hall_inventory_loan_dispatches',function(Blueprint $t):void {
            $t->unique(['company_id','id','loan_id','borrowing_hall_id'],
                'internal_loan_dispatch_receipt_scope_key');
        });
        Schema::create('hall_inventory_internal_loan_receipts',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('loan_id');
            $t->uuid('dispatch_id');
            $t->uuid('receiving_hall_id');
            $t->char('evidence_sha256',64);
            $t->unsignedInteger('evidence_size');
            $t->string('evidence_storage_path',500);
            $t->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','dispatch_id'],'internal_loan_receipt_once');
            $t->unique(['company_id','id','receiving_hall_id'],'internal_loan_receipt_scope_key');
            $t->foreign(['company_id','loan_id'],'internal_loan_receipt_draft_fk')
                ->references(['company_id','id'])->on('hall_inventory_loan_drafts')->restrictOnDelete();
            $t->foreign(['company_id','dispatch_id','loan_id','receiving_hall_id'],
                'internal_loan_receipt_dispatch_scope_fk')
                ->references(['company_id','id','loan_id','borrowing_hall_id'])
                ->on('hall_inventory_loan_dispatches')->restrictOnDelete();
            $t->foreign(['company_id','receiving_hall_id'],'internal_loan_receipt_hall_fk')
                ->references(['company_id','id'])->on('halls')->restrictOnDelete();
        });
        Schema::create('hall_inventory_internal_loan_receipt_lines',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('receipt_id');
            $t->uuid('hall_id');
            $t->uuid('source_item_id');
            $t->uuid('item_id');
            $t->uuid('movement_id');
            $t->unsignedInteger('quantity');
            $t->foreign(['company_id','receipt_id','hall_id'],'internal_loan_receipt_line_parent_fk')
                ->references(['company_id','id','receiving_hall_id'])
                ->on('hall_inventory_internal_loan_receipts')->restrictOnDelete();
            $t->foreign(['company_id','hall_id','item_id','movement_id'],'internal_loan_receipt_line_move_fk')
                ->references(['company_id','hall_id','item_id','id'])
                ->on('hall_inventory_movement_events')->restrictOnDelete();
            $t->foreign(['company_id','hall_id','item_id'],'internal_loan_receipt_line_item_fk')
                ->references(['company_id','hall_id','id'])->on('hall_inventory_items')->restrictOnDelete();
            $t->unique(['receipt_id','source_item_id'],'internal_loan_receipt_line_source_once');
            $t->unique(['receipt_id','item_id'],'internal_loan_receipt_line_dest_once');
            $t->unique('movement_id','internal_loan_receipt_line_move_once');
        });
        DB::statement('ALTER TABLE hall_inventory_internal_loan_receipt_lines ADD CONSTRAINT internal_loan_receipt_line_qty CHECK (quantity > 0 AND quantity <= 100000000)');
        foreach(['hall_inventory_internal_loan_receipts','hall_inventory_internal_loan_receipt_lines'] as $table) {
            DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
        }
    }
    public function down():void {
        if(DB::table('hall_inventory_internal_loan_receipts')->exists())
            throw new RuntimeException('Immutable destination custody must be archived first.');
        Schema::dropIfExists('hall_inventory_internal_loan_receipt_lines');
        Schema::dropIfExists('hall_inventory_internal_loan_receipts');
        Schema::table('hall_inventory_loan_dispatches',function(Blueprint $t):void {
            $t->dropUnique('internal_loan_dispatch_receipt_scope_key');
        });
    }
};

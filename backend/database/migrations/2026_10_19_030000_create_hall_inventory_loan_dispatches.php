<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_loan_dispatches',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('loan_id');
            $t->uuid('lending_hall_id'); $t->uuid('borrowing_hall_id');
            $t->char('evidence_sha256',64);
            $t->unsignedInteger('evidence_size');
            $t->string('evidence_storage_path',500);
            $t->foreignId('dispatched_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','loan_id'],'loan_dispatch_one_per_draft');
            $t->foreign(['company_id','loan_id'],'loan_dispatch_draft_fk')
                ->references(['company_id','id'])->on('hall_inventory_loan_drafts')->restrictOnDelete();
            $t->foreign(['company_id','lending_hall_id'],'loan_dispatch_source_fk')
                ->references(['company_id','id'])->on('halls')->restrictOnDelete();
            $t->foreign(['company_id','borrowing_hall_id'],'loan_dispatch_target_fk')
                ->references(['company_id','id'])->on('halls')->restrictOnDelete();
        });
        Schema::create('hall_inventory_loan_dispatch_lines',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');$t->uuid('dispatch_id');
            $t->uuid('item_id'); $t->uuid('movement_id');
            $t->unsignedInteger('quantity');
            $t->foreign(['company_id','dispatch_id'],'loan_dispatch_line_parent_fk')
                ->references(['company_id','id'])->on('hall_inventory_loan_dispatches')->restrictOnDelete();
            $t->foreign('movement_id','loan_dispatch_line_movement_fk')
                ->references('id')->on('hall_inventory_movement_events')->restrictOnDelete();
            $t->unique(['dispatch_id','item_id'],'loan_dispatch_line_unique_item');
            $t->unique('movement_id','loan_dispatch_line_unique_movement');
        });
        Schema::table('hall_inventory_loan_dispatches',function(Blueprint $t):void {
            $t->unique(['company_id','id'],'loan_dispatch_tenant_id_key');
        });
        DB::statement('ALTER TABLE hall_inventory_loan_dispatch_lines ADD CONSTRAINT loan_dispatch_line_quantity_valid CHECK (quantity>0 AND quantity<=100000000)');
        foreach(['hall_inventory_loan_dispatches','hall_inventory_loan_dispatch_lines'] as $table) {
            DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
        }
    }
    public function down():void {
        if(DB::table('hall_inventory_loan_dispatches')->exists())
            throw new RuntimeException('Immutable dispatch custody must be archived before rollback.');
        Schema::dropIfExists('hall_inventory_loan_dispatch_lines');
        Schema::dropIfExists('hall_inventory_loan_dispatches');
    }
};

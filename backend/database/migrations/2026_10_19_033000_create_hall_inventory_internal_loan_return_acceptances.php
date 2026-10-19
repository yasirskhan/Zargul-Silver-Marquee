<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_internal_loan_return_acceptances',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('return_batch_id');
            $t->uuid('source_hall_id');
            $t->char('review_fingerprint',64);
            $t->char('evidence_sha256',64);
            $t->unsignedInteger('evidence_size');
            $t->string('evidence_storage_path',500);
            $t->foreignId('accepted_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','return_batch_id'],'return_acceptance_once');
            $t->unique(['company_id','id','source_hall_id'],'return_acceptance_scope_key');
            $t->foreign(['company_id','return_batch_id','source_hall_id'],'return_acceptance_batch_fk')
                ->references(['company_id','id','source_hall_id'])
                ->on('hall_inventory_internal_loan_return_batches')->restrictOnDelete();
        });
        Schema::create('hall_inventory_internal_loan_return_acceptance_lines',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('acceptance_id');
            $t->uuid('source_hall_id');
            $t->uuid('source_item_id');
            $t->uuid('dest_item_id');
            $t->unsignedInteger('sent_quantity');
            $t->unsignedInteger('good_quantity')->default(0);
            $t->unsignedInteger('damaged_quantity')->default(0);
            $t->unsignedInteger('unresolved_quantity')->default(0);
            $t->uuid('source_good_movement_id')->nullable();
            $t->uuid('source_damage_movement_id')->nullable();
            $t->uuid('destination_movement_id')->nullable();
            $t->foreign(['company_id','acceptance_id','source_hall_id'],'return_accepted_line_parent_fk')
                ->references(['company_id','id','source_hall_id'])
                ->on('hall_inventory_internal_loan_return_acceptances')->restrictOnDelete();
            $t->foreign(['company_id','source_hall_id','source_item_id'],'return_accepted_source_item_fk')
                ->references(['company_id','hall_id','id'])->on('hall_inventory_items')->restrictOnDelete();
            $t->unique(['acceptance_id','source_item_id'],'return_accepted_source_unique');
        });
        DB::statement('ALTER TABLE hall_inventory_internal_loan_return_acceptance_lines ADD CONSTRAINT return_acceptance_quantities_valid CHECK (sent_quantity>0 AND sent_quantity<=100000000 AND good_quantity>=0 AND damaged_quantity>=0 AND unresolved_quantity>=0 AND good_quantity+damaged_quantity+unresolved_quantity=sent_quantity)');
        foreach(['hall_inventory_internal_loan_return_acceptances','hall_inventory_internal_loan_return_acceptance_lines'] as $table)
            DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
    }
    public function down():void {
        if(DB::table('hall_inventory_internal_loan_return_acceptances')->exists())
            throw new RuntimeException('Immutable return acknowledgments must be archived.');
        Schema::dropIfExists('hall_inventory_internal_loan_return_acceptance_lines');
        Schema::dropIfExists('hall_inventory_internal_loan_return_acceptances');
    }
};

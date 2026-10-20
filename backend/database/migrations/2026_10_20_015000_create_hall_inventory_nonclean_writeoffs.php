<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::table('hall_inventory_loan_incident_reports',function(Blueprint $t):void {
            $t->unique(['company_id','id','loan_id','source_hall_id','source_item_id'],'writeoff_incident_source_key');
        });
        Schema::create('hall_inventory_nonclean_writeoff_proposals',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('loan_id');
            $t->uuid('hall_id');$t->uuid('item_id');$t->uuid('incident_id');
            $t->string('kind',12);$t->unsignedInteger('quantity');
            $t->string('reason',500);$t->char('fingerprint',64);
            $t->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','incident_id'],'writeoff_one_proposal_per_incident');
            $t->unique(['company_id','id','loan_id','hall_id','item_id'],'writeoff_proposal_exact_scope');
            $t->foreign(['company_id','incident_id','loan_id','hall_id','item_id'],'writeoff_incident_exact_scope_fk')
                ->references(['company_id','id','loan_id','source_hall_id','source_item_id'])
                ->on('hall_inventory_loan_incident_reports')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_nonclean_writeoff_proposals
            ADD CONSTRAINT writeoff_proposal_valid CHECK (
                kind IN ('damage','missing') AND quantity BETWEEN 1 AND 100000000
                AND length(reason) BETWEEN 10 AND 500
            )");
        DB::statement('CREATE TRIGGER immutable_nonclean_writeoff_proposals BEFORE UPDATE OR DELETE
            ON hall_inventory_nonclean_writeoff_proposals FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
        Schema::create('hall_inventory_nonclean_writeoff_decisions',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('proposal_id');
            $t->uuid('loan_id');$t->uuid('hall_id');$t->uuid('item_id');
            $t->string('decision',12);$t->string('reason',500);
            $t->char('fingerprint',64);$t->uuid('movement_id')->nullable();
            $t->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','proposal_id'],'writeoff_one_final_decision');
            $t->unique(['company_id','movement_id'],'writeoff_one_movement');
            $t->foreign(['company_id','proposal_id','loan_id','hall_id','item_id'],'writeoff_decision_exact_scope_fk')
                ->references(['company_id','id','loan_id','hall_id','item_id'])
                ->on('hall_inventory_nonclean_writeoff_proposals')->restrictOnDelete();
            $t->foreign(['company_id','hall_id','item_id','movement_id'],'writeoff_movement_exact_fk')
                ->references(['company_id','hall_id','item_id','id'])
                ->on('hall_inventory_movement_events')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_nonclean_writeoff_decisions
            ADD CONSTRAINT writeoff_decision_valid CHECK (
                (decision='approved' AND movement_id IS NOT NULL) OR
                (decision='rejected' AND movement_id IS NULL)
            )");
        DB::statement('CREATE TRIGGER immutable_nonclean_writeoff_decisions BEFORE UPDATE OR DELETE
            ON hall_inventory_nonclean_writeoff_decisions FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
        DB::statement('ALTER TABLE hall_inventory_movement_events DROP CONSTRAINT hall_inventory_movement_kind_check');
        DB::statement("ALTER TABLE hall_inventory_movement_events ADD CONSTRAINT hall_inventory_movement_kind_check CHECK (
            event_kind IN ('opening_owned','acquire_owned','dispatch_owned_loan','return_owned_good',
                'return_owned_damaged','accept_borrowed','send_borrowed_back','confirm_borrowed_return',
                'damage_borrowed','reclassify_owned_damage','restore_owned_usable',
                'writeoff_owned_on_loan','writeoff_owned_damaged'))");
    }
    public function down():void {
        if(DB::table('hall_inventory_nonclean_writeoff_proposals')->exists() ||
           DB::table('hall_inventory_nonclean_writeoff_decisions')->exists())
            throw new RuntimeException('Immutable write-off decisions must be archived before rollback.');
        Schema::dropIfExists('hall_inventory_nonclean_writeoff_decisions');
        Schema::dropIfExists('hall_inventory_nonclean_writeoff_proposals');
        Schema::table('hall_inventory_loan_incident_reports',fn(Blueprint $t)=>
            $t->dropUnique('writeoff_incident_source_key'));
        DB::statement('ALTER TABLE hall_inventory_movement_events DROP CONSTRAINT hall_inventory_movement_kind_check');
        DB::statement("ALTER TABLE hall_inventory_movement_events ADD CONSTRAINT hall_inventory_movement_kind_check CHECK (
            event_kind IN ('opening_owned','acquire_owned','dispatch_owned_loan','return_owned_good',
                'return_owned_damaged','accept_borrowed','send_borrowed_back','confirm_borrowed_return',
                'damage_borrowed','reclassify_owned_damage','restore_owned_usable'))");
    }
};
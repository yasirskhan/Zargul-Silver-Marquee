<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::table('hall_inventory_loan_draft_lines',function(Blueprint $t):void {
            $t->unique(['company_id','loan_id','catalog_hall_id','item_id'],'ext_out_draft_line_scope_key');
        });
        Schema::create('hall_inventory_external_out_handovers',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id');$t->uuid('loan_id');$t->uuid('hall_id');
            $t->char('evidence_sha256',64);$t->unsignedInteger('evidence_size');
            $t->string('evidence_storage_path',500);
            $t->foreignId('witnessed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','loan_id'],'ext_out_handover_one_loan_key');
            $t->unique(['company_id','id','loan_id','hall_id'],'ext_out_handover_scope_key');
            $t->foreign(['company_id','loan_id'],'ext_out_handover_loan_fk')
                ->references(['company_id','id'])->on('hall_inventory_loan_drafts')->restrictOnDelete();
            $t->foreign(['company_id','hall_id'],'ext_out_handover_hall_fk')
                ->references(['company_id','id'])->on('halls')->restrictOnDelete();
        });
        Schema::create('hall_inventory_external_out_handover_lines',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('handover_id');
            $t->uuid('loan_id');$t->uuid('hall_id');$t->uuid('item_id');
            $t->unsignedInteger('counted_quantity');
            $t->unique(['handover_id','item_id'],'ext_out_handover_item_key');
            $t->foreign(['company_id','handover_id','loan_id','hall_id'],'ext_out_line_handover_fk')
                ->references(['company_id','id','loan_id','hall_id'])
                ->on('hall_inventory_external_out_handovers')->restrictOnDelete();
            $t->foreign(['company_id','loan_id','hall_id','item_id'],'ext_out_line_original_draft_fk')
                ->references(['company_id','loan_id','catalog_hall_id','item_id'])
                ->on('hall_inventory_loan_draft_lines')->restrictOnDelete();
        });
        Schema::create('hall_inventory_external_out_dispatches',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('loan_id');
            $t->uuid('hall_id');$t->uuid('handover_id');
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','loan_id'],'ext_out_dispatch_one_loan_key');
            $t->unique(['company_id','id','hall_id'],'ext_out_dispatch_scope_key');
            $t->foreign(['company_id','handover_id','loan_id','hall_id'],'ext_out_dispatch_handover_fk')
                ->references(['company_id','id','loan_id','hall_id'])
                ->on('hall_inventory_external_out_handovers')->restrictOnDelete();
        });
        Schema::create('hall_inventory_external_out_dispatch_lines',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('dispatch_id');
            $t->uuid('hall_id');$t->uuid('item_id');$t->uuid('movement_id');
            $t->unsignedInteger('quantity');
            $t->unique(['dispatch_id','item_id'],'ext_out_dispatch_item_key');
            $t->unique('movement_id','ext_out_dispatch_movement_key');
            $t->foreign(['company_id','dispatch_id','hall_id'],'ext_out_dispatch_line_parent_fk')
                ->references(['company_id','id','hall_id'])
                ->on('hall_inventory_external_out_dispatches')->restrictOnDelete();
            $t->foreign(['company_id','hall_id','item_id','movement_id'],'ext_out_dispatch_line_movement_fk')
                ->references(['company_id','hall_id','item_id','id'])
                ->on('hall_inventory_movement_events')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE hall_inventory_external_out_handover_lines ADD CONSTRAINT ext_out_count_valid CHECK (counted_quantity<=100000000)');
        DB::statement('ALTER TABLE hall_inventory_external_out_dispatch_lines ADD CONSTRAINT ext_out_post_qty_valid CHECK (quantity>0 AND quantity<=100000000)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION venuedesk_guard_external_out_handover() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM hall_inventory_loan_drafts d
        WHERE d.company_id=NEW.company_id AND d.id=NEW.loan_id
          AND d.direction='external_out' AND d.lending_hall_id=NEW.hall_id
          AND d.borrowing_hall_id IS NULL AND d.external_partner_id IS NOT NULL
    ) THEN
        RAISE EXCEPTION 'External lending evidence must belong to an exact external_out source hall';
    END IF;
    RETURN NEW;
END
$$;
SQL);
        DB::statement('CREATE TRIGGER ext_out_handover_exact_draft BEFORE INSERT ON hall_inventory_external_out_handovers FOR EACH ROW EXECUTE FUNCTION venuedesk_guard_external_out_handover()');
        foreach(['hall_inventory_external_out_handovers','hall_inventory_external_out_handover_lines',
                 'hall_inventory_external_out_dispatches','hall_inventory_external_out_dispatch_lines'] as $name) {
            DB::statement("CREATE TRIGGER immutable_{$name} BEFORE UPDATE OR DELETE ON {$name} FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
        }
    }
    public function down():void {
        if(DB::table('hall_inventory_external_out_handovers')->exists()||
           DB::table('hall_inventory_external_out_dispatches')->exists())
            throw new RuntimeException('Signed outside lending evidence requires archival before rollback.');
        Schema::dropIfExists('hall_inventory_external_out_dispatch_lines');
        Schema::dropIfExists('hall_inventory_external_out_dispatches');
        Schema::dropIfExists('hall_inventory_external_out_handover_lines');
        Schema::dropIfExists('hall_inventory_external_out_handovers');
        Schema::table('hall_inventory_loan_draft_lines',function(Blueprint $t):void {
            $t->dropUnique('ext_out_draft_line_scope_key');
        });
        DB::statement('DROP FUNCTION IF EXISTS venuedesk_guard_external_out_handover()');
    }
};
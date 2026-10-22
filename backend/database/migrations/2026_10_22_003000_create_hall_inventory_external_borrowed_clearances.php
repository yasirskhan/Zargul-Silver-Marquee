<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clear exactly the partner-received units from borrowed transit after an
 * independent substantiated discrepancy decision. Missing units stay in
 * transit. No liability, journal, payment, stock retirement or closure.
 */
return new class extends Migration {
    public function up():void {
        Schema::table('hall_inventory_external_in_discrepancy_decisions',function(Blueprint $t):void {
            $t->unique(['company_id','discrepancy_id','id'],'ext_in_discrepancy_decision_scope');
        });
        Schema::create('hall_inventory_external_in_discrepancy_clearances',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('hall_id'); $t->uuid('loan_id');
            $t->uuid('discrepancy_id'); $t->uuid('decision_id');
            $t->char('fingerprint',64);
            $t->foreignId('cleared_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','discrepancy_id'],'ext_in_clearance_one_per_discrepancy');
            $t->unique(['company_id','hall_id','id'],'ext_in_clearance_scope');
            $t->foreign(['company_id','hall_id','discrepancy_id'],'ext_in_clearance_discrepancy_fk')
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_external_in_discrepancies')->restrictOnDelete();
            $t->foreign(['company_id','discrepancy_id','decision_id'],'ext_in_clearance_decision_fk')
                ->references(['company_id','discrepancy_id','id'])
                ->on('hall_inventory_external_in_discrepancy_decisions')->restrictOnDelete();
        });
        Schema::create('hall_inventory_external_in_discrepancy_clearance_lines',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('company_id'); $t->uuid('hall_id'); $t->uuid('clearance_id');
            $t->uuid('item_id'); $t->uuid('movement_id');
            $t->unsignedInteger('quantity');
            $t->unique(['company_id','clearance_id','item_id'],'ext_in_clearance_distinct_item');
            $t->unique('movement_id','ext_in_clearance_distinct_movement');
            $t->foreign(['company_id','hall_id','clearance_id'],'ext_in_clearance_line_fk')
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_external_in_discrepancy_clearances')->restrictOnDelete();
            $t->foreign(['company_id','hall_id','item_id','movement_id'],'ext_in_clearance_movement_fk')
                ->references(['company_id','hall_id','item_id','id'])
                ->on('hall_inventory_movement_events')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hall_inventory_external_in_discrepancy_clearances
            ADD CONSTRAINT ext_in_clearance_valid CHECK (length(fingerprint)=64)");
        DB::statement("ALTER TABLE hall_inventory_external_in_discrepancy_clearance_lines
            ADD CONSTRAINT ext_in_clearance_line_valid CHECK (quantity BETWEEN 1 AND 100000000)");
        foreach(['hall_inventory_external_in_discrepancy_clearances',
            'hall_inventory_external_in_discrepancy_clearance_lines'] as $table)
            DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION venuedesk_guard_external_clearance_line() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS(
        SELECT 1
        FROM hall_inventory_external_in_discrepancy_clearances c
        JOIN hall_inventory_external_in_discrepancy_decisions x
            ON x.company_id=c.company_id AND x.discrepancy_id=c.discrepancy_id
            AND x.id=c.decision_id AND x.decision='substantiated'
        JOIN hall_inventory_external_in_discrepancy_lines dl
            ON dl.company_id=c.company_id AND dl.discrepancy_id=c.discrepancy_id
            AND dl.item_id=NEW.item_id AND dl.received_quantity=NEW.quantity
        JOIN hall_inventory_movement_events m
            ON m.company_id=NEW.company_id AND m.hall_id=NEW.hall_id
            AND m.item_id=NEW.item_id AND m.id=NEW.movement_id
            AND m.event_kind='confirm_borrowed_return' AND m.quantity=NEW.quantity
        WHERE c.company_id=NEW.company_id AND c.hall_id=NEW.hall_id
            AND c.id=NEW.clearance_id
    ) THEN RAISE EXCEPTION 'Clearance line must match a substantiated partner-received quantity and exact transit movement'; END IF;
    RETURN NEW;
END $$;
SQL);
        DB::statement('CREATE TRIGGER guard_external_clearance_line
            BEFORE INSERT ON hall_inventory_external_in_discrepancy_clearance_lines
            FOR EACH ROW EXECUTE FUNCTION venuedesk_guard_external_clearance_line()');
    }
    public function down():void {
        if(DB::table('hall_inventory_external_in_discrepancy_clearances')->exists())
            throw new RuntimeException('Immutable partner receipt clearances cannot be rolled back.');
        Schema::dropIfExists('hall_inventory_external_in_discrepancy_clearance_lines');
        Schema::dropIfExists('hall_inventory_external_in_discrepancy_clearances');
        DB::statement('DROP FUNCTION IF EXISTS venuedesk_guard_external_clearance_line()');
        Schema::table('hall_inventory_external_in_discrepancy_decisions',function(Blueprint $t):void {
            $t->dropUnique('ext_in_discrepancy_decision_scope');
        });
    }
};

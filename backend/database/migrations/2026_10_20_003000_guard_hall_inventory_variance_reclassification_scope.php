<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up():void {
        // The reference IDs are individually valid but must also identify
        // the SAME tenant, hall, physical count and stock item.
        DB::statement('CREATE UNIQUE INDEX stocktake_reclass_line_scope_key ON hall_inventory_stocktake_lines (company_id,hall_id,stocktake_id,id)');
        DB::statement('CREATE UNIQUE INDEX stocktake_reclass_ack_scope_key ON hall_inventory_variance_review_acknowledgments (company_id,hall_id,stocktake_id,id)');
        DB::statement('CREATE UNIQUE INDEX stocktake_reclass_position_scope_key ON hall_inventory_positions (company_id,hall_id,id)');
        DB::statement('CREATE UNIQUE INDEX stocktake_reclass_movement_scope_key ON hall_inventory_movement_events (company_id,hall_id,id)');
        DB::statement('ALTER TABLE hall_inventory_variance_reclassifications
            ADD CONSTRAINT stocktake_reclass_line_scope_fk
            FOREIGN KEY (company_id,hall_id,stocktake_id,stocktake_line_id)
            REFERENCES hall_inventory_stocktake_lines(company_id,hall_id,stocktake_id,id)');
        DB::statement('ALTER TABLE hall_inventory_variance_reclassifications
            ADD CONSTRAINT stocktake_reclass_ack_scope_fk
            FOREIGN KEY (company_id,hall_id,stocktake_id,acknowledgment_id)
            REFERENCES hall_inventory_variance_review_acknowledgments(company_id,hall_id,stocktake_id,id)');
        DB::statement('ALTER TABLE hall_inventory_variance_reclassifications
            ADD CONSTRAINT stocktake_reclass_pos_scope_fk
            FOREIGN KEY (company_id,hall_id,position_id)
            REFERENCES hall_inventory_positions(company_id,hall_id,id)');
        DB::statement('ALTER TABLE hall_inventory_variance_reclassifications
            ADD CONSTRAINT stocktake_reclass_mov_scope_fk
            FOREIGN KEY (company_id,hall_id,movement_id)
            REFERENCES hall_inventory_movement_events(company_id,hall_id,id)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION venuedesk_guard_variance_reclassification() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM hall_inventory_stocktake_lines l
        INNER JOIN hall_inventory_positions p
            ON p.company_id=l.company_id AND p.hall_id=l.hall_id
            AND p.item_id=l.item_id AND p.id=NEW.position_id
        INNER JOIN hall_inventory_movement_events m
            ON m.company_id=l.company_id AND m.hall_id=l.hall_id
            AND m.item_id=l.item_id AND m.position_id=p.id
            AND m.id=NEW.movement_id
            AND m.event_kind=NEW.event_kind
            AND m.revision_before=NEW.book_revision
            AND m.quantity=NEW.quantity
        WHERE l.company_id=NEW.company_id AND l.hall_id=NEW.hall_id
            AND l.stocktake_id=NEW.stocktake_id
            AND l.id=NEW.stocktake_line_id
    ) THEN
        RAISE EXCEPTION 'Variance reclassification must reference exact line, position and movement';
    END IF;
    RETURN NEW;
END
$$;
SQL);
        DB::statement('CREATE TRIGGER variance_reclass_reference_guard
            BEFORE INSERT ON hall_inventory_variance_reclassifications
            FOR EACH ROW EXECUTE FUNCTION venuedesk_guard_variance_reclassification()');
    }

    public function down():void {
        if(DB::table('hall_inventory_variance_reclassifications')->exists())
            throw new RuntimeException('Adjudication provenance must be archived before rollback.');
        DB::statement('DROP TRIGGER IF EXISTS variance_reclass_reference_guard ON hall_inventory_variance_reclassifications');
        DB::statement('DROP FUNCTION IF EXISTS venuedesk_guard_variance_reclassification()');
        foreach(['line','ack','pos','mov'] as $what)
            DB::statement('ALTER TABLE hall_inventory_variance_reclassifications DROP CONSTRAINT stocktake_reclass_'.$what.'_scope_fk');
        foreach(['stocktake_reclass_line_scope_key' =>'hall_inventory_stocktake_lines',
            'stocktake_reclass_ack_scope_key'=>'hall_inventory_variance_review_acknowledgments',
            'stocktake_reclass_position_scope_key'=>'hall_inventory_positions',
            'stocktake_reclass_movement_scope_key'=>'hall_inventory_movement_events'] as $index=>$table)
            DB::statement('DROP INDEX IF EXISTS '.$index);
    }
};

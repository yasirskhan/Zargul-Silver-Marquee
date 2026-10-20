<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_external_in_return_batches',function(Blueprint $t):void {
            $t->uuid('id')->primary(); $t->uuid('company_id'); $t->uuid('loan_id'); $t->uuid('hall_id');
            $t->char('evidence_sha256',64); $t->unsignedInteger('evidence_size');
            $t->string('evidence_storage_path',500);
            $t->foreignId('dispatched_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','hall_id','id'],'ext_in_return_scope');
            $t->foreign(['company_id','loan_id'])->references(['company_id','loan_id'])
                ->on('hall_inventory_external_in_acceptances')->restrictOnDelete();
            $t->foreign(['company_id','hall_id'])->references(['company_id','id'])
                ->on('halls')->restrictOnDelete();
        });
        Schema::create('hall_inventory_external_in_return_lines',function(Blueprint $t):void {
            $t->uuid('id')->primary(); $t->uuid('company_id'); $t->uuid('hall_id');
            $t->uuid('batch_id'); $t->uuid('item_id'); $t->uuid('movement_id');
            $t->unsignedInteger('quantity');
            $t->foreign(['company_id','hall_id','batch_id'],'ext_in_return_batch_fk')
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_external_in_return_batches')->restrictOnDelete();
            $t->foreign(['company_id','hall_id','item_id','movement_id'],'ext_in_return_move_fk')
                ->references(['company_id','hall_id','item_id','id'])
                ->on('hall_inventory_movement_events')->restrictOnDelete();
            $t->unique(['batch_id','item_id'],'ext_in_return_distinct_item');
            $t->unique('movement_id','ext_in_return_distinct_move');

        });
        Schema::create('hall_inventory_external_in_return_confirmations',function(Blueprint $t):void {
            $t->uuid('id')->primary(); $t->uuid('company_id'); $t->uuid('hall_id'); $t->uuid('batch_id');
            $t->char('evidence_sha256',64); $t->unsignedInteger('evidence_size');
            $t->string('evidence_storage_path',500);
            $t->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','batch_id'],'ext_in_return_one_confirmation');
            $t->unique(['company_id','hall_id','id'],'ext_in_return_confirmation_scope');
            $t->foreign(['company_id','hall_id','batch_id'],'ext_in_return_confirm_batch_fk')
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_external_in_return_batches')->restrictOnDelete();
        });
        Schema::create('hall_inventory_external_in_return_confirmation_lines',function(Blueprint $t):void {
            $t->uuid('id')->primary(); $t->uuid('company_id'); $t->uuid('hall_id');
            $t->uuid('confirmation_id'); $t->uuid('item_id'); $t->uuid('movement_id');
            $t->unsignedInteger('quantity');
            $t->foreign(['company_id','hall_id','confirmation_id'],'ext_in_return_confirm_line_fk')
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_external_in_return_confirmations')->restrictOnDelete();
            $t->foreign(['company_id','hall_id','item_id','movement_id'],'ext_in_return_confirm_move_fk')
                ->references(['company_id','hall_id','item_id','id'])
                ->on('hall_inventory_movement_events')->restrictOnDelete();
            $t->unique(['confirmation_id','item_id'],'ext_in_return_confirm_distinct_item');
            $t->unique('movement_id','ext_in_return_confirm_distinct_move');

        });
        DB::statement('ALTER TABLE hall_inventory_external_in_return_lines ADD CONSTRAINT ext_in_return_qty_check CHECK(quantity BETWEEN 1 AND 100000000)');
        DB::statement('ALTER TABLE hall_inventory_external_in_return_confirmation_lines ADD CONSTRAINT ext_in_return_confirm_qty_check CHECK(quantity BETWEEN 1 AND 100000000)');
        foreach(['hall_inventory_external_in_return_batches','hall_inventory_external_in_return_lines',
            'hall_inventory_external_in_return_confirmations','hall_inventory_external_in_return_confirmation_lines'] as $table) {
            DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION venuedesk_guard_external_return_line() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS(
        SELECT 1 FROM hall_inventory_external_in_return_batches b
        JOIN hall_inventory_external_in_acceptances a
            ON a.company_id=b.company_id AND a.loan_id=b.loan_id AND a.hall_id=b.hall_id
        JOIN hall_inventory_external_in_acceptance_lines al
            ON al.company_id=a.company_id AND al.acceptance_id=a.id
            AND al.hall_id=b.hall_id AND al.item_id=NEW.item_id
        JOIN hall_inventory_movement_events m
            ON m.company_id=NEW.company_id AND m.hall_id=NEW.hall_id
            AND m.item_id=NEW.item_id AND m.id=NEW.movement_id
            AND m.event_kind='send_borrowed_back' AND m.quantity=NEW.quantity
        WHERE b.company_id=NEW.company_id AND b.id=NEW.batch_id
            AND b.hall_id=NEW.hall_id
    ) THEN RAISE EXCEPTION 'External return line must reference accepted borrowed loan and exact transit movement'; END IF;
    RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION venuedesk_guard_external_return_confirmation_line() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS(
        SELECT 1 FROM hall_inventory_external_in_return_confirmations c
        JOIN hall_inventory_external_in_return_lines l
            ON l.company_id=c.company_id AND l.batch_id=c.batch_id
            AND l.hall_id=c.hall_id AND l.item_id=NEW.item_id
            AND l.quantity=NEW.quantity
        JOIN hall_inventory_movement_events m
            ON m.company_id=NEW.company_id AND m.hall_id=NEW.hall_id
            AND m.item_id=NEW.item_id AND m.id=NEW.movement_id
            AND m.event_kind='confirm_borrowed_return' AND m.quantity=NEW.quantity
        WHERE c.company_id=NEW.company_id AND c.id=NEW.confirmation_id
            AND c.hall_id=NEW.hall_id
    ) THEN RAISE EXCEPTION 'Partner return acknowledgment must match the exact originally shipped line'; END IF;
    RETURN NEW;
END $$;
SQL);
        DB::statement('CREATE TRIGGER guard_ext_in_return_line BEFORE INSERT ON hall_inventory_external_in_return_lines
            FOR EACH ROW EXECUTE FUNCTION venuedesk_guard_external_return_line()');
        DB::statement('CREATE TRIGGER guard_ext_in_return_confirmation_line BEFORE INSERT ON hall_inventory_external_in_return_confirmation_lines
            FOR EACH ROW EXECUTE FUNCTION venuedesk_guard_external_return_confirmation_line()');
    }
    public function down():void {
        if(DB::table('hall_inventory_external_in_return_batches')->exists())
            throw new RuntimeException('Immutable borrowed returns require archival before rollback.');
        Schema::dropIfExists('hall_inventory_external_in_return_confirmation_lines');
        Schema::dropIfExists('hall_inventory_external_in_return_confirmations');
        Schema::dropIfExists('hall_inventory_external_in_return_lines');
        Schema::dropIfExists('hall_inventory_external_in_return_batches');
        DB::statement('DROP FUNCTION IF EXISTS venuedesk_guard_external_return_confirmation_line()');
        DB::statement('DROP FUNCTION IF EXISTS venuedesk_guard_external_return_line()');
    }
};

<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('hall_inventory_external_in_receipts',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('loan_id');
            $t->uuid('hall_id');$t->char('evidence_sha256',64);
            $t->unsignedInteger('evidence_size');$t->string('evidence_storage_path',500);
            $t->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','loan_id'],'ext_in_one_receipt');
            $t->unique(['company_id','loan_id','hall_id','id'],'ext_in_receipt_scope_key');
            $t->foreign(['company_id','loan_id'])->references(['company_id','id'])
                ->on('hall_inventory_loan_drafts')->restrictOnDelete();
            $t->foreign(['company_id','hall_id'])->references(['company_id','id'])
                ->on('halls')->restrictOnDelete();
        });
        // Actual independently counted delivery rows are immutable and scoped to
        // one receipt; draft requested quantities alone never prove custody.
        Schema::create('hall_inventory_external_in_receipt_lines',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->uuid('company_id');
            $t->uuid('loan_id');$t->uuid('hall_id');$t->uuid('receipt_id');
            $t->uuid('item_id');$t->unsignedInteger('counted_quantity');
            $t->foreign(['company_id','loan_id','hall_id','receipt_id'],
                'ext_in_count_parent_fk')->references(['company_id','loan_id','hall_id','id'])
                ->on('hall_inventory_external_in_receipts')->restrictOnDelete();
            $t->foreign(['company_id','hall_id','item_id'],'ext_in_count_item_fk')
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_items')->restrictOnDelete();
            $t->unique(['receipt_id','item_id'],'ext_in_count_distinct_item');
        });
        DB::statement('ALTER TABLE hall_inventory_external_in_receipt_lines
            ADD CONSTRAINT ext_in_count_quantity_check CHECK(counted_quantity<=100000000)');
        Schema::create('hall_inventory_external_in_acceptances',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('loan_id');
            $t->uuid('hall_id');$t->uuid('receipt_id');
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','loan_id'],'ext_in_one_acceptance');
            $t->unique(['company_id','hall_id','id'],'ext_in_accept_scope_key');
            $t->foreign(['company_id','loan_id','hall_id','receipt_id'],
                'ext_in_accept_receipt_fk')
                ->references(['company_id','loan_id','hall_id','id'])
                ->on('hall_inventory_external_in_receipts')->restrictOnDelete();
        });
        Schema::create('hall_inventory_external_in_acceptance_lines',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->uuid('company_id');
            $t->uuid('acceptance_id');$t->uuid('hall_id');$t->uuid('item_id');
            $t->uuid('movement_id');$t->unsignedInteger('quantity');
            $t->foreign(['company_id','hall_id','acceptance_id'],
                'ext_in_lines_parent_fk')->references(['company_id','hall_id','id'])
                ->on('hall_inventory_external_in_acceptances')->restrictOnDelete();
            $t->foreign(['company_id','hall_id','item_id','movement_id'],
                'ext_in_lines_movement_fk')->references(['company_id','hall_id','item_id','id'])
                ->on('hall_inventory_movement_events')->restrictOnDelete();
            $t->unique(['acceptance_id','item_id'],'ext_in_line_unique_item');
            $t->unique('movement_id','ext_in_line_unique_movement');
        });
        DB::statement('ALTER TABLE hall_inventory_external_in_acceptance_lines
            ADD CONSTRAINT ext_in_line_quantity_check CHECK(quantity BETWEEN 1 AND 100000000)');
        foreach(['hall_inventory_external_in_receipts','hall_inventory_external_in_receipt_lines',
            'hall_inventory_external_in_acceptances',
            'hall_inventory_external_in_acceptance_lines'] as $table)
            DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE
                ON {$table} FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION venuedesk_guard_external_in_count() RETURNS trigger LANGUAGE plpgsql AS $
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM hall_inventory_external_in_receipts r
        JOIN hall_inventory_loan_drafts d
            ON d.company_id=r.company_id AND d.id=r.loan_id
        JOIN hall_inventory_loan_draft_lines l
            ON l.company_id=d.company_id AND l.loan_id=d.id
            AND l.item_id=NEW.item_id
            AND l.catalog_hall_id=NEW.hall_id
        WHERE r.id=NEW.receipt_id
            AND r.company_id=NEW.company_id
            AND r.loan_id=NEW.loan_id
            AND r.hall_id=NEW.hall_id
            AND d.direction='external_in'
            AND d.borrowing_hall_id=NEW.hall_id
    ) THEN
        RAISE EXCEPTION 'External borrowed physical count must match its own loan and hall item';
    END IF;
    RETURN NEW;
END $;
SQL);
        DB::statement('CREATE TRIGGER guard_external_in_count
            BEFORE INSERT ON hall_inventory_external_in_receipt_lines
            FOR EACH ROW EXECUTE FUNCTION venuedesk_guard_external_in_count()');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION venuedesk_external_in_consistent() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS(
        SELECT 1 FROM hall_inventory_external_in_acceptances a
        JOIN hall_inventory_loan_drafts d ON d.company_id=a.company_id AND d.id=a.loan_id
        JOIN hall_inventory_loan_draft_lines l ON l.company_id=a.company_id
            AND l.loan_id=d.id AND l.item_id=NEW.item_id
            AND l.catalog_hall_id=NEW.hall_id
        JOIN hall_inventory_movement_events m ON m.id=NEW.movement_id
            AND m.company_id=NEW.company_id AND m.hall_id=NEW.hall_id
            AND m.item_id=NEW.item_id
            AND m.quantity=NEW.quantity AND m.event_kind='accept_borrowed'
        WHERE a.id=NEW.acceptance_id AND a.company_id=NEW.company_id
            AND a.hall_id=NEW.hall_id AND d.direction='external_in'
            AND d.borrowing_hall_id=NEW.hall_id
            AND l.requested_quantity=NEW.quantity
    ) THEN RAISE EXCEPTION 'External borrowed receipt line must be a matching draft and borrowed movement'; END IF;
    RETURN NEW;
END $$;
SQL);
        DB::statement('CREATE TRIGGER guard_external_in_acceptance_line
            BEFORE INSERT ON hall_inventory_external_in_acceptance_lines
            FOR EACH ROW EXECUTE FUNCTION venuedesk_external_in_consistent()');
    }
    public function down():void {
        if(DB::table('hall_inventory_external_in_receipts')->exists())
            throw new RuntimeException('Immutable external borrowed custody requires archival before rollback.');
        Schema::dropIfExists('hall_inventory_external_in_acceptance_lines');
        Schema::dropIfExists('hall_inventory_external_in_acceptances');
        Schema::dropIfExists('hall_inventory_external_in_receipt_lines');
        Schema::dropIfExists('hall_inventory_external_in_receipts');
        DB::statement('DROP FUNCTION IF EXISTS venuedesk_external_in_consistent()');
        DB::statement('DROP FUNCTION IF EXISTS venuedesk_guard_external_in_count()');
    }
};

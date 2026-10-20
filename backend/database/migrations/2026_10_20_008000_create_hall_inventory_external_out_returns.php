<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::table('hall_inventory_external_out_dispatches',function(Blueprint $t):void {
            $t->unique(['company_id','id','loan_id','hall_id'],'ext_out_return_exact_dispatch_key');
        });
        Schema::table('hall_inventory_external_out_dispatch_lines',function(Blueprint $t):void {
            $t->unique(['company_id','dispatch_id','hall_id','item_id'],'ext_out_return_original_item_key');
        });
        Schema::create('hall_inventory_external_out_return_receipts',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('loan_id');
            $t->uuid('hall_id');$t->uuid('dispatch_id');
            $t->char('evidence_sha256',64);$t->unsignedInteger('evidence_size');
            $t->string('evidence_storage_path',500);
            $t->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','id','loan_id','hall_id','dispatch_id'],'ext_out_return_receipt_scope_key');
            $t->foreign(['company_id','dispatch_id','loan_id','hall_id'],'ext_out_return_origin_fk')
                ->references(['company_id','id','loan_id','hall_id'])
                ->on('hall_inventory_external_out_dispatches')->restrictOnDelete();
            $t->index(['company_id','loan_id','created_at']);
        });
        Schema::create('hall_inventory_external_out_return_lines',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('receipt_id');
            $t->uuid('loan_id');$t->uuid('hall_id');$t->uuid('dispatch_id');
            $t->uuid('item_id');$t->unsignedInteger('quantity');
            $t->unique(['company_id','receipt_id','hall_id','item_id'],'ext_out_return_line_receipt_item_key');
            $t->foreign(['company_id','receipt_id','loan_id','hall_id','dispatch_id'],'ext_out_return_line_receipt_fk')
                ->references(['company_id','id','loan_id','hall_id','dispatch_id'])
                ->on('hall_inventory_external_out_return_receipts')->restrictOnDelete();
            $t->foreign(['company_id','dispatch_id','hall_id','item_id'],'ext_out_return_line_dispatch_fk')
                ->references(['company_id','dispatch_id','hall_id','item_id'])
                ->on('hall_inventory_external_out_dispatch_lines')->restrictOnDelete();
        });
        Schema::create('hall_inventory_external_out_return_reviews',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('receipt_id');
            $t->uuid('loan_id');$t->uuid('hall_id');$t->uuid('dispatch_id');
            $t->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','receipt_id'],'ext_out_return_one_review_key');
            $t->unique(['company_id','id','receipt_id','hall_id'],'ext_out_return_review_scope_key');
            $t->foreign(['company_id','receipt_id','loan_id','hall_id','dispatch_id'],'ext_out_return_review_receipt_fk')
                ->references(['company_id','id','loan_id','hall_id','dispatch_id'])
                ->on('hall_inventory_external_out_return_receipts')->restrictOnDelete();
        });
        Schema::create('hall_inventory_external_out_return_review_lines',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->uuid('company_id');$t->uuid('review_id');
            $t->uuid('receipt_id');$t->uuid('hall_id');$t->uuid('item_id');
            $t->unsignedInteger('good_quantity');$t->unsignedInteger('damaged_quantity');
            $t->unsignedInteger('unresolved_quantity');
            $t->uuid('good_movement_id')->nullable();$t->uuid('damaged_movement_id')->nullable();
            $t->unique(['review_id','item_id'],'ext_out_return_review_item_key');
            $t->foreign(['company_id','review_id','receipt_id','hall_id'],'ext_out_return_review_line_parent_fk')
                ->references(['company_id','id','receipt_id','hall_id'])
                ->on('hall_inventory_external_out_return_reviews')->restrictOnDelete();
            $t->foreign(['company_id','receipt_id','hall_id','item_id'],'ext_out_return_review_line_received_fk')
                ->references(['company_id','receipt_id','hall_id','item_id'])
                ->on('hall_inventory_external_out_return_lines')->restrictOnDelete();
            $t->foreign(['company_id','hall_id','item_id','good_movement_id'],'ext_out_return_review_good_movement_fk')
                ->references(['company_id','hall_id','item_id','id'])
                ->on('hall_inventory_movement_events')->restrictOnDelete();
            $t->foreign(['company_id','hall_id','item_id','damaged_movement_id'],'ext_out_return_review_bad_movement_fk')
                ->references(['company_id','hall_id','item_id','id'])
                ->on('hall_inventory_movement_events')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE hall_inventory_external_out_return_lines ADD CONSTRAINT ext_out_return_qty_valid CHECK(quantity>0 AND quantity<=100000000)');
        DB::statement('ALTER TABLE hall_inventory_external_out_return_review_lines ADD CONSTRAINT ext_out_return_review_qty_valid CHECK(good_quantity+damaged_quantity+unresolved_quantity>0 AND good_quantity+damaged_quantity+unresolved_quantity<=100000000 AND ((good_quantity=0 AND good_movement_id IS NULL) OR (good_quantity>0 AND good_movement_id IS NOT NULL)) AND ((damaged_quantity=0 AND damaged_movement_id IS NULL) OR (damaged_quantity>0 AND damaged_movement_id IS NOT NULL)))');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION venuedesk_guard_external_out_return_review_line() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE qty bigint;
BEGIN
    SELECT l.quantity INTO qty FROM hall_inventory_external_out_return_lines l
    WHERE l.company_id=NEW.company_id AND l.receipt_id=NEW.receipt_id
      AND l.hall_id=NEW.hall_id AND l.item_id=NEW.item_id;
    IF qty IS NULL OR qty<>NEW.good_quantity+NEW.damaged_quantity+NEW.unresolved_quantity THEN
        RAISE EXCEPTION 'Outside return physical counts must balance independent condition decision';
    END IF;
    IF NEW.good_movement_id IS NOT NULL AND NOT EXISTS(
        SELECT 1 FROM hall_inventory_movement_events m
        JOIN hall_inventory_external_out_return_reviews r ON r.company_id=NEW.company_id AND r.id=NEW.review_id
        WHERE m.id=NEW.good_movement_id AND m.company_id=NEW.company_id
          AND m.hall_id=NEW.hall_id AND m.item_id=NEW.item_id AND m.quantity=NEW.good_quantity
          AND m.event_kind='return_owned_good' AND m.actor_id=r.reviewed_by
    ) THEN
        RAISE EXCEPTION 'Outside good return movement provenance mismatch';
    END IF;
    IF NEW.damaged_movement_id IS NOT NULL AND NOT EXISTS(
        SELECT 1 FROM hall_inventory_movement_events m
        JOIN hall_inventory_external_out_return_reviews r ON r.company_id=NEW.company_id AND r.id=NEW.review_id
        WHERE m.id=NEW.damaged_movement_id AND m.company_id=NEW.company_id
          AND m.hall_id=NEW.hall_id AND m.item_id=NEW.item_id AND m.quantity=NEW.damaged_quantity
          AND m.event_kind='return_owned_damaged' AND m.actor_id=r.reviewed_by
    ) THEN
        RAISE EXCEPTION 'Outside damaged return movement provenance mismatch';
    END IF;
    RETURN NEW;
END
$$;
SQL);
        DB::statement('CREATE TRIGGER ext_out_return_review_exact_movement BEFORE INSERT ON hall_inventory_external_out_return_review_lines FOR EACH ROW EXECUTE FUNCTION venuedesk_guard_external_out_return_review_line()');
        foreach(['hall_inventory_external_out_return_receipts','hall_inventory_external_out_return_lines',
            'hall_inventory_external_out_return_reviews','hall_inventory_external_out_return_review_lines'] as $name)
            DB::statement("CREATE TRIGGER immutable_{$name} BEFORE UPDATE OR DELETE ON {$name} FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()");
    }
    public function down():void {
        if(DB::table('hall_inventory_external_out_return_receipts')->exists()||
           DB::table('hall_inventory_external_out_return_reviews')->exists())
            throw new RuntimeException('Outside physical returns must be archived before migration rollback.');
        Schema::dropIfExists('hall_inventory_external_out_return_review_lines');
        Schema::dropIfExists('hall_inventory_external_out_return_reviews');
        Schema::dropIfExists('hall_inventory_external_out_return_lines');
        Schema::dropIfExists('hall_inventory_external_out_return_receipts');
        Schema::table('hall_inventory_external_out_dispatch_lines',fn(Blueprint $t)=>
            $t->dropUnique('ext_out_return_original_item_key'));
        Schema::table('hall_inventory_external_out_dispatches',fn(Blueprint $t)=>
            $t->dropUnique('ext_out_return_exact_dispatch_key'));
        DB::statement('DROP FUNCTION IF EXISTS venuedesk_guard_external_out_return_review_line()');
    }
};
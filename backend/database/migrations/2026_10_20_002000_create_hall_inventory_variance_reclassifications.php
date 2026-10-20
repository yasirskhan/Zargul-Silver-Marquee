<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        DB::statement('ALTER TABLE hall_inventory_movement_events DROP CONSTRAINT hall_inventory_movement_kind_check');
        DB::statement("ALTER TABLE hall_inventory_movement_events ADD CONSTRAINT hall_inventory_movement_kind_check CHECK (
            event_kind IN ('opening_owned','acquire_owned','dispatch_owned_loan','return_owned_good',
                'return_owned_damaged','accept_borrowed','send_borrowed_back',
                'confirm_borrowed_return','damage_borrowed',
                'reclassify_owned_damage','restore_owned_usable'))");
        Schema::create('hall_inventory_variance_reclassifications',function(Blueprint $t):void {
            $t->uuid('id')->primary(); $t->uuid('company_id'); $t->uuid('hall_id');
            $t->uuid('stocktake_id'); $t->uuid('stocktake_line_id')->unique();
            $t->uuid('acknowledgment_id'); $t->uuid('position_id');
            $t->uuid('movement_id')->unique(); $t->string('event_kind',32);
            $t->unsignedInteger('quantity'); $t->unsignedBigInteger('book_revision');
            $t->char('request_fingerprint',64); $t->string('reason',500);
            $t->foreignId('adjudicated_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign(['company_id','hall_id','stocktake_id'])
                ->references(['company_id','hall_id','id'])->on('hall_inventory_stocktakes')->restrictOnDelete();
            $t->foreign('stocktake_line_id')->references('id')->on('hall_inventory_stocktake_lines')->restrictOnDelete();
            $t->foreign('acknowledgment_id')->references('id')->on('hall_inventory_variance_review_acknowledgments')->restrictOnDelete();
            $t->foreign('position_id')->references('id')->on('hall_inventory_positions')->restrictOnDelete();
            $t->foreign('movement_id')->references('id')->on('hall_inventory_movement_events')->restrictOnDelete();
            $t->index(['company_id','hall_id','stocktake_id']);
        });
        DB::statement("ALTER TABLE hall_inventory_variance_reclassifications ADD CONSTRAINT variance_reclass_kind_check
            CHECK (event_kind IN ('reclassify_owned_damage','restore_owned_usable') AND quantity BETWEEN 1 AND 100000000)");
        DB::statement('CREATE TRIGGER immutable_hall_variance_reclassifications
            BEFORE UPDATE OR DELETE ON hall_inventory_variance_reclassifications
            FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down():void {
        if(DB::table('hall_inventory_variance_reclassifications')->exists())
            throw new RuntimeException('Immutable condition adjudication must be archived before rollback.');
        Schema::dropIfExists('hall_inventory_variance_reclassifications');
        DB::statement('ALTER TABLE hall_inventory_movement_events DROP CONSTRAINT hall_inventory_movement_kind_check');
        DB::statement("ALTER TABLE hall_inventory_movement_events ADD CONSTRAINT hall_inventory_movement_kind_check CHECK (
            event_kind IN ('opening_owned','acquire_owned','dispatch_owned_loan','return_owned_good',
                'return_owned_damaged','accept_borrowed','send_borrowed_back',
                'confirm_borrowed_return','damage_borrowed'))");
    }
};

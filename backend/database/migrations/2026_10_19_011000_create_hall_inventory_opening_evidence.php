<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        // Two separate immutable stages: a current hall employee's opening
        // physical-count claim, then an independent owner/admin receipt.
        // Recording a REQUEST never changes a stock position.
        Schema::create('hall_inventory_opening_requests',function(Blueprint $table):void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hall_id');
            $table->uuid('item_id');
            $table->uuid('stocktake_id');
            $table->unsignedInteger('quantity');
            $table->char('request_fingerprint',64);
            $table->string('reason',500);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id','hall_id','item_id'])
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_items')->restrictOnDelete();
            $table->foreign(['company_id','hall_id','stocktake_id'])
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_stocktakes')->restrictOnDelete();
            $table->unique(['company_id','hall_id','item_id']);
        });
        DB::statement('ALTER TABLE hall_inventory_opening_requests
            ADD CONSTRAINT hall_inventory_opening_qty_check
            CHECK (quantity>0 AND quantity<=100000000)');
        DB::statement('CREATE TRIGGER immutable_hall_inventory_opening_requests
            BEFORE UPDATE OR DELETE ON hall_inventory_opening_requests
            FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');

        Schema::create('hall_inventory_opening_acceptances',function(Blueprint $table):void {
            $table->uuid('id')->primary();
            $table->uuid('opening_request_id')->unique();
            $table->uuid('movement_id')->unique();
            $table->foreignId('accepted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('accepted_at')->useCurrent();
            $table->foreign('opening_request_id')
                ->references('id')->on('hall_inventory_opening_requests')->restrictOnDelete();
            $table->foreign('movement_id')
                ->references('id')->on('hall_inventory_movement_events')->restrictOnDelete();
        });
        DB::statement('CREATE TRIGGER immutable_hall_inventory_opening_acceptances
            BEFORE UPDATE OR DELETE ON hall_inventory_opening_acceptances
            FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down():void
    {
        if(DB::table('hall_inventory_opening_requests')->exists()) {
            throw new RuntimeException('Opening custody evidence needs authorized archival.');
        }
        Schema::dropIfExists('hall_inventory_opening_acceptances');
        Schema::dropIfExists('hall_inventory_opening_requests');
    }
};

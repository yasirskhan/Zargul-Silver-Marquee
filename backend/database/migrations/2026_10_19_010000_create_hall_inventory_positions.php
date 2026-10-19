<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Schema only. A catalog item begins with NO stock, not even a row.
        // Position changes require a future independently gated, immutable
        // evidence-backed movement writer with deterministic row locking.
        Schema::create('hall_inventory_positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hall_id');
            $table->uuid('item_id');
            $table->unsignedBigInteger('owned_usable')->default(0);
            $table->unsignedBigInteger('owned_on_loan')->default(0);
            $table->unsignedBigInteger('owned_damaged')->default(0);
            $table->unsignedBigInteger('borrowed_usable')->default(0);
            $table->unsignedBigInteger('borrowed_transit')->default(0);
            $table->unsignedBigInteger('borrowed_damaged')->default(0);
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestampsTz();
            $table->unique(['company_id','hall_id','item_id']);
            $table->unique(['company_id','hall_id','id']);
            $table->foreign(['company_id','hall_id','item_id'])
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_items')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE hall_inventory_positions
            ADD CONSTRAINT hall_inventory_positions_counts_check CHECK (
                owned_usable<=1000000000 AND owned_on_loan<=1000000000
                AND owned_damaged<=1000000000
                AND borrowed_usable<=1000000000
                AND borrowed_transit<=1000000000
                AND borrowed_damaged<=1000000000
                AND revision<=9223372036854775806
            )');

        Schema::create('hall_inventory_movement_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hall_id');
            $table->uuid('item_id');
            $table->uuid('position_id');
            $table->string('event_kind',32);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('revision_before');
            $table->unsignedBigInteger('revision_after');
            $table->char('request_fingerprint',64);
            $table->string('reason',500);
            $table->jsonb('before_values');
            $table->jsonb('after_values');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id','hall_id','position_id'])
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_positions')->restrictOnDelete();
            $table->foreign(['company_id','hall_id','item_id'])
                ->references(['company_id','hall_id','id'])
                ->on('hall_inventory_items')->restrictOnDelete();
            $table->unique(['position_id','revision_after']);
            $table->index(['company_id','hall_id','created_at']);
            $table->index(['company_id','hall_id','item_id','created_at']);
        });
        DB::statement("ALTER TABLE hall_inventory_movement_events
            ADD CONSTRAINT hall_inventory_movement_kind_check CHECK (
                event_kind IN (
                    'opening_owned','acquire_owned',
                    'dispatch_owned_loan','return_owned_good',
                    'return_owned_damaged','accept_borrowed',
                    'send_borrowed_back','confirm_borrowed_return',
                    'damage_borrowed'
                )
            )");
        DB::statement('ALTER TABLE hall_inventory_movement_events
            ADD CONSTRAINT hall_inventory_movement_qty_check CHECK (
                quantity>0 AND quantity<=100000000
                AND revision_after=revision_before+1
            )');
        DB::statement('CREATE TRIGGER immutable_hall_inventory_movement_events
            BEFORE UPDATE OR DELETE ON hall_inventory_movement_events
            FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        if (DB::table('hall_inventory_positions')->exists()) {
            throw new RuntimeException(
                'Recorded hall positions require authorized archival before rollback.'
            );
        }
        Schema::dropIfExists('hall_inventory_movement_events');
        Schema::dropIfExists('hall_inventory_positions');
    }
};

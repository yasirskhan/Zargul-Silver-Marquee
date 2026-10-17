<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A catalog item belongs to exactly ONE physical hall. Zero stock is
        // implied until separate immutable stock-receipt movements are posted.
        // No company-wide inventory bucket or automatic booking/finance impact.
        Schema::create('hall_inventory_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hall_id');
            $table->string('name', 160);
            $table->string('normalized_key', 180);
            $table->string('category', 80);
            $table->string('unit', 16);
            $table->unsignedInteger('minimum_quantity')->default(0);
            $table->string('creation_reason', 500);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->foreign(['company_id', 'hall_id'])
                ->references(['company_id', 'id'])->on('halls')->restrictOnDelete();
            $table->unique(['company_id', 'hall_id', 'id']);
            $table->unique(['hall_id', 'normalized_key']);
            $table->index(['company_id', 'hall_id', 'category', 'is_active']);
        });

        DB::statement("ALTER TABLE hall_inventory_items ADD CONSTRAINT hall_inventory_unit_valid CHECK (unit IN ('piece','set','pair','kg','liter'))");
        DB::statement("ALTER TABLE hall_inventory_items ADD CONSTRAINT hall_inventory_minimum_valid CHECK (minimum_quantity <= 100000000)");
        DB::statement("ALTER TABLE hall_inventory_items ADD CONSTRAINT hall_inventory_name_nonempty CHECK (length(btrim(name)) > 0 AND length(btrim(normalized_key)) > 0)");
        // No direct mutation or removal of a catalog row in this foundation.
        // Later revisions/retirement require a separately audited service.
        DB::statement('CREATE TRIGGER immutable_hall_inventory_items BEFORE UPDATE OR DELETE ON hall_inventory_items FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');

        Schema::create('hall_inventory_item_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hall_id');
            $table->uuid('item_id');
            $table->string('action', 24);
            $table->jsonb('after_values');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'hall_id', 'item_id'])
                ->references(['company_id', 'hall_id', 'id'])->on('hall_inventory_items')->restrictOnDelete();
            $table->unique(['item_id', 'action']);
            $table->index(['company_id', 'hall_id', 'created_at']);
        });
        DB::statement("ALTER TABLE hall_inventory_item_events ADD CONSTRAINT hall_inventory_event_action CHECK (action = 'created')");
        DB::statement('CREATE TRIGGER immutable_hall_inventory_item_events BEFORE UPDATE OR DELETE ON hall_inventory_item_events FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        // Developer/test rollback only; do not remove recorded real inventory
        // history from a customer production database.
        if (DB::table('hall_inventory_items')->exists()) {
            throw new RuntimeException('Inventory items require approved archival before rollback.');
        }
        Schema::dropIfExists('hall_inventory_item_events');
        Schema::dropIfExists('hall_inventory_items');
    }
};

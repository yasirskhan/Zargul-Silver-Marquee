<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Physical counts are EVIDENCE ONLY, not a purchase/stock adjustment.
        // Reconciliation, variance approval and ownership movements are separate.
        Schema::create('hall_inventory_stocktakes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hall_id');
            $table->string('audit_kind', 24);
            $table->char('request_fingerprint', 64);
            $table->string('reason', 500);
            $table->foreignId('counted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('counted_at')->useCurrent();
            $table->foreign(['company_id','hall_id'])
                ->references(['company_id','id'])->on('halls')->restrictOnDelete();
            $table->unique(['company_id', 'hall_id', 'id']);
            $table->index(['company_id', 'hall_id', 'counted_at']);
        });
        DB::statement("ALTER TABLE hall_inventory_stocktakes ADD CONSTRAINT stocktake_kind_valid CHECK (audit_kind IN ('opening','post_event','monthly','quarterly','ad_hoc'))");

        Schema::create('hall_inventory_stocktake_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hall_id');
            $table->uuid('stocktake_id');
            $table->uuid('item_id');
            $table->string('ownership', 12);
            $table->unsignedInteger('usable_count');
            $table->unsignedInteger('damaged_count')->default(0);
            $table->string('note', 500)->default('');
            $table->foreign(['company_id', 'hall_id', 'stocktake_id'])
                ->references(['company_id', 'hall_id', 'id'])->on('hall_inventory_stocktakes')->restrictOnDelete();
            $table->foreign(['company_id', 'hall_id', 'item_id'])
                ->references(['company_id', 'hall_id', 'id'])->on('hall_inventory_items')->restrictOnDelete();
            $table->unique(['stocktake_id', 'item_id', 'ownership']);
            $table->index(['company_id', 'hall_id', 'item_id']);
        });
        DB::statement("ALTER TABLE hall_inventory_stocktake_lines ADD CONSTRAINT stocktake_ownership_valid CHECK (ownership IN ('owned','borrowed'))");
        DB::statement("ALTER TABLE hall_inventory_stocktake_lines ADD CONSTRAINT stocktake_counts_bounded CHECK (usable_count <= 100000000 AND damaged_count <= 100000000)");

        DB::statement('CREATE TRIGGER immutable_hall_inventory_stocktakes BEFORE UPDATE OR DELETE ON hall_inventory_stocktakes FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
        DB::statement('CREATE TRIGGER immutable_hall_inventory_stocktake_lines BEFORE UPDATE OR DELETE ON hall_inventory_stocktake_lines FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        if (DB::table('hall_inventory_stocktakes')->exists()) {
            throw new RuntimeException('Inventory physical count evidence requires approved archival before rollback.');
        }
        Schema::dropIfExists('hall_inventory_stocktake_lines');
        Schema::dropIfExists('hall_inventory_stocktakes');
    }
};

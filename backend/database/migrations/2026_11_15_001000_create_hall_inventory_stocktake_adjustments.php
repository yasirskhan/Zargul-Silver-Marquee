<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('hall_inventory_stocktake_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('hall_id')->constrained('halls')->restrictOnDelete();
            $table->foreignUuid('stocktake_id')->constrained('hall_inventory_stocktakes')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('hall_inventory_items')->restrictOnDelete();
            $table->string('ownership', 12)->default('owned');
            $table->string('status', 24)->default('proposed');
            $table->unsignedBigInteger('expected_revision');
            $table->integer('usable_before');
            $table->integer('damaged_before');
            $table->integer('usable_after');
            $table->integer('damaged_after');
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('proposed_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('movement_id')->nullable()->constrained('hall_inventory_movement_events')->restrictOnDelete();
            $table->string('reason', 500);
            $table->string('approval_reason', 500)->nullable();
            $table->timestampsTz();
            $table->unique(['stocktake_id', 'item_id', 'ownership']);
            $table->index(['company_id', 'hall_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('hall_inventory_stocktake_adjustments'); }
};

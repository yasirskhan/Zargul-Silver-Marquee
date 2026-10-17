<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('venue_event_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('reservation_id');
            $table->string('status', 20)->default('in_progress');
            $table->string('started_clock', 5);
            $table->string('ended_clock', 5)->nullable();
            $table->jsonb('overtime_snapshot')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->foreign(['company_id', 'reservation_id'])
                ->references(['company_id', 'id'])->on('venue_reservations')->restrictOnDelete();
            $table->unique(['company_id', 'reservation_id']);
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'status', 'updated_at']);
        });
        DB::statement("ALTER TABLE venue_event_operations ADD CONSTRAINT venue_event_operation_status CHECK (status IN ('in_progress','completed'))");
        DB::statement("ALTER TABLE venue_event_operations ADD CONSTRAINT venue_event_operation_revision CHECK (revision > 0)");
        DB::statement("ALTER TABLE venue_event_operations ADD CONSTRAINT venue_event_operation_clocks CHECK ((status = 'in_progress' AND ended_clock IS NULL AND overtime_snapshot IS NULL AND completed_by IS NULL) OR (status = 'completed' AND ended_clock IS NOT NULL AND overtime_snapshot IS NOT NULL AND completed_by IS NOT NULL))");

        Schema::create('venue_event_operation_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('operation_id');
            $table->unsignedInteger('revision');
            $table->string('action', 24);
            $table->jsonb('before_values')->nullable();
            $table->jsonb('after_values');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'operation_id'])
                ->references(['company_id', 'id'])->on('venue_event_operations')->restrictOnDelete();
            $table->unique(['operation_id', 'revision']);
        });
        DB::statement("ALTER TABLE venue_event_operation_events ADD CONSTRAINT venue_event_operation_action CHECK (action IN ('started','completed'))");
        DB::statement('CREATE TRIGGER immutable_venue_event_operation_events BEFORE UPDATE OR DELETE ON venue_event_operation_events FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        if (DB::table('venue_event_operations')->exists()) {
            throw new RuntimeException('Recorded event operations require approved archival before rollback.');
        }
        Schema::dropIfExists('venue_event_operation_events');
        Schema::dropIfExists('venue_event_operations');
    }
};

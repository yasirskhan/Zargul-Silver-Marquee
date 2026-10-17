<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Actual fulfilment is distinct from an approved planning checklist.
        // Each reviewed plan task may be evidenced only once for its event.
        Schema::create('venue_event_task_fulfillments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('reservation_id');
            $table->uuid('operation_id');
            $table->uuid('planning_task_id');
            $table->unsignedInteger('planning_revision');
            $table->string('title_snapshot', 120);
            $table->string('reason', 500);
            $table->foreignId('completed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'reservation_id'])->references(['company_id', 'id'])
                ->on('venue_reservations')->restrictOnDelete();
            $table->foreign(['company_id', 'operation_id'])->references(['company_id', 'id'])
                ->on('venue_event_operations')->restrictOnDelete();
            $table->foreign(['company_id', 'planning_task_id'])->references(['company_id', 'id'])
                ->on('operations_plan_tasks')->restrictOnDelete();
            $table->unique(['company_id', 'reservation_id', 'planning_task_id'], 'event_fulfillment_once');
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'reservation_id', 'created_at']);
        });
        DB::statement("ALTER TABLE venue_event_task_fulfillments ADD CONSTRAINT event_fulfillment_revision CHECK (planning_revision > 0)");
        DB::statement('CREATE TRIGGER immutable_venue_event_task_fulfillments BEFORE UPDATE OR DELETE ON venue_event_task_fulfillments FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        if (DB::table('venue_event_task_fulfillments')->exists()) {
            throw new RuntimeException('Recorded actual service fulfilment requires approved archival before rollback.');
        }
        Schema::dropIfExists('venue_event_task_fulfillments');
    }
};

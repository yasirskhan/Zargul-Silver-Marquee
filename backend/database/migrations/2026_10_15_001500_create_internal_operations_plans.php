<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Enforce that a task's hall and proposal refer to the very same hall
        // within the same company, not merely two independently valid FKs.
        Schema::table('booking_drafts', function (Blueprint $table): void {
            $table->unique(['company_id', 'id', 'hall_id'],
                'booking_drafts_company_id_hall_plan_unique');
        });
        Schema::create('operations_plan_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('booking_id');
            $table->uuid('hall_id');
            $table->string('title', 120);
            $table->string('status', 16)->default('planned');
            $table->unsignedInteger('revision')->default(1);
            $table->string('creation_reason', 500);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'booking_id', 'hall_id'])
                ->references(['company_id', 'id', 'hall_id'])
                ->on('booking_drafts')->restrictOnDelete();
            $table->index(['company_id', 'hall_id', 'booking_id', 'created_at'],
                'ops_plan_company_hall_booking_idx');
        });
        DB::statement("ALTER TABLE operations_plan_tasks ADD CONSTRAINT ops_plan_status CHECK (status IN ('planned','reviewed'))");
        DB::statement("ALTER TABLE operations_plan_tasks ADD CONSTRAINT ops_plan_revision CHECK (revision > 0)");
        DB::statement("ALTER TABLE operations_plan_tasks ADD CONSTRAINT ops_plan_title CHECK (char_length(title) BETWEEN 3 AND 120 AND title !~ '[<>@]')");

        Schema::create('operations_plan_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('task_id');
            $table->unsignedInteger('revision');
            $table->string('action', 16);
            $table->string('status', 16);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['task_id', 'revision']);
            $table->foreign(['company_id', 'task_id'])
                ->references(['company_id', 'id'])
                ->on('operations_plan_tasks')->restrictOnDelete();
            $table->index(['company_id', 'created_at']);
        });
        DB::statement("ALTER TABLE operations_plan_events ADD CONSTRAINT ops_plan_event_action CHECK (action IN ('created','reviewed','reopened'))");
        DB::statement("ALTER TABLE operations_plan_events ADD CONSTRAINT ops_plan_event_status CHECK (status IN ('planned','reviewed'))");
        DB::statement('ALTER TABLE operations_plan_events ADD CONSTRAINT ops_plan_event_revision CHECK (revision > 0)');
        DB::statement('CREATE TRIGGER immutable_operations_plan_events BEFORE UPDATE OR DELETE ON operations_plan_events FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_plan_events');
        Schema::dropIfExists('operations_plan_tasks');
        Schema::table('booking_drafts', function (Blueprint $table): void {
            $table->dropUnique('booking_drafts_company_id_hall_plan_unique');
        });
    }
};

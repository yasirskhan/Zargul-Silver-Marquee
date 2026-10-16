<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Internal proposal records only. This is NOT a confirmed reservation,
        // not a public intake endpoint and never owns a physical slot claim.
        Schema::create('booking_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hall_id');
            $table->uuid('customer_id');
            $table->date('event_date');
            $table->string('program', 16);
            $table->unsignedInteger('requested_guests');
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->foreign(['company_id', 'hall_id'])
                ->references(['company_id', 'id'])->on('halls')->restrictOnDelete();
            $table->foreign(['company_id', 'customer_id'])
                ->references(['company_id', 'id'])->on('customer_profiles')->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'hall_id', 'event_date', 'program']);
            $table->index(['company_id', 'customer_id', 'created_at']);
        });

        DB::statement("ALTER TABLE booking_drafts ADD CONSTRAINT booking_draft_program_allowed CHECK (program IN ('morning', 'noon', 'evening'))");
        DB::statement("ALTER TABLE booking_drafts ADD CONSTRAINT booking_draft_only_preconfirmation CHECK (status IN ('draft', 'under_review', 'withdrawn'))");
        DB::statement('ALTER TABLE booking_drafts ADD CONSTRAINT booking_draft_positive_guests CHECK (requested_guests > 0)');
        DB::statement('ALTER TABLE booking_drafts ADD CONSTRAINT booking_draft_positive_revision CHECK (revision > 0)');

        // Permanently retain redacted proposal transitions, not decrypted
        // customer contact, uncontrolled price quotes or a confirmed claim.
        Schema::create('booking_draft_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('booking_id');
            $table->unsignedInteger('revision');
            $table->string('action', 24);
            $table->jsonb('before_values')->nullable();
            $table->jsonb('after_values');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['company_id', 'booking_id'])
                ->references(['company_id', 'id'])->on('booking_drafts')->restrictOnDelete();
            $table->unique(['booking_id', 'revision']);
            $table->index(['company_id', 'created_at']);
        });
        DB::statement("ALTER TABLE booking_draft_events ADD CONSTRAINT booking_draft_action_allowed CHECK (action IN ('created', 'updated', 'submitted', 'withdrawn'))");
        DB::statement('ALTER TABLE booking_draft_events ADD CONSTRAINT booking_event_revision_positive CHECK (revision > 0)');
        DB::statement('CREATE TRIGGER immutable_booking_draft_events BEFORE UPDATE OR DELETE ON booking_draft_events FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_draft_events');
        Schema::dropIfExists('booking_drafts');
    }
};

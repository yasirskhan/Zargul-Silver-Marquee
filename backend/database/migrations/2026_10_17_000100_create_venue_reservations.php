<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The draft remains a full immutable historical source, but is no
        // longer counted as a pending proposal once a physical slot is booked.
        // Keep the old explicit ban on using "confirmed" as a draft status.
        DB::statement('ALTER TABLE booking_drafts DROP CONSTRAINT booking_draft_only_preconfirmation');
        DB::statement("ALTER TABLE booking_drafts ADD CONSTRAINT booking_draft_only_preconfirmation CHECK (status IN ('draft', 'under_review', 'withdrawn', 'booked'))");
        DB::statement('ALTER TABLE booking_draft_events DROP CONSTRAINT booking_draft_action_allowed');
        DB::statement("ALTER TABLE booking_draft_events ADD CONSTRAINT booking_draft_action_allowed CHECK (action IN ('created', 'updated', 'submitted', 'withdrawn', 'booked'))");

        // Confirmed internal reservations are separate from unconfirmed drafts.
        // The exclusive claim is the authority for a physical hall/date/program.
        Schema::create('venue_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('source_draft_id');
            $table->uuid('customer_id');
            $table->uuid('hall_id');
            $table->uuid('claim_id');
            $table->date('event_date');
            $table->string('program', 16);
            $table->unsignedInteger('guests');
            $table->string('status', 16)->default('confirmed');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->foreign(['company_id', 'source_draft_id'])
                ->references(['company_id', 'id'])->on('booking_drafts')->restrictOnDelete();
            $table->foreign(['company_id', 'hall_id'])
                ->references(['company_id', 'id'])->on('halls')->restrictOnDelete();
            $table->foreign(['company_id', 'customer_id'])
                ->references(['company_id', 'id'])->on('customer_profiles')->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'source_draft_id']);
            $table->unique('claim_id');
            $table->index(['company_id', 'hall_id', 'event_date']);
            $table->index(['company_id', 'customer_id', 'created_at']);
        });
        DB::statement("ALTER TABLE venue_reservations ADD CONSTRAINT reservation_state_allowed CHECK (status IN ('confirmed', 'cancelled'))");
        DB::statement("ALTER TABLE venue_reservations ADD CONSTRAINT reservation_program_allowed CHECK (program IN ('morning', 'noon', 'evening'))");
        DB::statement('ALTER TABLE venue_reservations ADD CONSTRAINT reservation_guests_positive CHECK (guests > 0 AND revision > 0)');

        Schema::create('venue_reservation_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('reservation_id');
            $table->unsignedInteger('revision');
            $table->string('action', 20);
            $table->jsonb('before_values')->nullable();
            $table->jsonb('after_values');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'reservation_id'])
                ->references(['company_id', 'id'])->on('venue_reservations')->restrictOnDelete();
            $table->unique(['reservation_id', 'revision']);
            $table->index(['company_id', 'created_at']);
        });
        DB::statement("ALTER TABLE venue_reservation_events ADD CONSTRAINT reservation_action_allowed CHECK (action IN ('confirmed', 'rescheduled', 'cancelled'))");
        DB::statement('CREATE TRIGGER immutable_venue_reservation_events BEFORE UPDATE OR DELETE ON venue_reservation_events FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        // Rollback in a production venue requires an explicit data migration
        // rather than silently reclassifying or deleting booked source drafts.
        if (DB::table('booking_drafts')->where('status', 'booked')->exists()) {
            throw new RuntimeException('Cannot roll back confirmed booking source statuses without an approved data migration.');
        }
        Schema::dropIfExists('venue_reservation_events');
        Schema::dropIfExists('venue_reservations');
        DB::statement('ALTER TABLE booking_draft_events DROP CONSTRAINT booking_draft_action_allowed');
        DB::statement("ALTER TABLE booking_draft_events ADD CONSTRAINT booking_draft_action_allowed CHECK (action IN ('created', 'updated', 'submitted', 'withdrawn'))");
        DB::statement('ALTER TABLE booking_drafts DROP CONSTRAINT booking_draft_only_preconfirmation');
        DB::statement("ALTER TABLE booking_drafts ADD CONSTRAINT booking_draft_only_preconfirmation CHECK (status IN ('draft', 'under_review', 'withdrawn'))");
    }
};

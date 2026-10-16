<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Active claims only; expired/cancelled claims must be released by a
        // future audited service. One row = one occupied hall/date/program.
        Schema::create('hall_slot_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hall_id');
            $table->date('event_date');
            $table->string('program', 16);
            $table->string('status', 16);
            $table->timestampTz('expires_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->foreign(['company_id', 'hall_id'])
                ->references(['company_id', 'id'])->on('halls')->restrictOnDelete();
            $table->unique(['hall_id', 'event_date', 'program'], 'hall_slot_exclusive_claim');
            $table->unique(['company_id', 'hall_id', 'id']);
            $table->index(['company_id', 'event_date']);
        });

        DB::statement("ALTER TABLE hall_slot_claims ADD CONSTRAINT hall_claim_program_allowed CHECK (program IN ('morning', 'noon', 'evening'))");
        DB::statement(<<<'SQL'
ALTER TABLE hall_slot_claims ADD CONSTRAINT hall_claim_state_valid CHECK (
    (status = 'hold' AND expires_at IS NOT NULL)
    OR (status = 'confirmed' AND expires_at IS NULL)
)
SQL);

        // A released active claim may be removed without deleting prior events.
        // claim_id intentionally references history identity, not a live FK row.
        Schema::create('hall_slot_claim_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('claim_id');
            $table->uuid('company_id');
            $table->uuid('hall_id');
            $table->date('event_date');
            $table->string('program', 16);
            $table->string('action', 32);
            $table->jsonb('before_values');
            $table->jsonb('after_values');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'hall_id'])
                ->references(['company_id', 'id'])->on('halls')->restrictOnDelete();
            $table->index(['company_id', 'hall_id', 'claim_id']);
        });

        DB::statement("ALTER TABLE hall_slot_claim_events ADD CONSTRAINT hall_claim_event_program_allowed CHECK (program IN ('morning', 'noon', 'evening'))");
        DB::statement("ALTER TABLE hall_slot_claim_events ADD CONSTRAINT hall_claim_event_action_allowed CHECK (action IN ('hold_created', 'hold_expired', 'hold_released', 'confirmed', 'cancelled'))");
        DB::statement('CREATE TRIGGER immutable_hall_slot_claim_events BEFORE UPDATE OR DELETE ON hall_slot_claim_events FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_slot_claim_events');
        Schema::dropIfExists('hall_slot_claims');
    }
};

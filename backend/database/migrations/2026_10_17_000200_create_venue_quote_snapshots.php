<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('venue_quote_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('reservation_id');
            $table->unsignedInteger('reservation_revision');
            $table->uuid('hall_id');
            $table->unsignedInteger('program_revision');
            $table->unsignedInteger('seating_revision');
            $table->unsignedInteger('addon_revision')->default(0);
            $table->char('currency_code', 3);
            $table->jsonb('frozen_lines');
            $table->char('request_fingerprint', 64);
            $table->unsignedBigInteger('subtotal_minor');
            $table->string('status', 24)->default('internal_nonbinding');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'reservation_id'])
                ->references(['company_id', 'id'])->on('venue_reservations')->restrictOnDelete();
            $table->foreign(['company_id', 'hall_id'])
                ->references(['company_id', 'id'])->on('halls')->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'reservation_id', 'created_at']);
        });
        DB::statement("ALTER TABLE venue_quote_snapshots ADD CONSTRAINT quote_snapshot_state CHECK (status = 'internal_nonbinding' AND reservation_revision > 0 AND program_revision > 0 AND seating_revision > 0)");
        DB::statement("ALTER TABLE venue_quote_snapshots ADD CONSTRAINT quote_snapshot_currency CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE venue_quote_snapshots ADD CONSTRAINT quote_snapshot_total CHECK (subtotal_minor <= 100000000000)");
        DB::statement('CREATE TRIGGER immutable_venue_quote_snapshots BEFORE UPDATE OR DELETE ON venue_quote_snapshots FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_quote_snapshots');
    }
};

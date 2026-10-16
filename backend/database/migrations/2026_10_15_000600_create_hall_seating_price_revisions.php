<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('halls', function (Blueprint $table): void {
            // Zero means the owner has not yet approved extra-seating prices.
            $table->unsignedInteger('seating_price_revision')->default(0);
        });

        Schema::create('hall_seating_price_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hall_id');
            $table->unsignedInteger('revision_number');
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('included_chairs');
            $table->unsignedInteger('included_tables');
            $table->unsignedBigInteger('extra_chair_rate_minor');
            $table->unsignedBigInteger('extra_table_rate_minor');
            $table->char('currency_code', 3);
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['company_id', 'hall_id'])
                ->references(['company_id', 'id'])->on('halls')->restrictOnDelete();
            $table->unique(['hall_id', 'revision_number']);
            $table->index(['company_id', 'hall_id', 'revision_number']);
        });

        DB::statement(<<<'SQL'
ALTER TABLE hall_seating_price_revisions
ADD CONSTRAINT hall_seating_price_valid CHECK (
    capacity BETWEEN 1 AND 100000
    AND included_chairs <= capacity
    AND included_tables <= capacity
    AND extra_chair_rate_minor <= 100000000000
    AND extra_table_rate_minor <= 100000000000
)
SQL);
        DB::statement("ALTER TABLE hall_seating_price_revisions ADD CONSTRAINT hall_seating_currency_valid CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement('CREATE TRIGGER immutable_hall_seating_price_revisions BEFORE UPDATE OR DELETE ON hall_seating_price_revisions FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_seating_price_revisions');
        Schema::table('halls', function (Blueprint $table): void {
            $table->dropColumn('seating_price_revision');
        });
    }
};

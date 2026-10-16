<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('halls', function (Blueprint $table): void {
            $table->unsignedInteger('addon_catalog_revision')->default(0);
        });

        Schema::create('hall_addon_catalog_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hall_id');
            $table->unsignedInteger('revision_number');
            $table->char('currency_code', 3);
            $table->jsonb('items');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'hall_id'])
                ->references(['company_id', 'id'])->on('halls')->restrictOnDelete();
            $table->unique(['hall_id', 'revision_number']);
            $table->index(['company_id', 'hall_id', 'revision_number']);
        });

        DB::statement(<<<'SQL'
ALTER TABLE hall_addon_catalog_revisions ADD CONSTRAINT hall_addons_catalog_array CHECK (
    jsonb_typeof(items) = 'array' AND jsonb_array_length(items) <= 100
)
SQL);
        DB::statement("ALTER TABLE hall_addon_catalog_revisions ADD CONSTRAINT hall_addons_currency_valid CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement('CREATE TRIGGER immutable_hall_addon_catalog_revisions BEFORE UPDATE OR DELETE ON hall_addon_catalog_revisions FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_addon_catalog_revisions');
        Schema::table('halls', function (Blueprint $table): void {
            $table->dropColumn('addon_catalog_revision');
        });
    }
};

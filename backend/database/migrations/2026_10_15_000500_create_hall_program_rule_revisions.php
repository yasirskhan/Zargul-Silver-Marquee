<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('halls', function (Blueprint $table): void {
            // Zero means no approved program configuration yet.
            $table->unsignedInteger('program_rules_revision')->default(0);
        });

        Schema::create('hall_program_rule_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hall_id');
            $table->unsignedInteger('revision_number');
            $table->char('currency_code', 3);
            $table->jsonb('rules');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id', 'hall_id'])
                ->references(['company_id', 'id'])->on('halls')->restrictOnDelete();
            $table->unique(['hall_id', 'revision_number']);
            $table->index(['company_id', 'hall_id', 'revision_number']);
        });

        DB::statement(<<<'SQL'
ALTER TABLE hall_program_rule_revisions
ADD CONSTRAINT hall_program_rules_three_sessions CHECK (
    jsonb_typeof(rules) = 'object'
    AND rules ? 'morning' AND rules ? 'noon' AND rules ? 'evening'
    AND (rules - 'morning' - 'noon' - 'evening') = '{}'::jsonb
    AND jsonb_typeof(rules->'morning') = 'object'
    AND jsonb_typeof(rules->'noon') = 'object'
    AND jsonb_typeof(rules->'evening') = 'object'
)
SQL);
        DB::statement("ALTER TABLE hall_program_rule_revisions ADD CONSTRAINT hall_program_currency_code CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement('CREATE TRIGGER immutable_hall_program_rule_revisions BEFORE UPDATE OR DELETE ON hall_program_rule_revisions FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_program_rule_revisions');
        Schema::table('halls', function (Blueprint $table): void {
            $table->dropColumn('program_rules_revision');
        });
    }
};

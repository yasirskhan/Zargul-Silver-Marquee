<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hall_guest_pricing_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hall_id');
            $table->unsignedInteger('revision_number');
            $table->jsonb('tiers');
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('changed_by');
            $table->string('reason', 500);
            $table->timestamps();
            $table->unique(['company_id', 'hall_id', 'revision_number']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('restrict');
            $table->foreign('hall_id')->references('id')->on('halls')->onDelete('restrict');
        });
        DB::statement("ALTER TABLE hall_guest_pricing_revisions
            ADD CONSTRAINT hall_guest_pricing_tiers_array CHECK (jsonb_typeof(tiers) = 'array')");
        Schema::table('halls', function (Blueprint $table) {
            $table->unsignedInteger('guest_pricing_revision')->default(0);
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION hall_guest_pricing_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'approved hall guest pricing history is immutable';
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER hall_guest_pricing_no_update_delete
    BEFORE UPDATE OR DELETE ON hall_guest_pricing_revisions
    FOR EACH ROW EXECUTE FUNCTION hall_guest_pricing_immutable();
SQL);
    }

    public function down(): void
    {
        Schema::table('halls', function (Blueprint $table) {
            $table->dropColumn('guest_pricing_revision');
        });
        Schema::dropIfExists('hall_guest_pricing_revisions');
    }
};
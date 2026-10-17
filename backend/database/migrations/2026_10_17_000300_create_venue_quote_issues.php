<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('venue_quote_issues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('snapshot_id');
            $table->uuid('reservation_id');
            $table->unsignedBigInteger('number');
            $table->string('document_number', 24);
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->jsonb('frozen_lines');
            $table->string('status', 24)->default('internal_issued');
            $table->string('purpose', 500);
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('issued_at')->useCurrent();
            $table->foreign(['company_id', 'snapshot_id'])
                ->references(['company_id', 'id'])->on('venue_quote_snapshots')->restrictOnDelete();
            $table->foreign(['company_id', 'reservation_id'])
                ->references(['company_id', 'id'])->on('venue_reservations')->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'snapshot_id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'document_number']);
        });
        DB::statement("ALTER TABLE venue_quote_issues ADD CONSTRAINT quote_issue_state CHECK (status = 'internal_issued')");
        DB::statement("ALTER TABLE venue_quote_issues ADD CONSTRAINT quote_issue_amount CHECK (subtotal_minor <= 100000000000 AND number > 0)");
        DB::statement('CREATE TRIGGER immutable_venue_quote_issues BEFORE UPDATE OR DELETE ON venue_quote_issues FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_quote_issues');
    }
};

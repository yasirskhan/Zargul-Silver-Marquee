<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('venue_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('quote_issue_id');
            $table->uuid('reservation_id');
            $table->uuid('customer_id');
            $table->unsignedBigInteger('number');
            $table->string('invoice_number', 24);
            $table->unsignedInteger('tax_rate_bps');
            $table->char('currency_code', 3);
            $table->jsonb('frozen_lines');
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('tax_minor');
            $table->unsignedBigInteger('total_minor');
            $table->string('status', 16)->default('issued');
            $table->string('reason', 500);
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('issued_at')->useCurrent();
            $table->foreign(['company_id', 'quote_issue_id'])
                ->references(['company_id', 'id'])->on('venue_quote_issues')->restrictOnDelete();
            $table->foreign(['company_id', 'reservation_id'])
                ->references(['company_id', 'id'])->on('venue_reservations')->restrictOnDelete();
            $table->foreign(['company_id', 'customer_id'])
                ->references(['company_id', 'id'])->on('customer_profiles')->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'quote_issue_id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'invoice_number']);
        });
        DB::statement("ALTER TABLE venue_invoices ADD CONSTRAINT invoice_status_allowed CHECK (status = 'issued')");
        DB::statement("ALTER TABLE venue_invoices ADD CONSTRAINT invoice_amount_bounds CHECK (number > 0 AND tax_rate_bps <= 10000 AND subtotal_minor <= 100000000000 AND tax_minor <= 100000000000 AND total_minor <= 100000000000 AND total_minor = subtotal_minor + tax_minor)");
        DB::statement('CREATE TRIGGER immutable_venue_invoices BEFORE UPDATE OR DELETE ON venue_invoices FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_invoices');
    }
};

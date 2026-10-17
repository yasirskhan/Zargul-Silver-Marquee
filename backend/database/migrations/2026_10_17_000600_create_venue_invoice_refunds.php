<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Each approved full-payment refund is one immutable reversal of the
        // original balanced deposit journal, never deletion of source history.
        Schema::create('venue_invoice_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('invoice_id');
            $table->uuid('payment_id');
            $table->uuid('approval_id');
            $table->uuid('journal_id');
            $table->unsignedBigInteger('number');
            $table->string('refund_number', 24);
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('reason', 500);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id','invoice_id'])->references(['company_id','id'])
                ->on('venue_invoices')->restrictOnDelete();
            $table->foreign(['company_id','payment_id'])->references(['company_id','id'])
                ->on('venue_invoice_payments')->restrictOnDelete();
            $table->foreign(['company_id','approval_id'])->references(['company_id','id'])
                ->on('approval_requests')->restrictOnDelete();
            $table->foreign(['company_id','journal_id'])->references(['company_id','id'])
                ->on('finance_journals')->restrictOnDelete();
            $table->unique(['company_id','id']);
            $table->unique(['company_id','number']);
            $table->unique(['company_id','refund_number']);
            $table->unique(['company_id','payment_id']);
            $table->unique(['company_id','approval_id']);
            $table->unique(['company_id','journal_id']);
            $table->index(['company_id','invoice_id','created_at']);
        });
        DB::statement('ALTER TABLE venue_invoice_refunds ADD CONSTRAINT venue_refund_amount CHECK (number > 0 AND amount_minor > 0 AND amount_minor <= 100000000000)');
        DB::statement('CREATE TRIGGER immutable_venue_invoice_refunds BEFORE UPDATE OR DELETE ON venue_invoice_refunds FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }
    public function down(): void
    {
        Schema::dropIfExists('venue_invoice_refunds');
    }
};

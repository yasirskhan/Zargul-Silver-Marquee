<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Extend the EXISTING exactly-two-line, deferred-balanced finance ledger.
        DB::statement('ALTER TABLE finance_journals DROP CONSTRAINT finance_journal_type');
        DB::statement("ALTER TABLE finance_journals ADD CONSTRAINT finance_journal_type CHECK (entry_type IN ('opening_cash','reversal','customer_deposit'))");
        DB::statement('ALTER TABLE finance_journal_lines DROP CONSTRAINT finance_line_account');
        DB::statement("ALTER TABLE finance_journal_lines ADD CONSTRAINT finance_line_account CHECK (account_code IN ('cash_on_hand','opening_equity','bank_clearing','customer_deposit_liability'))");

        Schema::create('venue_invoice_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('invoice_id');
            $table->uuid('journal_id');
            $table->unsignedBigInteger('number');
            $table->string('receipt_number', 24);
            $table->string('method', 12);
            $table->string('external_reference', 120)->nullable();
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('reason', 500);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['company_id','invoice_id'])->references(['company_id','id'])
                ->on('venue_invoices')->restrictOnDelete();
            $table->foreign(['company_id','journal_id'])->references(['company_id','id'])
                ->on('finance_journals')->restrictOnDelete();
            $table->unique(['company_id','id']);
            $table->unique(['company_id','journal_id']);
            $table->unique(['company_id','number']);
            $table->unique(['company_id','receipt_number']);
            $table->unique(['company_id','method','external_reference']);
            $table->index(['company_id','invoice_id','created_at']);
        });
        DB::statement("ALTER TABLE venue_invoice_payments ADD CONSTRAINT venue_payment_method CHECK (method IN ('cash','bank'))");
        DB::statement("ALTER TABLE venue_invoice_payments ADD CONSTRAINT venue_payment_amount CHECK (number > 0 AND amount_minor > 0 AND amount_minor <= 100000000000)");
        DB::statement("ALTER TABLE venue_invoice_payments ADD CONSTRAINT venue_payment_reference CHECK ((method = 'cash') = (external_reference IS NULL))");
        DB::statement('CREATE TRIGGER immutable_venue_invoice_payments BEFORE UPDATE OR DELETE ON venue_invoice_payments FOR EACH ROW EXECUTE FUNCTION venuedesk_immutable_history()');
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_invoice_payments');
        DB::statement('ALTER TABLE finance_journal_lines DROP CONSTRAINT finance_line_account');
        DB::statement("ALTER TABLE finance_journal_lines ADD CONSTRAINT finance_line_account CHECK (account_code IN ('cash_on_hand','opening_equity'))");
        DB::statement('ALTER TABLE finance_journals DROP CONSTRAINT finance_journal_type');
        DB::statement("ALTER TABLE finance_journals ADD CONSTRAINT finance_journal_type CHECK (entry_type IN ('opening_cash','reversal'))");
    }
};

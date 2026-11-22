<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['venue_invoices' => 'issued_at', 'venue_invoice_payments' => 'created_at',
            'venue_invoice_refunds' => 'created_at', 'finance_journals' => 'created_at'] as $table => $column) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE timestamp(3) with time zone");
        }
    }

    public function down(): void
    {
        foreach (['venue_invoices' => 'issued_at', 'venue_invoice_payments' => 'created_at',
            'venue_invoice_refunds' => 'created_at', 'finance_journals' => 'created_at'] as $table => $column) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE timestamp(0) with time zone");
        }
    }
};

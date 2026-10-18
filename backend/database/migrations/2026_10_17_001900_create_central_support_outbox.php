<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No venue booking, guest, ledger or bank data is sent automatically.
        // Customer-authored messages are encrypted at rest in client database.
        Schema::create('central_support_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('installation_id');
            $table->unsignedBigInteger('created_by');
            $table->uuid('client_request_id');
            $table->char('payload_sha256',64);
            $table->text('encrypted_subject');
            $table->text('encrypted_body');
            $table->string('delivery_state',24)->default('queued');
            $table->string('central_ticket_number',64)->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('installation_id')->references('installation_id')
                ->on('central_installation_identity')->restrictOnDelete();
            $table->unique(['company_id','client_request_id']);
            $table->index(['delivery_state','created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_support_outbox');
    }
};

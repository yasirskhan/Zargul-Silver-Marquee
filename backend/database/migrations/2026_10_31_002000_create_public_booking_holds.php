<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_booking_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hall_id');
            $table->date('event_date');
            $table->string('program', 16);
            $table->unsignedInteger('guests');
            $table->string('quote_hash', 64);
            $table->jsonb('quote');
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('deposit_minor');
            $table->string('customer_name', 120);
            $table->string('customer_email', 254)->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->uuid('idempotency_key');
            $table->string('token_hash', 64)->unique();
            $table->text('token_ciphertext');
            $table->timestampTz('expires_at');
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key']);
            $table->unique(['company_id', 'hall_id', 'event_date', 'program']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('restrict');
            $table->foreign('hall_id')->references('id')->on('halls')->onDelete('restrict');
        });
        DB::statement("ALTER TABLE public_booking_holds ADD CONSTRAINT public_booking_hold_status
            CHECK (status IN ('active'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('public_booking_holds');
    }
};
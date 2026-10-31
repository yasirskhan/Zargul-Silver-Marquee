<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_booking_payment_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hold_id');
            $table->string('provider', 32);
            $table->string('reference', 120)->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency_code', 3);
            $table->string('verifier', 120);
            $table->timestampTz('verified_at');
            $table->jsonb('evidence');
            $table->timestamps();
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('restrict');
            $table->foreign('hold_id')->references('id')->on('public_booking_holds')->onDelete('restrict');
        });
        Schema::create('public_booking_confirmations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hall_id');
            $table->date('event_date');
            $table->string('program', 16);
            $table->unsignedInteger('guests');
            $table->jsonb('quote');
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('deposit_minor');
            $table->string('customer_name', 120);
            $table->string('customer_email', 254)->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->uuid('hold_id')->unique();
            $table->uuid('payment_evidence_id')->unique();
            $table->string('token_hash', 64)->unique();
            $table->text('token_ciphertext');
            $table->timestampTz('confirmed_at');
            $table->timestamps();
            $table->unique(['company_id', 'hall_id', 'event_date', 'program']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('restrict');
            $table->foreign('hall_id')->references('id')->on('halls')->onDelete('restrict');
            $table->foreign('hold_id')->references('id')->on('public_booking_holds')->onDelete('restrict');
            $table->foreign('payment_evidence_id')->references('id')->on('public_booking_payment_evidence')->onDelete('restrict');
        });
        Schema::create('public_booking_confirmation_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('confirmation_id');
            $table->string('action', 32);
            $table->jsonb('snapshot');
            $table->timestampTz('occurred_at');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('restrict');
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public_booking_evidence_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'public booking evidence is immutable';
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER public_booking_hold_events_immutable
    BEFORE UPDATE OR DELETE ON public_booking_hold_events
    FOR EACH ROW EXECUTE FUNCTION public_booking_evidence_immutable();
CREATE TRIGGER public_booking_payment_events_immutable
    BEFORE UPDATE OR DELETE ON public_booking_payment_evidence
    FOR EACH ROW EXECUTE FUNCTION public_booking_evidence_immutable();
CREATE TRIGGER public_booking_confirmation_events_immutable
    BEFORE UPDATE OR DELETE ON public_booking_confirmation_events
    FOR EACH ROW EXECUTE FUNCTION public_booking_evidence_immutable();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('public_booking_confirmation_events');
        Schema::dropIfExists('public_booking_confirmations');
        Schema::dropIfExists('public_booking_payment_evidence');
    }
};
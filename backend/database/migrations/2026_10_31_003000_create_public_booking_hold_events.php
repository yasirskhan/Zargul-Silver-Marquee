<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_booking_hold_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('hold_id');
            $table->string('action', 32);
            $table->jsonb('snapshot');
            $table->timestampTz('occurred_at');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_booking_hold_events');
    }
};
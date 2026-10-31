<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_booking_payment_evidence', function (Blueprint $table) {
            $table->dropForeign(['hold_id']);
        });
        Schema::table('public_booking_confirmations', function (Blueprint $table) {
            $table->dropForeign(['hold_id']);
        });
    }

    public function down(): void
    {
        Schema::table('public_booking_payment_evidence', function (Blueprint $table) {
            $table->foreign('hold_id')->references('id')->on('public_booking_holds')->onDelete('restrict');
        });
        Schema::table('public_booking_confirmations', function (Blueprint $table) {
            $table->foreign('hold_id')->references('id')->on('public_booking_holds')->onDelete('restrict');
        });
    }
};
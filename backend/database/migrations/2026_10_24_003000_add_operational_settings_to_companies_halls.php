<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('legal_name', 160)->nullable();
            $table->string('contact_email', 254)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('address', 240)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->string('timezone', 64)->nullable();
        });
        Schema::table('halls', function (Blueprint $table): void {
            $table->string('address', 240)->nullable();
            $table->string('phone', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('halls', function (Blueprint $table): void {
            $table->dropColumn(['address', 'phone']);
        });
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['legal_name', 'contact_email', 'contact_phone',
                'address', 'currency_code', 'timezone']);
        });
    }
};

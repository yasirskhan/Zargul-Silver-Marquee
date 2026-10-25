<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_payment_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->unique();
            $table->jsonb('config');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_payment_settings');
    }
};

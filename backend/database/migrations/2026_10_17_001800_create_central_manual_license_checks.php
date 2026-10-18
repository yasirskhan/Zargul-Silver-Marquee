<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only manual invocation journal: no license/payment secrets.
        // Serialized rate limit is enforced under advisory xact lock.
        Schema::create('central_manual_license_check_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('installation_id');
            $table->uuid('company_id');
            $table->unsignedBigInteger('requested_by');
            $table->timestampTz('requested_at');
            $table->foreign('installation_id')->references('installation_id')
                ->on('central_installation_identity')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['installation_id','requested_at'], 'central_manual_check_rate_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_manual_license_check_attempts');
    }
};

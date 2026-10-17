<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_license_check_state', function (Blueprint $table): void {
            $table->unsignedSmallInteger('singleton_id')->primary();
            $table->uuid('installation_id')->unique();
            $table->timestampTz('last_online_verified_at')->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('next_retry_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->string('last_failure_category', 32)->nullable();
            $table->timestampsTz();
            $table->foreign('installation_id')
                ->references('installation_id')->on('central_installation_identity')
                ->restrictOnDelete();
        });
        DB::statement('ALTER TABLE central_license_check_state ADD CONSTRAINT central_check_singleton CHECK (singleton_id = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('central_license_check_state');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Client-local cache only. No central billing data or signer private keys.
        Schema::create('central_signed_license_cache', function (Blueprint $table): void {
            $table->unsignedSmallInteger('singleton_id')->primary();
            $table->uuid('installation_id')->unique();
            $table->uuid('license_id');
            $table->unsignedBigInteger('revision');
            $table->char('envelope_sha256', 64);
            $table->text('encrypted_signed_envelope');
            $table->timestampTz('accepted_at');
            $table->timestampsTz();
            $table->foreign('installation_id')
                ->references('installation_id')->on('central_installation_identity')
                ->restrictOnDelete();
        });
        DB::statement(
            'ALTER TABLE central_signed_license_cache ADD CONSTRAINT central_cache_one_installation CHECK (singleton_id = 1)'
        );
        DB::statement(
            'ALTER TABLE central_signed_license_cache ADD CONSTRAINT central_cache_positive_revision CHECK (revision > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('central_signed_license_cache');
    }
};

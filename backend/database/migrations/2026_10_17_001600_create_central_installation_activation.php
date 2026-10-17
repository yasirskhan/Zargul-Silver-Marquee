<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Client-local installation/customer binding, not a paid entitlement.
        // Central staff, customer billing and signing keys remain outside client DB.
        Schema::create('central_installation_activation', function (Blueprint $table): void {
            $table->unsignedSmallInteger('singleton_id')->primary();
            $table->uuid('installation_id')->unique();
            $table->uuid('customer_id');
            $table->string('approved_domain', 253);
            $table->string('status', 32)->default('registered_pending_license');
            $table->timestampTz('central_registered_at');
            $table->timestampsTz();
            $table->foreign('installation_id')->references('installation_id')
                ->on('central_installation_identity')->restrictOnDelete();
        });
        DB::statement(
            'ALTER TABLE central_installation_activation ADD CONSTRAINT central_activation_singleton CHECK (singleton_id=1)'
        );
        DB::statement(
            "ALTER TABLE central_installation_activation ADD CONSTRAINT central_activation_pending_only CHECK (status='registered_pending_license')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('central_installation_activation');
    }
};

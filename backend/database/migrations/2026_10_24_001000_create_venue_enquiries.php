<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_enquiries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('website_id');
            $table->uuid('hall_id')->nullable();
            $table->string('customer_name', 120);
            $table->string('email', 254)->nullable();
            $table->string('phone', 32)->nullable();
            $table->date('event_date');
            $table->string('program', 16);
            $table->unsignedInteger('guests');
            $table->text('message')->nullable();
            $table->timestampTz('consent_at');
            $table->string('status', 16)->default('new');
            $table->string('source', 32)->default('public_website');
            $table->uuid('request_key')->unique();
            $table->string('ip_hash', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('website_id')->references('id')->on('websites')->cascadeOnDelete();
            $table->foreign('hall_id')->references('id')->on('halls')->nullOnDelete();
            $table->index(['company_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_enquiries');
    }
};
